<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Form\ListingsGridForm;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\node\NodeInterface;

/**
 * Creates an item's listings and applies or undoes the "Sold here" cascade.
 */
final class ListingManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * An item's listings, keyed by platform, in platform order.
   *
   * @return array<string, \Drupal\ishop_items\Entity\PlatformListing>
   *   The listings that exist.
   */
  public function forItem(NodeInterface $item): array {
    if ($item->isNew()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('item', $item->id())->execute();
    $by_platform = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      $by_platform[$listing->getPlatform()] = $listing;
    }
    return array_intersect_key(array_replace(Platforms::PLATFORMS, $by_platform), $by_platform);
  }

  /**
   * The items still to post on a platform, oldest first.
   *
   * Items with a "To post" listing there that the eligibility rules (or a
   * manual override) don't rule out, and that the current user can view.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Keyed by node ID.
   */
  public function toPostQueue(string $platform): array {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('platform', $platform)
      ->condition('status', Platforms::TO_POST)
      ->execute();
    $queue = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      $item = $listing->getItem();
      // Finished items (sold, donated, kept) are not posted anywhere.
      if (!$item || !$item->access('view') || in_array((string) $item->get('field_item_status')->value, Stale::ITEM_DONE, TRUE)) {
        continue;
      }
      // Not checked yet: nothing is posted until it's cleared (decision 265).
      if (!Cleared::isCleared($item)) {
        continue;
      }
      $verdict = PlatformCategories::evaluate(ListingsGridForm::eligibilityFacts($item) + [
        'override' => $listing->get('eligibility_override')->value,
        'override_note' => $listing->get('eligibility_note')->value,
      ], $platform)['verdict'];
      if ($verdict !== Eligibility::NO) {
        $queue[(int) $item->id()] = $item;
      }
    }
    ksort($queue);
    return $queue;
  }

  /**
   * The stale-listing nudge for one listing, or NULL.
   *
   * Only live listings with a "Date posted", on items not finished.
   */
  public function nudge(PlatformListing $listing): ?array {
    $item = $listing->getItem();
    $posted = (string) $listing->get('date_posted')->value;
    if (!$item || $posted === '' || !in_array($listing->getStatus(), Stale::LIVE, TRUE)
      || in_array((string) $item->get('field_item_status')->value, Stale::ITEM_DONE, TRUE)) {
      return NULL;
    }
    $number = fn ($value) => $value === NULL || $value === '' ? NULL : (float) $value;
    $listed = $number($listing->get('listed_price')->value);
    $asking = $number($item->get('field_asking_price')->value);
    return Stale::nudge($posted, Stale::today(), $asking ?? $listed, $listed);
  }

  /**
   * Of the given listing IDs, the ones that exist and are still live.
   *
   * @return int[]
   *   Listing IDs.
   */
  public function liveListingIds(array $ids): array {
    if (!$ids) {
      return [];
    }
    return array_map('intval', array_values($this->entityTypeManager->getStorage('platform_listing')->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $ids, 'IN')
      ->condition('status', Stale::LIVE, 'IN')
      ->execute()));
  }

  /**
   * Finishes an item without a sale: Donated or Kept.
   *
   * Its live listings become "Take down" (so "Needs you now" asks for them
   * to come down); unposted ones stay as they are, and the to-post queues
   * leave finished items out.
   *
   * @return string[]
   *   Platform names set to "Take down".
   */
  public function finishItem(NodeInterface $item, string $status, string $why): array {
    $taken_down = [];
    foreach ($this->forItem($item) as $platform => $listing) {
      if (in_array($listing->getStatus(), Stale::LIVE, TRUE)) {
        $listing->set('status', Platforms::TAKE_DOWN);
        $this->saveRevision($listing, $why);
        $taken_down[] = Platforms::PLATFORMS[$platform];
      }
    }
    $item->set('field_item_status', $status);
    $item->setNewRevision(TRUE);
    $item->setRevisionUserId($this->currentUser->id());
    $item->setRevisionLogMessage($why);
    $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $item->save();
    return $taken_down;
  }

  /**
   * Every stale listing the current user can see, longest-waiting first.
   *
   * @return array<int, array{listing: \Drupal\ishop_items\Entity\PlatformListing, item: \Drupal\node\NodeInterface, nudge: array}>
   *   One row per listing.
   */
  public function staleListings(): array {
    $cutoff = (new \DateTimeImmutable(Stale::today()))->modify('-' . Stale::FIRST_DAYS . ' days')->format('Y-m-d');
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Stale::LIVE, 'IN')
      ->condition('date_posted', $cutoff, '<=')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      $item = $listing->getItem();
      if ($item && $item->access('view') && ($nudge = $this->nudge($listing))) {
        $rows[] = ['listing' => $listing, 'item' => $item, 'nudge' => $nudge];
      }
    }
    usort($rows, fn ($a, $b) => [$b['nudge']['days'], $a['item']->id()] <=> [$a['nudge']['days'], $b['item']->id()]);
    return $rows;
  }

  /**
   * Creates the missing "To post" listings for an item (one per platform).
   *
   * @return int
   *   How many were created.
   */
  public function createDefaults(NodeInterface $item): int {
    $existing = $this->forItem($item);
    $created = 0;
    foreach (array_keys(Platforms::PLATFORMS) as $platform) {
      if (isset($existing[$platform])) {
        continue;
      }
      $listing = PlatformListing::create([
        'item' => $item->id(),
        'platform' => $platform,
        'status' => Platforms::TO_POST,
      ]);
      $listing->setRevisionLogMessage('Created with the item.');
      $listing->setRevisionUserId($this->currentUser->id());
      $listing->save();
      $created++;
    }
    return $created;
  }

  /**
   * Deletes an item's listings (when the item is deleted).
   */
  public function deleteForItem(NodeInterface $item): void {
    $listings = $this->forItem($item);
    if ($listings) {
      $this->entityTypeManager->getStorage('platform_listing')->delete($listings);
    }
  }

  /**
   * The changes marking $sold "Sold here" would make to the other listings.
   *
   * @return array<string, array{from: string, to: string}>
   *   Platform => change.
   */
  public function planSale(PlatformListing $sold): array {
    $statuses = array_map(fn (PlatformListing $l) => $l->getStatus(), $this->forItem($sold->getItem()));
    return Cascade::plan($statuses, $sold->getPlatform());
  }

  /**
   * Marks a listing "Sold here" and applies the cascade.
   *
   * The item's status becomes Sold; what it was before is kept on the sold
   * listing so un-marking can restore it.
   */
  public function applySale(PlatformListing $sold, string $why): void {
    $item = $sold->getItem();
    $listings = $this->forItem($item);
    foreach ($this->planSale($sold) as $platform => $change) {
      $other = $listings[$platform];
      $other->set('status', $change['to']);
      $other->set('cascade_sale', $sold->id());
      $other->set('status_before_cascade', $change['from']);
      $this->saveRevision($other, sprintf('Sold on %s: %s.', Platforms::PLATFORMS[$sold->getPlatform()], Platforms::STATUSES[$change['to']]));
    }
    $sold->set('status', Platforms::SOLD_HERE);
    $sold->set('item_status_before_sale', (string) $item->get('field_item_status')->value);
    $this->saveRevision($sold, $why);
    if ($item->get('field_item_status')->value !== 'sold') {
      $item->set('field_item_status', 'sold');
      $item->setNewRevision(TRUE);
      $item->setRevisionLogMessage(sprintf('Sold on %s.', Platforms::PLATFORMS[$sold->getPlatform()]));
      $item->setRevisionUserId($this->currentUser->id());
      $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $item->save();
    }
  }

  /**
   * Un-marks a sale: the listing gets $new_status, the cascade is undone.
   *
   * Only listings the cascade changed, and nobody changed since, go back.
   *
   * @return array<string, string>
   *   Platform => restored status.
   */
  public function undoSale(PlatformListing $sold, string $new_status, string $why): array {
    $item = $sold->getItem();
    $rows = [];
    $listings = $this->forItem($item);
    foreach ($listings as $platform => $listing) {
      $rows[$platform] = [
        'status' => $listing->getStatus(),
        'before' => $listing->get('status_before_cascade')->value,
        'from_sale' => (string) $listing->get('cascade_sale')->target_id === (string) $sold->id(),
      ];
    }
    $restore = Cascade::restore($rows);
    foreach ($listings as $platform => $listing) {
      if (!$rows[$platform]['from_sale']) {
        continue;
      }
      if (isset($restore[$platform])) {
        $listing->set('status', $restore[$platform]);
      }
      $listing->set('cascade_sale', NULL);
      $listing->set('status_before_cascade', NULL);
      $this->saveRevision($listing, sprintf('Sale on %s un-marked: restored.', Platforms::PLATFORMS[$sold->getPlatform()]));
    }
    $item_before = (string) $sold->get('item_status_before_sale')->value;
    $sold->set('status', $new_status);
    $sold->set('item_status_before_sale', NULL);
    $this->saveRevision($sold, $why);
    if ($item->get('field_item_status')->value === 'sold' && $item_before !== '') {
      $item->set('field_item_status', $item_before);
      $item->setNewRevision(TRUE);
      $item->setRevisionLogMessage(sprintf('Sale on %s un-marked.', Platforms::PLATFORMS[$sold->getPlatform()]));
      $item->setRevisionUserId($this->currentUser->id());
      $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $item->save();
    }
    return $restore;
  }

  /**
   * Revision log of a markPosted() save; undoPosted() looks for it.
   */
  public const POSTED_LOG = 'Posted with "I posted it" on the Post page.';

  /**
   * "I posted it on <Platform>": Posted, at the asking price, posted today.
   *
   * The listing link is optional. Undo with undoPosted().
   */
  public function markPosted(PlatformListing $listing, ?string $url = NULL): void {
    $item = $listing->getItem();
    $asking = $item->get('field_asking_price')->value;
    $listing->set('status', Platforms::POSTED);
    if ($asking !== NULL && $asking !== '') {
      $listing->set('listed_price', $asking);
    }
    $listing->set('date_posted', \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d'));
    if ($url !== NULL && trim($url) !== '') {
      $listing->set('url', trim($url));
    }
    $listing->set('not_allowed_auto', FALSE);
    $this->saveRevision($listing, self::POSTED_LOG);
    $this->syncItemStatus($item, sprintf('Posted on %s.', Platforms::PLATFORMS[$listing->getPlatform()]));
  }

  /**
   * Whether the listing's last change was "I posted it" (so Undo can apply).
   */
  public function canUndoPosted(PlatformListing $listing): bool {
    return $listing->getStatus() === Platforms::POSTED && $listing->getRevisionLogMessage() === self::POSTED_LOG;
  }

  /**
   * Undoes "I posted it": status, price, date and link go back as they were.
   *
   * @return bool
   *   FALSE when the last change wasn't "I posted it" (nothing undone).
   */
  public function undoPosted(PlatformListing $listing): bool {
    if (!$this->canUndoPosted($listing)) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $previous = $storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('id', $listing->id())
      ->condition('revision_id', $listing->getRevisionId(), '<')
      ->sort('revision_id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$previous) {
      return FALSE;
    }
    $before = $storage->loadRevision(key($previous));
    foreach (['status', 'listed_price', 'date_posted', 'url', 'not_allowed_auto'] as $field) {
      $listing->set($field, $before->get($field)->getValue());
    }
    $this->saveRevision($listing, 'Undo: "I posted it" taken back.');
    $this->syncItemStatus($listing->getItem(), sprintf('Undo: not posted on %s after all.', Platforms::PLATFORMS[$listing->getPlatform()]));
    return TRUE;
  }

  /**
   * The item's status follows its listings (Liam, 2026-10-21).
   *
   * Draft or Ready becomes Listed when any listing is live; Listed goes back
   * to Ready (Draft if the checklist isn't complete) when none is. Sold,
   * donated and kept items are left alone.
   */
  public function syncItemStatus(NodeInterface $item, string $why): void {
    $status = (string) $item->get('field_item_status')->value;
    $live = array_filter($this->forItem($item), fn (PlatformListing $l) => in_array($l->getStatus(), Stale::LIVE, TRUE));
    $new = $status;
    if ($live && in_array($status, ['draft', 'ready'], TRUE)) {
      $new = 'listed';
    }
    elseif (!$live && $status === 'listed') {
      $new = ReadyCheck::missing(ItemHooks::checkValues($item)) ? 'draft' : 'ready';
    }
    if ($new === $status) {
      return;
    }
    $item->set('field_item_status', $new);
    $item->setNewRevision(TRUE);
    $item->setRevisionLogMessage($why);
    $item->setRevisionUserId($this->currentUser->id());
    $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $item->save();
  }

  /**
   * Platforms that don't fit the item are set to Not allowed automatically.
   *
   * A "To post" listing the rules rule out (Skip, Not allowed) becomes Not
   * allowed, flagged as set by the site; if the item changes so the platform
   * fits again, a flagged one goes back to To post. Listings set by hand are
   * never touched.
   *
   * @return string[]
   *   Platform names that changed.
   */
  public function applyEligibility(NodeInterface $item): array {
    $facts = ListingsGridForm::eligibilityFacts($item);
    $changed = [];
    foreach ($this->forItem($item) as $platform => $listing) {
      $verdict = $this->verdict($facts, $listing);
      if ($verdict === Eligibility::NO && $listing->getStatus() === Platforms::TO_POST) {
        $listing->set('status', Platforms::NOT_ALLOWED);
        $listing->set('not_allowed_auto', TRUE);
        $this->saveRevision($listing, sprintf("Not allowed automatically: this item doesn't fit %s.", Platforms::PLATFORMS[$platform]));
        $changed[] = Platforms::PLATFORMS[$platform];
      }
      elseif ($verdict !== Eligibility::NO && $listing->get('not_allowed_auto')->value && $listing->getStatus() === Platforms::NOT_ALLOWED) {
        $listing->set('status', Platforms::TO_POST);
        $listing->set('not_allowed_auto', FALSE);
        $this->saveRevision($listing, sprintf('Back to To post: the item now fits %s.', Platforms::PLATFORMS[$platform]));
        $changed[] = Platforms::PLATFORMS[$platform];
      }
    }
    return $changed;
  }

  /**
   * The first platform still to post after $after (wrapping round), or NULL.
   *
   * Platforms the rules rule out are skipped.
   */
  public function nextToPost(NodeInterface $item, ?string $after = NULL): ?string {
    $facts = ListingsGridForm::eligibilityFacts($item);
    $listings = $this->forItem($item);
    $order = array_keys($listings);
    if ($after !== NULL && ($at = array_search($after, $order, TRUE)) !== FALSE) {
      $order = array_merge(array_slice($order, $at + 1), array_slice($order, 0, $at));
    }
    foreach ($order as $platform) {
      $listing = $listings[$platform];
      if ($listing->getStatus() === Platforms::TO_POST && $this->verdict($facts, $listing) !== Eligibility::NO) {
        return $platform;
      }
    }
    return NULL;
  }

  /**
   * The eligibility verdict for a listing, with its manual override if any.
   */
  public function verdict(array $facts, PlatformListing $listing): string {
    return PlatformCategories::evaluate($facts + [
      'override' => $listing->get('eligibility_override')->value,
      'override_note' => $listing->get('eligibility_note')->value,
    ], $listing->getPlatform())['verdict'];
  }

  /**
   * Saves a listing as a new revision with a log message.
   */
  public function saveRevision(PlatformListing $listing, string $message): void {
    $listing->setNewRevision(TRUE);
    $listing->setRevisionLogMessage($message);
    $listing->setRevisionUserId($this->currentUser->id());
    $listing->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $listing->save();
  }

}
