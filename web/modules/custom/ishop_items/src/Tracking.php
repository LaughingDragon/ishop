<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Tracking numbers: clean them up, guess the carrier, link to its page.
 *
 * Phase 10 A (Shipping room). Pure rules, no carrier API: the link opens the
 * carrier's own public tracking page.
 */
final class Tracking {

  /**
   * Carriers, in the order the Shipped form shows them.
   */
  public const CARRIERS = [
    'usps' => 'USPS',
    'ups' => 'UPS',
    'fedex' => 'FedEx',
    'dhl' => 'DHL',
    'other' => 'Other',
  ];

  /**
   * Each carrier's public tracking page; the number goes on the end.
   */
  private const PAGES = [
    'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
    'ups' => 'https://www.ups.com/track?tracknum=',
    'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=',
    'dhl' => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id=',
  ];

  /**
   * A typed or scanned number without spaces, dashes or the ZIP prefix.
   *
   * A scanned USPS barcode starts with 420 and the buyer's ZIP code
   * (5 or 9 digits); that part is dropped, so the ZIP is never stored.
   */
  public static function normalize(string $number): string {
    $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    if (preg_match('/^420(?:\d{9}|\d{5})(9[1-5]\d{18,20})$/', $number, $m)) {
      return $m[1];
    }
    return $number;
  }

  /**
   * The carrier a number most likely belongs to, or NULL when unsure.
   */
  public static function guess(string $number): ?string {
    $number = self::normalize($number);
    return match (TRUE) {
      (bool) preg_match('/^1Z[0-9A-Z]{16}$/', $number) => 'ups',
      (bool) preg_match('/^9[1-5]\d{18,20}$/', $number) => 'usps',
      (bool) preg_match('/^[A-Z]{2}\d{9}US$/', $number) => 'usps',
      (bool) preg_match('/^(\d{12}|\d{15})$/', $number) => 'fedex',
      (bool) preg_match('/^\d{10}$/', $number) => 'dhl',
      default => NULL,
    };
  }

  /**
   * The carrier's tracking page for a number, or NULL (Other, or no number).
   */
  public static function url(string $carrier, string $number): ?string {
    $number = self::normalize($number);
    if ($number === '' || !isset(self::PAGES[$carrier])) {
      return NULL;
    }
    return self::PAGES[$carrier] . rawurlencode($number);
  }

}
