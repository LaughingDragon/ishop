<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Bulk photo drop: split a pile of photos into items by time taken.
 *
 * As in the old crosslister's import_inbox.py: sort by when each photo was
 * taken and start a new item wherever there's a gap of $gap minutes or more
 * (45 by default). The page does the same in JavaScript for its live
 * preview; this is the version the tests pin down.
 */
final class BulkGrouping {

  public const DEFAULT_GAP_MINUTES = 45;

  /**
   * Splits photos into groups.
   *
   * @param array<int|string, int> $taken
   *   Photo ID => Unix time taken.
   * @param int $gap
   *   Minutes of silence that separate one item from the next.
   *
   * @return array<int, array<int|string>>
   *   Groups of photo IDs, each in time order, groups in time order.
   */
  public static function split(array $taken, int $gap = self::DEFAULT_GAP_MINUTES): array {
    asort($taken);
    $groups = [];
    $last = NULL;
    foreach ($taken as $id => $time) {
      if ($last === NULL || $time - $last >= $gap * 60) {
        $groups[] = [];
      }
      $groups[count($groups) - 1][] = $id;
      $last = $time;
    }
    return $groups;
  }

}
