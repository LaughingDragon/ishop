<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

/**
 * Known marketplace senders (Phase 10 B), as pure rules.
 *
 * A message counts only when its From domain belongs to a marketplace AND
 * Gmail's own Authentication-Results say DKIM or DMARC passed for that
 * domain. A forged "You sold it!" email is ignored.
 */
final class Senders {

  /**
   * Platform => sending domains (subdomains count).
   */
  public const DOMAINS = [
    'ebay' => ['ebay.com'],
    'poshmark' => ['poshmark.com'],
    'mercari' => ['mercari.com', 'mercariapp.com'],
    'depop' => ['depop.com', 'depopmail.com'],
    'grailed' => ['grailed.com'],
    'vinted' => ['vinted.com', 'vinted.co.uk', 'vinted.ca'],
    'facebook' => ['facebookmail.com'],
  ];

  /**
   * The Gmail search for the marketplace senders (no promotions or social).
   */
  public static function gmailQuery(int $days): string {
    $domains = array_merge(...array_values(self::DOMAINS));
    return sprintf('from:(%s) newer_than:%dd -category:promotions -category:social -category:forums', implode(' OR ', $domains), max(1, $days));
  }

  /**
   * The platform a From address belongs to, or NULL.
   */
  public static function platform(string $from): ?string {
    $domain = self::domain($from);
    if ($domain === NULL) {
      return NULL;
    }
    foreach (self::DOMAINS as $platform => $domains) {
      foreach ($domains as $known) {
        if ($domain === $known || str_ends_with($domain, '.' . $known)) {
          return $platform;
        }
      }
    }
    return NULL;
  }

  /**
   * The domain of an address ("Name <a@b.com>" or "a@b.com"), lower case.
   */
  public static function domain(string $from): ?string {
    if (preg_match('/@([a-z0-9.-]+\.[a-z]{2,})\s*>?\s*$/i', trim($from), $m)) {
      return strtolower($m[1]);
    }
    return NULL;
  }

  /**
   * Whether Gmail authenticated the message as coming from that platform.
   *
   * @param string[] $results
   *   The message's Authentication-Results header values.
   * @param string $platform
   *   The platform the From address claims.
   */
  public static function authentic(array $results, string $platform): bool {
    foreach ($results as $result) {
      // Only Gmail's own verdict counts (mx.google.com adds it on arrival).
      if (!preg_match('/^\s*mx\.google\.com\s*;/i', $result)) {
        continue;
      }
      $passed = [];
      if (preg_match_all('/\bdkim=pass\b[^;]*?header\.(?:i|d)=@?([a-z0-9.-]+)/i', $result, $m)) {
        $passed = array_merge($passed, $m[1]);
      }
      if (preg_match_all('/\bdmarc=pass\b[^;]*?header\.from=([a-z0-9.-]+)/i', $result, $m)) {
        $passed = array_merge($passed, $m[1]);
      }
      foreach ($passed as $domain) {
        if (self::platform('x@' . $domain) === $platform) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
