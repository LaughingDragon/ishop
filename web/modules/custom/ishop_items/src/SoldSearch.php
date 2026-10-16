<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Check sold prices: the links for an item (Phase 7.5, item 270).
 *
 * The server can't read sold listings (eBay and Mercari block it, and the AI
 * search only finds current listings), so Cody checks them directly: each
 * link opens a site's own search for the item, filtered to sold, in the
 * phone's browser. Nothing is fetched or stored here; Terapeak opens in
 * Cody's own signed-in Seller Hub (no eBay login or cookie on the server,
 * ever).
 *
 * Link formats, checked 2026-10-16: Poshmark's availability=sold_out
 * verified from the server; eBay and Mercari block server requests, and
 * Terapeak needs a signed-in seller, so those are checked on Cody's phone.
 */
final class SoldSearch {

  /**
   * Longest search text sent to a site.
   */
  public const MAX_QUERY = 80;

  /**
   * The search text for a title, or NULL for an empty or placeholder one.
   */
  public static function query(string $title): ?string {
    $query = trim((string) preg_replace('/\s+/', ' ', $title));
    if ($query === '' || str_starts_with($query, ReadyCheck::DEFAULT_TITLE_PREFIX)) {
      return NULL;
    }
    return mb_substr($query, 0, self::MAX_QUERY);
  }

  /**
   * The links, best first: [key => ['label' => ..., 'url' => ...]].
   *
   * @param string $title
   *   The item's title (what the AI or Cody identified it as).
   * @param bool $terapeak
   *   Whether Cody uses Seller Hub (then Terapeak research comes first).
   */
  public static function links(string $title, bool $terapeak): array {
    $query = self::query($title);
    if ($query === NULL) {
      return [];
    }
    $links = [];
    if ($terapeak) {
      $links['terapeak'] = [
        'label' => 'eBay Research (Seller Hub)',
        'url' => 'https://www.ebay.com/sh/research?' . http_build_query([
          'marketplace' => 'EBAY-US',
          'keywords' => $query,
          'dayRange' => 90,
          'tabName' => 'SOLD',
        ]),
      ];
    }
    $links['ebay'] = [
      'label' => 'eBay sold',
      'url' => 'https://www.ebay.com/sch/i.html?' . http_build_query([
        '_nkw' => $query,
        'LH_Sold' => 1,
        'LH_Complete' => 1,
      ]),
    ];
    $links['mercari'] = [
      'label' => 'Mercari sold',
      'url' => 'https://www.mercari.com/search/?' . http_build_query([
        'keyword' => $query,
        'itemStatuses' => 2,
      ]),
    ];
    $links['poshmark'] = [
      'label' => 'Poshmark sold',
      'url' => 'https://poshmark.com/search?' . http_build_query([
        'query' => $query,
        'availability' => 'sold_out',
      ]),
    ];
    return $links;
  }

}
