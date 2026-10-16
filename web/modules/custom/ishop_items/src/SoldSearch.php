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
   * Most words sent to a site.
   *
   * EBay (and Mercari, Poshmark) only show listings containing EVERY word,
   * so a whole 80-character title found nothing (Liam, 2026-10-16: "ebay
   * search shows no results"). A few key words find similar sold items.
   */
  public const MAX_WORDS = 5;

  /**
   * Words that narrow a search without describing the item.
   */
  public const FILLER = [
    'a', 'an', 'and', 'the', 'of', 'for', 'with', 'in', 'on', 'by', 'to', '&', '-', '+', '|', '/',
    'new', 'sealed', 'nwt', 'nib', 'nwot', 'bnib', 'used', 'unused', 'preowned', 'pre-owned', 'vintage',
    'set', 'lot', 'piece', 'pieces', 'pc', 'pcs', 'pack', 'size', 'authentic', 'genuine', 'original',
  ];

  /**
   * The search text for a title, or NULL for an empty or placeholder one.
   *
   * Filler words and bare counts ("Set of 3") are dropped and at most
   * MAX_WORDS whole words kept, in
   * order (brand and item type usually come first in a listing title).
   */
  public static function query(string $title): ?string {
    $title = trim((string) preg_replace('/\s+/', ' ', $title));
    if ($title === '' || str_starts_with($title, ReadyCheck::DEFAULT_TITLE_PREFIX)) {
      return NULL;
    }
    $words = [];
    foreach (explode(' ', $title) as $word) {
      $bare = trim($word, " \t,.;:!?()[]\"'");
      if ($bare === '' || in_array(mb_strtolower($bare), self::FILLER, TRUE) || preg_match('/^(\d{1,2}|\d+(of|x)\d*)$/i', $bare)) {
        continue;
      }
      $words[] = $bare;
      if (count($words) >= self::MAX_WORDS) {
        break;
      }
    }
    return $words ? implode(' ', $words) : NULL;
  }

  /**
   * The search parameter each link uses (the page lets Cody edit the words).
   */
  public const PARAMS = [
    'terapeak' => 'keywords',
    'ebay' => '_nkw',
    'mercari' => 'keyword',
    'poshmark' => 'query',
  ];

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
