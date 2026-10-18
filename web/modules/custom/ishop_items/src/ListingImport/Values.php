<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

use Drupal\ishop_items\Platforms;

/**
 * Reads the values people type into a listings spreadsheet (Phase 10).
 *
 * Everything here is forgiving: "$1,234.50", "10/5/26", "Posh + Merc".
 * Anything it can't read comes back NULL, never guessed.
 */
final class Values {

  /**
   * What people call each platform in a sheet (lower case).
   */
  public const PLATFORM_NAMES = [
    'poshmark' => 'poshmark',
    'posh' => 'poshmark',
    'pm' => 'poshmark',
    'mercari' => 'mercari',
    'merc' => 'mercari',
    'ebay' => 'ebay',
    'e-bay' => 'ebay',
    'e bay' => 'ebay',
    'depop' => 'depop',
    'grailed' => 'grailed',
    'vinted' => 'vinted',
    'facebook' => 'facebook',
    'facebook marketplace' => 'facebook',
    'fb' => 'facebook',
    'fbm' => 'facebook',
    'fb marketplace' => 'facebook',
    'marketplace' => 'facebook',
  ];

  public const STATUS_LISTED = 'listed';
  public const STATUS_SOLD = 'sold';
  public const STATUS_ENDED = 'ended';

  /**
   * Status words (lower case) and what they mean.
   */
  public const STATUS_WORDS = [
    'active' => self::STATUS_LISTED,
    'listed' => self::STATUS_LISTED,
    'live' => self::STATUS_LISTED,
    'available' => self::STATUS_LISTED,
    'for sale' => self::STATUS_LISTED,
    'posted' => self::STATUS_LISTED,
    'up' => self::STATUS_LISTED,
    'yes' => self::STATUS_LISTED,
    'sold' => self::STATUS_SOLD,
    'sold out' => self::STATUS_SOLD,
    'shipped' => self::STATUS_SOLD,
    'delivered' => self::STATUS_SOLD,
    'completed' => self::STATUS_SOLD,
    'complete' => self::STATUS_SOLD,
    'paid' => self::STATUS_SOLD,
    'ended' => self::STATUS_ENDED,
    'inactive' => self::STATUS_ENDED,
    'unlisted' => self::STATUS_ENDED,
    'delisted' => self::STATUS_ENDED,
    'removed' => self::STATUS_ENDED,
    'deleted' => self::STATUS_ENDED,
    'draft' => self::STATUS_ENDED,
    'not listed' => self::STATUS_ENDED,
    'expired' => self::STATUS_ENDED,
    'donated' => self::STATUS_ENDED,
    'kept' => self::STATUS_ENDED,
  ];

  /**
   * Marks that mean "listed there" in a per-platform column.
   */
  public const YES = ['yes', 'y', 'x', '✓', '✔', '✅', 'true', '1', 'listed', 'active', 'live', 'posted', 'up'];

  /**
   * A cell as trimmed text.
   */
  public static function text(mixed $value): string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    if (is_bool($value)) {
      return $value ? 'TRUE' : 'FALSE';
    }
    if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
      return (string) (int) $value;
    }
    return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
  }

  /**
   * A price: "$1,234.50", "12", 12.5. NULL when empty or not a price.
   */
  public static function money(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
      return $value >= 0 ? round((float) $value, 2) : NULL;
    }
    $text = str_replace([',', ' '], '', self::text($value));
    $text = preg_replace('/^(US)?\$|^USD|USD$|\$$/i', '', $text) ?? '';
    if ($text === '' || !preg_match('/^\d+(\.\d+)?$/', $text)) {
      return NULL;
    }
    return round((float) $text, 2);
  }

  /**
   * A whole number of at least 1, or NULL.
   */
  public static function quantity(mixed $value): ?int {
    $text = self::text($value);
    if (!preg_match('/^\d+(\.0+)?$/', $text) || (int) $text < 1) {
      return NULL;
    }
    return (int) $text;
  }

  /**
   * A date as Y-m-d.
   *
   * Reads a spreadsheet date, 2026-10-05, 10/5/2026, 10/5/26,
   * "Oct 5, 2026", or an Excel day number. NULL when it can't be read.
   */
  public static function date(mixed $value): ?string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    // Excel stores dates as days since 1899-12-30 (if the cell lost its
    // format).
    if ((is_int($value) || is_float($value)) && $value > 20000 && $value < 80000) {
      return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
    }
    $text = self::text($value);
    if ($text === '') {
      return NULL;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
      [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
    elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $text, $m)) {
      // US order: month first.
      [$mo, $d, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
      $y += $y < 100 ? 2000 : 0;
    }
    elseif (preg_match('/^[A-Za-z]{3,9}\.? \d{1,2},? \d{4}$/', $text)) {
      $date = \DateTimeImmutable::createFromFormat('!M j Y', preg_replace('/^([A-Za-z]{3})[a-z]*\.? (\d{1,2}),? (\d{4})$/', '$1 $2 $3', $text) ?? '');
      return $date ? $date->format('Y-m-d') : NULL;
    }
    elseif (preg_match('/^2\d{4}(\.\d+)?$/', $text)) {
      return self::date((float) $text);
    }
    else {
      return NULL;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : NULL;
  }

  /**
   * Platforms named in a cell: "Poshmark", "Posh, Merc", "eBay / FB".
   *
   * @return array{platforms: string[], unknown: string[]}
   *   Platform keys in the order named, and the names not recognised.
   */
  public static function platforms(mixed $value): array {
    $text = mb_strtolower(self::text($value));
    $platforms = [];
    $unknown = [];
    if ($text === '') {
      return ['platforms' => [], 'unknown' => []];
    }
    // "All" means every platform it's listed on: not knowable from a sheet.
    foreach (preg_split('/\s*(?:[,;\/&+|]|\band\b|\n)\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $name) {
      $name = trim($name, " .-\t");
      if ($name === '') {
        continue;
      }
      $key = self::PLATFORM_NAMES[$name] ?? self::PLATFORM_NAMES[str_replace([' ', '-', '.com'], '', $name)] ?? NULL;
      if ($key === NULL && ($from_url = Platforms::fromUrl($name))) {
        $key = $from_url;
      }
      if ($key === NULL) {
        $unknown[] = $name;
      }
      elseif (!in_array($key, $platforms, TRUE)) {
        $platforms[] = $key;
      }
    }
    return ['platforms' => $platforms, 'unknown' => $unknown];
  }

  /**
   * A status cell: STATUS_LISTED, STATUS_SOLD, STATUS_ENDED, '' or NULL.
   *
   * '' for an empty cell, NULL for a word it doesn't know.
   */
  public static function status(mixed $value): ?string {
    $text = mb_strtolower(self::text($value));
    if ($text === '') {
      return '';
    }
    if (isset(self::STATUS_WORDS[$text])) {
      return self::STATUS_WORDS[$text];
    }
    // "Sold 10/5", "SOLD - shipped", "Sold on Posh".
    if (preg_match('/^(sold|shipped|delivered)\b/', $text)) {
      return self::STATUS_SOLD;
    }
    if (preg_match('/^(active|listed|live|available)\b/', $text)) {
      return self::STATUS_LISTED;
    }
    return NULL;
  }

  /**
   * The site's own listing status words (lower case).
   *
   * As Cody's sheet uses them, plus the obvious variants.
   */
  public const LISTING_WORDS = [
    'posted' => Platforms::POSTED,
    'to post' => Platforms::TO_POST,
    'todo' => Platforms::TO_POST,
    'to do' => Platforms::TO_POST,
    'not yet' => Platforms::TO_POST,
    'sold here' => Platforms::SOLD_HERE,
    'sold' => Platforms::SOLD_HERE,
    'take down' => Platforms::TAKE_DOWN,
    'not needed - sold' => Platforms::NOT_NEEDED_SOLD,
    'not needed sold' => Platforms::NOT_NEEDED_SOLD,
    'not needed' => Platforms::NOT_NEEDED_SOLD,
    'posted - at risk' => Platforms::AT_RISK,
    'at risk' => Platforms::AT_RISK,
    'not allowed' => Platforms::NOT_ALLOWED,
  ];

  /**
   * Means "not on this platform": the listing stays blank.
   */
  public const BLANK = ['', 'no', 'n', 'false', '0', '-', 'n/a', 'na', 'none'];

  /**
   * What a per-platform status cell says.
   *
   * @return string|null
   *   A Platforms status key; NULL for blank or "no"; '?' for text it
   *   doesn't know. A mark (yes, x), a link or a price means Posted.
   */
  public static function listingStatus(mixed $value): ?string {
    $text = mb_strtolower(self::text($value));
    if (in_array($text, self::BLANK, TRUE)) {
      return NULL;
    }
    $words = trim(preg_replace('/\s+/', ' ', str_replace(['–', '—'], '-', $text)) ?? '');
    if (isset(self::LISTING_WORDS[$words])) {
      return self::LISTING_WORDS[$words];
    }
    if (in_array($text, self::YES, TRUE) || self::isUrl($text) || self::money($value) !== NULL || self::status($value) === self::STATUS_LISTED) {
      return Platforms::POSTED;
    }
    if (self::status($value) === self::STATUS_SOLD) {
      return Platforms::SOLD_HERE;
    }
    return '?';
  }

  /**
   * Whether a yes/no cell says yes.
   */
  public static function isYes(mixed $value): bool {
    return in_array(mb_strtolower(self::text($value)), ['yes', 'y', 'x', '✓', '✔', '✅', 'true', '1', 'sold'], TRUE);
  }

  /**
   * Whether the text is an http(s) link.
   */
  public static function isUrl(string $text): bool {
    return (bool) preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', trim($text));
  }

  /**
   * A link in a form that can be compared: host without www/m, no query.
   */
  public static function urlKey(string $url): string {
    $parts = parse_url(trim($url));
    if (!$parts || empty($parts['host'])) {
      return '';
    }
    $host = preg_replace('/^(www|m)\./', '', strtolower($parts['host']));
    return $host . rtrim($parts['path'] ?? '', '/');
  }

  /**
   * A title in a form that can be compared: lower case, words only.
   */
  public static function titleKey(string $title): string {
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '');
  }

}
