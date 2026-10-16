<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ishop_items\Form\ListingsGridForm;
use Drupal\node\NodeInterface;

/**
 * Bulk actions on the Stockroom list (Phase 3).
 *
 * Planned first (what each item goes from and to, or why it's skipped),
 * shown to confirm, then applied. Sold items are never changed in bulk:
 * a sale goes through "Sold here" on a platform, which runs the cascade.
 */
final class BulkActions {

  public const POSTED = 'posted';
  public const PRICE = 'price';
  public const LOCATION = 'location';
  public const STATUS = 'status';

  /**
   * The actions, in the order offered.
   */
  public const ACTIONS = [
    self::POSTED => 'Mark posted on a platform',
    self::PRICE => 'Change the asking price',
    self::LOCATION => 'Move to a storage location',
    self::STATUS => 'Set the status',
  ];

  /**
   * Statuses offered in bulk (Liam's list).
   *
   * Kept and Donated finish the item: its live listings become
   * "Take down".
   */
  public const STATUSES = [
    'listed' => 'Listed',
    'kept' => 'Kept',
    'donated' => 'Donated',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingManager $listings,
  ) {}

  /**
   * The new asking price: set to an amount, or lowered by a percentage.
   *
   * Lowering rounds to the cent and never goes below $0.01; "set" takes the
   * amount as given. NULL when there is no current price to lower.
   */
  public static function newPrice(?float $current, string $mode, float $amount): ?float {
    if ($mode === 'set') {
      return round($amount, 2);
    }
    if ($current === NULL || $current <= 0) {
      return NULL;
    }
    return max(0.01, round($current * (1 - $amount / 100), 2));
  }

  /**
   * A storage location as its path, e.g. "Game room > Table".
   */
  public function locationPath(?int $tid): string {
    if (!$tid) {
      return '';
    }
    $parents = $this->entityTypeManager->getStorage('taxonomy_term')->loadAllParents($tid);
    return implode(' > ', array_map(fn ($term) => $term->label(), array_reverse($parents)));
  }

  /**
   * Every storage location, as tid => path, in tree order.
   */
  public function locationOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('storage_location') as $term) {
      $options[(int) $term->tid] = $this->locationPath((int) $term->tid);
    }
    return $options;
  }

  /**
   * What the action would do to each item.
   *
   * @param \Drupal\node\NodeInterface[] $items
   *   The selected items.
   * @param string $action
   *   One of ACTIONS.
   * @param array $params
   *   Keys platform, mode + amount, location, or status.
   *
   * @return array<int, array{item: \Drupal\node\NodeInterface, before: string, after: string, skip: ?string}>
   *   One row per item, in the given order.
   */
  public function plan(array $items, string $action, array $params): array {
    $rows = [];
    foreach ($items as $item) {
      $rows[] = ['item' => $item] + $this->planOne($item, $action, $params);
    }
    return $rows;
  }

  /**
   * Before, after and skip reason for one item.
   */
  private function planOne(NodeInterface $item, string $action, array $params): array {
    $status = (string) $item->get('field_item_status')->value;
    $status_label = fn (string $s) => $item->getFieldDefinition('field_item_status')->getSetting('allowed_values')[$s] ?? $s;
    if (!$item->access('update')) {
      return ['before' => '', 'after' => '', 'skip' => 'you can\'t edit this item'];
    }
    if ($status === 'sold') {
      return ['before' => 'Sold', 'after' => '', 'skip' => 'sold items are not changed in bulk'];
    }
    switch ($action) {
      case self::POSTED:
        $platform = $params['platform'];
        $listing = $this->listings->forItem($item)[$platform] ?? NULL;
        $now = $listing ? Platforms::STATUSES[$listing->getStatus()] : 'No listing';
        $name = Platforms::PLATFORMS[$platform];
        if ($listing && in_array($listing->getStatus(), [Platforms::POSTED, Platforms::AT_RISK], TRUE)) {
          return ['before' => "$name: $now", 'after' => '', 'skip' => "already posted on $name"];
        }
        if ($listing && !in_array($listing->getStatus(), [Platforms::TO_POST, Platforms::TAKE_DOWN], TRUE)) {
          return ['before' => "$name: $now", 'after' => '', 'skip' => "$name says \"$now\""];
        }
        $verdict = PlatformCategories::evaluate(ListingsGridForm::eligibilityFacts($item) + [
          'override' => $listing?->get('eligibility_override')->value,
          'override_note' => $listing?->get('eligibility_note')->value,
        ], $platform)['verdict'];
        if ($verdict === Eligibility::NO) {
          $skip = "$name doesn't allow this item (see its listings)";
          return ['before' => "$name: $now", 'after' => '', 'skip' => $skip];
        }
        return ['before' => "$name: $now", 'after' => "$name: Posted", 'skip' => NULL];

      case self::PRICE:
        $raw = $item->get('field_asking_price')->value;
        $current = $raw === NULL || $raw === '' ? NULL : (float) $raw;
        $new = self::newPrice($current, $params['mode'], (float) $params['amount']);
        $before = $current === NULL ? 'No price' : '$' . number_format($current, 2);
        if ($new === NULL) {
          return ['before' => $before, 'after' => '', 'skip' => 'no asking price to lower'];
        }
        if ($current !== NULL && abs($new - $current) < 0.005) {
          return ['before' => $before, 'after' => '', 'skip' => 'already that price'];
        }
        return ['before' => $before, 'after' => '$' . number_format($new, 2), 'skip' => NULL];

      case self::LOCATION:
        $tid = (int) $item->get('field_storage_location')->target_id;
        $before = $this->locationPath($tid) ?: 'No location';
        if ($tid === (int) $params['location']) {
          return ['before' => $before, 'after' => '', 'skip' => 'already there'];
        }
        return ['before' => $before, 'after' => $this->locationPath((int) $params['location']), 'skip' => NULL];

      case self::STATUS:
        $before = $status_label($status);
        if ($status === $params['status']) {
          return ['before' => $before, 'after' => '', 'skip' => 'already ' . $before];
        }
        $after = self::STATUSES[$params['status']];
        if ($params['status'] !== 'listed') {
          $live = array_filter($this->listings->forItem($item), fn ($l) => in_array($l->getStatus(), Stale::LIVE, TRUE));
          if ($live) {
            $after .= ' (take down from ' . implode(', ', array_map(fn ($p) => Platforms::PLATFORMS[$p], array_keys($live))) . ')';
          }
        }
        return ['before' => $before, 'after' => $after, 'skip' => NULL];
    }
    return ['before' => '', 'after' => '', 'skip' => 'unknown action'];
  }

  /**
   * Applies the action to every item the plan doesn't skip.
   *
   * @return array
   *   The plan rows that were applied (skipped ones left out).
   */
  public function apply(array $items, string $action, array $params): array {
    $done = [];
    foreach ($this->plan($items, $action, $params) as $row) {
      if ($row['skip'] !== NULL) {
        continue;
      }
      $item = $row['item'];
      $why = 'Bulk action in the Stockroom: ' . self::ACTIONS[$action] . '.';
      switch ($action) {
        case self::POSTED:
          $listing = $this->listings->forItem($item)[$params['platform']] ?? NULL;
          if (!$listing) {
            $this->listings->createDefaults($item);
            $listing = $this->listings->forItem($item)[$params['platform']];
          }
          // Date posted is filled in on save (PlatformListing::preSave).
          $listing->set('status', Platforms::POSTED);
          $this->listings->saveRevision($listing, $why);
          if (in_array((string) $item->get('field_item_status')->value, ['draft', 'ready'], TRUE)) {
            $this->saveItem($item->set('field_item_status', 'listed'), $why);
          }
          break;

        case self::PRICE:
          $raw = $item->get('field_asking_price')->value;
          $new = self::newPrice($raw === NULL || $raw === '' ? NULL : (float) $raw, $params['mode'], (float) $params['amount']);
          $this->saveItem($item->set('field_asking_price', $new), $why);
          break;

        case self::LOCATION:
          $this->saveItem($item->set('field_storage_location', (int) $params['location']), $why);
          break;

        case self::STATUS:
          if ($params['status'] === 'listed') {
            $this->saveItem($item->set('field_item_status', 'listed'), $why);
          }
          else {
            $this->listings->finishItem($item, $params['status'], $why);
          }
          break;
      }
      $done[] = $row;
    }
    return $done;
  }

  /**
   * Saves an item as a new revision with a log message.
   */
  private function saveItem(NodeInterface $item, string $why): void {
    $item->setNewRevision(TRUE);
    $item->setRevisionUserId((int) \Drupal::currentUser()->id());
    $item->setRevisionLogMessage($why);
    // Programmatic saves keep the old revision time unless it's set.
    $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $item->save();
  }

}
