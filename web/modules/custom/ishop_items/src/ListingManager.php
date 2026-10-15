<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ishop_items\Entity\PlatformListing;
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
      $item->save();
    }
    return $restore;
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
