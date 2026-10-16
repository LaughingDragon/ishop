<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

/**
 * A listing's ID from its link (Phase 10 B), as pure rules.
 *
 * The same rule reads the stored listing link and the links in an email, so
 * an email can be matched to its listing exactly. Tracked links (encoded
 * inside a redirect) are decoded first.
 */
final class ListingRef {

  /**
   * Platform => pattern whose first group is the listing's ID.
   */
  private const PATTERNS = [
    'ebay' => '#ebay\.[a-z.]+/itm/(?:[^/\s?"]+/)?(\d{9,15})#i',
    'poshmark' => '#poshmark\.com/listing/[^\s?"/]*?-?([0-9a-f]{24})\b#i',
    'mercari' => '#mercari\.com/(?:us/)?item/(m\d{6,15})#i',
    'depop' => '#depop\.com/products/([a-z0-9][a-z0-9-]{2,120})#i',
    'grailed' => '#grailed\.com/listings/(\d{5,12})#i',
    'vinted' => '#vinted\.[a-z.]+/items/(\d{5,15})#i',
    'facebook' => '#facebook\.com/marketplace/item/(\d{8,20})#i',
  ];

  /**
   * The first listing ID for $platform found in $text, or NULL.
   */
  public static function find(string $platform, string $text): ?string {
    if (!isset(self::PATTERNS[$platform])) {
      return NULL;
    }
    // Tracked links carry the real one URL-encoded, sometimes twice.
    $decoded = $text;
    for ($i = 0; $i < 2; $i++) {
      $decoded = rawurldecode($decoded);
    }
    foreach ([$text, $decoded] as $haystack) {
      if (preg_match(self::PATTERNS[$platform], $haystack, $m)) {
        return strtolower($m[1]);
      }
    }
    return NULL;
  }

}
