<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * A link to eBay's sold-listings search for an item (decision 264).
 *
 * The AI can't read eBay's sold listings (the 2026-10-16 cost test: web
 * search returns current listings only and eBay blocks fetching its sold
 * search), so Cody checks real sold prices directly: one tap opens eBay's
 * own search, filtered to sold and completed listings, in the browser.
 */
final class EbaySold {

  /**
   * Longest search text sent to eBay.
   */
  public const MAX_QUERY = 80;

  /**
   * The sold-listings search URL for a title, or NULL for a placeholder one.
   */
  public static function url(string $title): ?string {
    $query = trim((string) preg_replace('/\s+/', ' ', $title));
    if ($query === '' || str_starts_with($query, ReadyCheck::DEFAULT_TITLE_PREFIX)) {
      return NULL;
    }
    $query = mb_substr($query, 0, self::MAX_QUERY);
    return 'https://www.ebay.com/sch/i.html?' . http_build_query([
      '_nkw' => $query,
      'LH_Sold' => 1,
      'LH_Complete' => 1,
    ]);
  }

}
