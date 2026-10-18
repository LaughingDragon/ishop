<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * The selling platforms and listing statuses (Phase 1, approved 2026-10-15).
 */
final class Platforms {

  /**
   * Platforms, in the order they are shown.
   */
  public const PLATFORMS = [
    'poshmark' => 'Poshmark',
    'mercari' => 'Mercari',
    // Added 2026-10-16 (items 278, 280): Cody sells on eBay too.
    'ebay' => 'eBay',
    'depop' => 'Depop',
    'grailed' => 'Grailed',
    'vinted' => 'Vinted',
    'facebook' => 'Facebook',
  ];

  /**
   * Short names for the small pills in Stockroom rows (punch 622).
   */
  public const SHORT = [
    'poshmark' => 'P',
    'mercari' => 'M',
    'ebay' => 'eB',
    'depop' => 'D',
    'grailed' => 'G',
    'vinted' => 'V',
    'facebook' => 'FB',
  ];

  public const TO_POST = 'to_post';
  public const POSTED = 'posted';
  public const SOLD_HERE = 'sold_here';
  public const TAKE_DOWN = 'take_down';
  public const NOT_NEEDED_SOLD = 'not_needed_sold';
  public const AT_RISK = 'at_risk';
  public const NOT_ALLOWED = 'not_allowed';

  /**
   * Listing statuses.
   */
  public const STATUSES = [
    self::TO_POST => 'To post',
    self::POSTED => 'Posted',
    self::SOLD_HERE => 'Sold here',
    self::TAKE_DOWN => 'Take down',
    self::NOT_NEEDED_SOLD => 'Not needed - sold',
    self::AT_RISK => 'Posted - at risk',
    self::NOT_ALLOWED => 'Not allowed',
  ];

  /**
   * Hostnames (and suffixes) of each platform's listing and share links.
   *
   * Includes the short links the apps share (posh.mk, merc.li, ...).
   */
  public const HOSTS = [
    'poshmark' => ['poshmark.com', 'posh.mk'],
    'mercari' => ['mercari.com', 'merc.li'],
    'ebay' => ['ebay.com', 'ebay.us'],
    'depop' => ['depop.com', 'depop.app.link'],
    'grailed' => ['grailed.com', 'grailed.app.link'],
    'vinted' => ['vinted.com', 'vinted.co.uk', 'vinted.fr', 'vinted.de', 'vinted.ca'],
    'facebook' => ['facebook.com', 'fb.com', 'fb.me', 'm.me'],
  ];

  /**
   * The platform a listing link belongs to, or NULL.
   *
   * Only http(s) links; www. and m. subdomains count.
   */
  public static function fromUrl(string $url): ?string {
    $parts = parse_url(trim($url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE) || empty($parts['host'])) {
      return NULL;
    }
    $host = strtolower($parts['host']);
    foreach (self::HOSTS as $platform => $hosts) {
      foreach ($hosts as $known) {
        if ($host === $known || str_ends_with($host, '.' . $known)) {
          return $platform;
        }
      }
    }
    return NULL;
  }

  /**
   * Splits pasted text into listing links by platform.
   *
   * @return array{links: array<string, string>, unknown: string[], duplicates: string[]}
   *   One link per platform (the first one), lines that are not a known
   *   listing link, and platforms that had more than one link.
   */
  public static function parseLinks(string $text): array {
    $links = [];
    $unknown = [];
    $duplicates = [];
    foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
      $platform = self::fromUrl($candidate);
      if ($platform === NULL) {
        $unknown[] = $candidate;
      }
      elseif (isset($links[$platform])) {
        $duplicates[] = $platform;
      }
      else {
        $links[$platform] = $candidate;
      }
    }
    return ['links' => $links, 'unknown' => $unknown, 'duplicates' => array_values(array_unique($duplicates))];
  }

  /**
   * Manual eligibility overrides (empty means "use the rule", Phase 3).
   */
  public const ELIGIBILITY = [
    'yes' => 'Yes',
    'no' => 'No',
    'check' => 'Check',
  ];

}
