<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * The "Sold here" cascade, as pure rules.
 *
 * When an item sells on one platform, its other listings follow:
 * live ones (Posted, Posted - at risk) become "Take down", and ones not yet
 * posted (To post) become "Not needed - sold". Take down, Not allowed and
 * the like are left alone. Un-marking the sale puts back exactly what the
 * cascade changed, and nothing else.
 */
final class Cascade {

  /**
   * What each status becomes when the item sells elsewhere.
   */
  private const ON_SALE = [
    Platforms::POSTED => Platforms::TAKE_DOWN,
    Platforms::AT_RISK => Platforms::TAKE_DOWN,
    Platforms::TO_POST => Platforms::NOT_NEEDED_SOLD,
  ];

  /**
   * The changes a sale on $sold_platform causes.
   *
   * @param array<string, string> $statuses
   *   Platform => current status, for every listing of the item.
   * @param string $sold_platform
   *   The platform it sold on.
   *
   * @return array<string, array{from: string, to: string}>
   *   Platform => the change, for the other platforms that change.
   */
  public static function plan(array $statuses, string $sold_platform): array {
    $changes = [];
    foreach ($statuses as $platform => $status) {
      if ($platform !== $sold_platform && isset(self::ON_SALE[$status])) {
        $changes[$platform] = ['from' => $status, 'to' => self::ON_SALE[$status]];
      }
    }
    return $changes;
  }

  /**
   * What to put back when a sale is un-marked.
   *
   * A listing is restored only if it was changed by this cascade and still
   * has the status the cascade gave it (a later manual change wins).
   *
   * @param array<string, array{status: string, before: ?string, from_sale: bool}> $listings
   *   Platform => current status, the status before the cascade, and whether
   *   the cascade from this sale changed it.
   *
   * @return array<string, string>
   *   Platform => status to restore.
   */
  public static function restore(array $listings): array {
    $restore = [];
    foreach ($listings as $platform => $listing) {
      if (!$listing['from_sale'] || $listing['before'] === NULL) {
        continue;
      }
      if (($listing['status'] ?? NULL) === (self::ON_SALE[$listing['before']] ?? NULL)) {
        $restore[$platform] = $listing['before'];
      }
    }
    return $restore;
  }

}
