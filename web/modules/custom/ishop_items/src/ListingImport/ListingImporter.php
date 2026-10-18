<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\PhotoPrep;
use Drupal\ishop_items\Platforms;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;

/**
 * Imports Cody's listings spreadsheet and undoes an import (Phase 10).
 *
 * The plan comes from ImportPlan; this creates one item per planned item,
 * sets each platform's listing as the sheet says (platforms the sheet
 * doesn't mention stay blank, not "To post") and keeps a record of what
 * each import created so it can be undone for UNDO_DAYS days.
 */
final class ListingImporter {

  /**
   * An import can be undone for this many days.
   */
  public const UNDO_DAYS = 7;

  /**
   * Uploaded sheets waiting for an import (deleted after it, or after a day).
   */
  public const UPLOADS = 'private://listing-import/uploads';

  /**
   * The old Crosslister app's records (exported once from its database).
   */
  public const RECORDS = 'private://listing-import/crosslister-records.json';

  /**
   * The photos those records point to.
   */
  public const RECORD_PHOTOS = 'private://listing-import/crosslister-photos';

  /**
   * Where imported items are kept unless the sheet says otherwise.
   */
  public const DEFAULT_LOCATION = ['Game room', 'Table'];

  /**
   * What to do with sold rows (Cody's choice on the preview).
   */
  public const SOLD_SKIP = 'skip';
  public const SOLD_DELIVERED = 'delivered';
  public const SOLD_TO_SHIP = 'to_ship';

  private const REGISTRY = 'ishop_items.listing_imports';
  private const MEMORY = 'ishop_items.listing_import_memory';
  private const NOTES = 'ishop_items.sheet_notes';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ListingManager $listings,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly StateInterface $state,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Term ID => name for a vocabulary, in the site's order.
   *
   * Storage locations are given as "Parent > Child" paths.
   *
   * @return array<int, string>
   *   Terms.
   */
  public function terms(string $vid): array {
    $tree = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vid);
    $names = [];
    $paths = [];
    foreach ($tree as $term) {
      $names[(int) $term->tid] = $term->name;
      $parent = (int) ($term->parents[0] ?? 0);
      $paths[(int) $term->tid] = $parent && isset($paths[$parent]) ? $paths[$parent] . ' > ' . $term->name : $term->name;
    }
    return $vid === 'storage_location' ? $paths : $names;
  }

  /**
   * What ImportPlan needs to know about the site.
   *
   * @param array $value_maps
   *   Cody's category and condition matches.
   * @param bool $fill
   *   Fill in details from the old Crosslister records.
   */
  public function context(array $value_maps = [], bool $fill = FALSE): array {
    $lower = fn (array $terms) => array_flip(array_map('mb_strtolower', $terms));
    $locations = $this->terms('storage_location');
    // Both "Table A" and "Game room > Table > Table A" find the place.
    $by_name = [];
    foreach ($locations as $tid => $path) {
      $parts = explode(' > ', $path);
      $by_name[mb_strtolower(end($parts))] ??= $tid;
    }
    $default = array_search(implode(' > ', self::DEFAULT_LOCATION), $locations, TRUE);

    $urls = [];
    $rows = $this->database->select('platform_listing', 'l')->fields('l', ['item', 'url'])->isNotNull('url')->execute();
    foreach ($rows as $row) {
      if (($key = Values::urlKey((string) $row->url)) !== '') {
        $urls[$key] = (int) $row->item;
      }
    }
    $titles = [];
    $rows = $this->database->select('node_field_data', 'n')->fields('n', ['nid', 'title'])->condition('type', 'item')->orderBy('nid')->execute();
    foreach ($rows as $row) {
      $titles[Values::titleKey((string) $row->title)] ??= (int) $row->nid;
    }
    unset($titles['']);

    return [
      'urls' => $urls,
      'titles' => $titles,
      'locations' => $lower($locations) + $by_name,
      'conditions' => $lower($this->terms('condition')),
      'categories' => $lower($this->terms('category')),
      'default_location' => $default === FALSE ? NULL : (int) $default,
      'value_maps' => $value_maps,
      'records' => $this->records(),
      'fill' => $fill,
    ];
  }

  /**
   * The old Crosslister records by number ([] when the file isn't there).
   */
  public function records(): array {
    $path = $this->fileSystem->realpath(self::RECORDS);
    if (!$path || !is_readable($path)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($path), TRUE);
    return is_array($data['records'] ?? NULL) ? $data['records'] : [];
  }

  /**
   * The proposed category/condition for each distinct sheet value.
   *
   * @param string[] $values
   *   The sheet's values.
   * @param string $field
   *   Either 'category' or 'condition'.
   *
   * @return array<string, int>
   *   Lower-case value => term ID (0 = leave blank): Cody's earlier choice,
   *   else the exact name, else the best guess, else "Other".
   */
  public function proposeValues(array $values, string $field): array {
    $terms = $this->terms($field);
    $remembered = $this->memory()['values'][$field] ?? [];
    $other = array_search('Other', $terms, TRUE);
    $words = $field === 'condition' ? ImportPlan::CONDITION_WORDS : [];
    $proposed = [];
    foreach ($values as $value) {
      $key = mb_strtolower(trim($value));
      if ($key === '' || isset($proposed[$key])) {
        continue;
      }
      if (array_key_exists($key, $remembered) && ($remembered[$key] === 0 || isset($terms[$remembered[$key]]))) {
        $proposed[$key] = (int) $remembered[$key];
        continue;
      }
      $guess = ValueGuess::guess($words[$key] ?? $value, $terms);
      $proposed[$key] = $guess ?? ($field === 'category' && $other !== FALSE ? (int) $other : 0);
    }
    return $proposed;
  }

  /**
   * The tab whose headings the site recognises best.
   */
  public function bestTab(array $sheets): int {
    $best = 0;
    $best_score = -1;
    foreach ($sheets as $i => $sheet) {
      $guess = $this->guessColumns($sheet);
      $score = count(array_filter($guess)) + (in_array('title', $guess, TRUE) ? 5 : 0);
      if ($score > $best_score) {
        [$best, $best_score] = [$i, $score];
      }
    }
    return $best;
  }

  /**
   * The site's guess for a tab's columns (remembered matches first).
   */
  public function guessColumns(array $sheet): array {
    return Columns::guess($sheet['headers'], array_column(array_slice($sheet['rows'], 0, 20), 'cells'), $this->memory()['columns']);
  }

  /**
   * The proposed category and condition matches for a tab.
   *
   * @return array<string, array<string, int>>
   *   Field => lower-case value => term ID (0 = leave blank).
   */
  public function proposeValueMaps(array $sheet, array $mapping): array {
    $maps = [];
    foreach (['category', 'condition'] as $field) {
      $column = array_search($field, $mapping, TRUE);
      if ($column !== FALSE) {
        $maps[$field] = $this->proposeValues(array_map(fn ($r) => Values::text($r['cells'][$column] ?? NULL), $sheet['rows']), $field);
      }
    }
    return array_filter($maps);
  }

  /**
   * Column and value matches remembered from the last import.
   *
   * @return array{columns: array<string, string>, values: array<string, array<string, int>>}
   *   Header key => target; field => lower-case value => term ID.
   */
  public function memory(): array {
    return $this->state->get(self::MEMORY, []) + ['columns' => [], 'values' => []];
  }

  /**
   * Remembers this import's matches for next time.
   */
  public function remember(array $headers, array $mapping, array $value_maps): void {
    $memory = $this->memory();
    foreach ($mapping as $i => $target) {
      $key = Columns::headerKey((string) ($headers[$i] ?? ''));
      if ($key !== '' && !Columns::isPrivate((string) $headers[$i])) {
        $memory['columns'][$key] = $target;
      }
    }
    foreach ($value_maps as $field => $map) {
      $memory['values'][$field] = array_map('intval', $map) + ($memory['values'][$field] ?? []);
    }
    $this->state->set(self::MEMORY, $memory);
  }

  /**
   * Starts an import record.
   *
   * @return int
   *   The import ID.
   */
  public function start(string $file_name, string $sheet, array $options): int {
    $registry = $this->keyValue->get(self::REGISTRY);
    $id = (int) max([0, ...array_map('intval', array_keys($registry->getAll()))]) + 1;
    $registry->set((string) $id, [
      'id' => $id,
      'uid' => (int) $this->currentUser->id(),
      'created' => $this->time->getRequestTime(),
      'file' => mb_substr($file_name, 0, 120),
      'sheet' => mb_substr($sheet, 0, 60),
      'options' => $options,
      'nids' => [],
      'mids' => [],
      'skipped' => [],
      'finished' => NULL,
      'undone' => NULL,
    ]);
    return $id;
  }

  /**
   * One import's record, or NULL.
   */
  public function get(int $id): ?array {
    return $this->keyValue->get(self::REGISTRY)->get((string) $id);
  }

  /**
   * Every import, newest first.
   *
   * @return array<int, array>
   *   Import records.
   */
  public function all(): array {
    $all = $this->keyValue->get(self::REGISTRY)->getAll();
    krsort($all, SORT_NUMERIC);
    return array_values($all);
  }

  /**
   * Whether an import can still be undone.
   */
  public function canUndo(array $import): bool {
    return $import['undone'] === NULL && $import['nids'] && $this->time->getRequestTime() - $import['created'] < self::UNDO_DAYS * 86400;
  }

  /**
   * Marks an import finished.
   */
  public function finish(int $id): void {
    $this->update($id, fn (array $import) => ['finished' => $this->time->getRequestTime()] + $import);
  }

  /**
   * Creates one item from the plan and records it on the import.
   *
   * @param int $id
   *   The import ID.
   * @param array $planned
   *   One ImportPlan item.
   * @param string $sold
   *   SOLD_DELIVERED or SOLD_TO_SHIP for a sold item.
   *
   * @return int
   *   The new item's node ID.
   */
  public function importItem(int $id, array $planned, string $sold): int {
    $transaction = $this->database->startTransaction();
    try {
      [$item, $media] = $this->createItem($id, $planned, $sold);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    $nid = (int) $item->id();
    $this->update($id, function (array $import) use ($nid, $media) {
      $import['nids'][] = $nid;
      $import['mids'] = array_merge($import['mids'], $media);
      return $import;
    });
    return $nid;
  }

  /**
   * Creates the item, its listings and photos.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: int[]}
   *   The item and the media IDs created.
   */
  private function createItem(int $id, array $planned, string $sold): array {
    $f = $planned['fields'];
    $rows = count($planned['rows']) > 1 ? 'rows ' . implode(', ', $planned['rows']) : 'row ' . $planned['rows'][0];
    $why = sprintf('Imported from the listings spreadsheet (import %d, %s).', $id, $rows);
    $media = [];
    foreach ($planned['photos'] as $photo) {
      if ($m = $this->recordPhoto($photo)) {
        $media[] = (int) $m->id();
      }
    }
    $values = [
      'type' => 'item',
      'title' => $planned['title'],
      'uid' => $this->currentUser->id(),
      'status' => 1,
      // A sale is applied below (the item becomes Sold then).
      'field_item_status' => in_array($planned['kind'], [ImportPlan::KIND_LISTED, ImportPlan::KIND_SOLD], TRUE) ? 'listed' : 'ready',
      'field_cleared_to_sell' => 1,
      'field_storage_location' => $f['location'],
      'field_quantity' => $f['quantity'] ?? 1,
      'field_asking_price' => $f['asking_price'],
      'field_brand' => $f['brand'] !== NULL ? $this->brand($f['brand']) : NULL,
      'field_size' => $f['size'],
      'field_color' => $f['color'],
      'field_condition' => $f['condition'],
      'field_category' => $f['category'],
      'field_cost_paid' => $f['cost'],
      'field_description' => $f['description'],
      'field_flaws' => $f['flaws'],
      'field_internal_notes' => $f['notes'],
      'field_ai_price_min' => $f['ai_price_min'],
      'field_ai_price_max' => $f['ai_price_max'],
      'field_photos' => array_map(fn ($mid) => ['target_id' => $mid], $media),
    ];
    $item = $this->entityTypeManager->getStorage('node')->create(array_filter($values, fn ($v) => $v !== NULL));
    $item->setRevisionLogMessage($why);
    $item->save();

    // hook_node_insert made one "To post" listing per platform. Set the ones
    // the sheet mentions; the rest are removed (blank, no reminders).
    $existing = $this->listings->forItem($item);
    foreach ($existing as $platform => $listing) {
      $sheet = $planned['listings'][$platform] ?? NULL;
      if ($sheet === NULL) {
        $listing->delete();
        continue;
      }
      // The sale itself is applied below, with the cascade.
      $status = $sheet['status'] === Platforms::SOLD_HERE ? Platforms::POSTED : $sheet['status'];
      $listing->set('status', $status);
      $listing->set('url', $sheet['url']);
      $listing->set('listed_price', $sheet['price']);
      if ($sheet['date_posted'] !== NULL && in_array($status, [Platforms::POSTED, Platforms::AT_RISK], TRUE)) {
        $listing->set('date_posted', $sheet['date_posted']);
      }
      $this->listings->saveRevision($listing, $why);
    }

    if ($planned['kind'] === ImportPlan::KIND_SOLD) {
      $listing = $this->listings->forItem($item)[$planned['sold_on']];
      $listing->set('sold_price', $planned['sold_price']);
      if ($planned['date_sold'] !== NULL) {
        $listing->set('date_sold', $planned['date_sold']);
      }
      $this->listings->applySale($listing, $why);
      if ($sold === self::SOLD_DELIVERED) {
        $listing = $this->reload($listing);
        $date = (string) $listing->get('date_sold')->value;
        $listing->set('date_shipped', $date);
        $listing->set('date_delivered', $date);
        $listing->set('ship_by_estimated', FALSE);
        $this->listings->saveRevision($listing, 'Imported as already delivered: the sheet has no shipping dates, so the sold date is used.');
      }
    }
    // The sheet's notes are mostly things to check before selling ("measure
    // it before posting"): unsold items' notes wait in "Needs you now"
    // until Cody taps Done.
    if ($planned['kind'] !== ImportPlan::KIND_SOLD && ($planned['sheet_note'] ?? NULL) !== NULL) {
      $this->keyValue->get(self::NOTES)->set((string) $item->id(), $planned['sheet_note']);
    }
    return [$item, $media];
  }

  /**
   * Notes from the sheet still waiting for Cody, by item.
   *
   * Notes for items that are gone (undone, deleted) or finished since
   * (sold, donated, kept) are dropped.
   *
   * @return array<int, array{item: \Drupal\node\NodeInterface, note: string}>
   *   Keyed by node ID, oldest item first.
   */
  public function sheetNotes(): array {
    $store = $this->keyValue->get(self::NOTES);
    $notes = $store->getAll();
    if (!$notes) {
      return [];
    }
    $items = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($notes));
    $open = [];
    foreach ($notes as $nid => $note) {
      $item = $items[$nid] ?? NULL;
      $finished = ['sold', 'donated', 'kept'];
      if (!$item instanceof NodeInterface || in_array((string) $item->get('field_item_status')->value, $finished, TRUE)) {
        $store->delete((string) $nid);
        continue;
      }
      $open[(int) $nid] = ['item' => $item, 'note' => (string) $note];
    }
    ksort($open);
    return $open;
  }

  /**
   * Cody has dealt with an item's note from the sheet.
   */
  public function dismissNote(int $nid): void {
    $this->keyValue->get(self::NOTES)->delete((string) $nid);
    Cache::invalidateTags(['ishop_needs']);
  }

  /**
   * A fresh copy of a listing (after the cascade saved it).
   */
  private function reload(PlatformListing $listing): PlatformListing {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $storage->resetCache([$listing->id()]);
    return $storage->load($listing->id());
  }

  /**
   * The brand term with this name, created if new.
   */
  private function brand(string $name): int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', 'brand')->condition('name', $name)->range(0, 1)->execute();
    if ($ids) {
      return (int) reset($ids);
    }
    $term = $storage->create(['vid' => 'brand', 'name' => mb_substr($name, 0, 255)]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * An Item photo copied from the Crosslister photos, or NULL if missing.
   */
  private function recordPhoto(string $relative): ?Media {
    if (str_contains($relative, '..')) {
      return NULL;
    }
    $source = $this->fileSystem->realpath(self::RECORD_PHOTOS . '/' . $relative);
    if (!$source || !is_file($source)) {
      return NULL;
    }
    $directory = PhotoPrep::SCHEME_PREFIX . date('Y-m');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file = $this->fileRepository->writeData((string) file_get_contents($source), $directory . '/' . basename($relative), FileExists::Rename);
    $file->setOwnerId((int) $this->currentUser->id());
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'item_photo',
      'uid' => $this->currentUser->id(),
      'name' => $file->getFilename(),
      'field_media_image_1' => ['target_id' => $file->id()],
      'field_taken_at' => gmdate('Y-m-d\TH:i:s', filemtime($source) ?: $this->time->getRequestTime()),
      'status' => 1,
    ]);
    $media->save();
    return $media;
  }

  /**
   * Undoes an import: deletes the items (and photos) it created.
   *
   * @return int
   *   How many items were deleted.
   */
  public function undo(int $id): int {
    $import = $this->get($id);
    if (!$import || !$this->canUndo($import)) {
      return 0;
    }
    $nodes = $this->entityTypeManager->getStorage('node');
    $deleted = 0;
    foreach (array_chunk($import['nids'], 25) as $chunk) {
      $items = array_filter($nodes->loadMultiple($chunk), fn ($n) => $n instanceof NodeInterface && $n->bundle() === 'item');
      $deleted += count($items);
      $nodes->delete($items);
    }
    $media_storage = $this->entityTypeManager->getStorage('media');
    foreach ($media_storage->loadMultiple($import['mids']) as $media) {
      $file = $media->get('field_media_image_1')->entity;
      $media->delete();
      $file?->delete();
    }
    $this->keyValue->get(self::NOTES)->deleteMultiple(array_map('strval', $import['nids']));
    $this->update($id, fn (array $import) => [
      'undone' => $this->time->getRequestTime(),
      'undone_by' => (int) $this->currentUser->id(),
    ] + $import);
    return $deleted;
  }

  /**
   * How many of an import's items were changed after it.
   *
   * Photos added, marked sold..., so the undo screen can say so.
   */
  public function changedSince(array $import): int {
    if (!$import['nids']) {
      return 0;
    }
    $after = ($import['finished'] ?? $import['created']) + 60;
    $items = (int) $this->database->select('node_field_data', 'n')->condition('nid', $import['nids'], 'IN')
      ->condition('changed', $after, '>')->countQuery()->execute()->fetchField();
    $listings = (int) $this->database->select('platform_listing', 'l')->condition('item', $import['nids'], 'IN')
      ->condition('changed', $after, '>')->countQuery()->execute()->fetchField();
    return max($items, $listings ? 1 : 0);
  }

  /**
   * Saves an uploaded sheet where the import page can read it again.
   *
   * @return string
   *   The private:// URI.
   */
  public function keepUpload(string $path, string $extension): string {
    $directory = self::UPLOADS;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $directory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
    $this->fileSystem->copy($path, $uri, FileExists::Error);
    return $uri;
  }

  /**
   * Deletes a kept upload (only from the uploads folder).
   */
  public function deleteUpload(?string $uri): void {
    if ($uri && str_starts_with($uri, self::UPLOADS . '/') && file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

  /**
   * Deletes uploads older than a day (an import started and abandoned).
   */
  public function cleanUploads(): int {
    $path = $this->fileSystem->realpath(self::UPLOADS);
    $deleted = 0;
    foreach ($path ? (glob($path . '/*') ?: []) : [] as $file) {
      if (is_file($file) && filemtime($file) < $this->time->getRequestTime() - 86400) {
        @unlink($file);
        $deleted++;
      }
    }
    return $deleted;
  }

  /**
   * Changes an import's record.
   */
  private function update(int $id, callable $change): void {
    $registry = $this->keyValue->get(self::REGISTRY);
    $registry->set((string) $id, $change($registry->get((string) $id)));
  }

}
