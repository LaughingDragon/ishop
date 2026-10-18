<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

use Drupal\ishop_items\Platforms;

/**
 * What each spreadsheet column can be, and the site's first guess (Phase 10).
 */
final class Columns {

  public const IGNORE = '';

  /**
   * Targets that apply to the whole row.
   */
  public const TARGETS = [
    'title' => 'Title',
    'sku' => 'Item number / SKU (groups rows of the same item)',
    'platform' => 'Platform(s)',
    'link' => 'Listing link',
    'price' => 'Price',
    'status' => 'Status (active / sold)',
    'sold_flag' => 'Sold? (yes / no)',
    'sold_on' => 'Sold on (platform or date)',
    'quantity' => 'Quantity',
    'location' => 'Where it is kept',
    'notes' => 'Notes (private)',
    'brand' => 'Brand',
    'size' => 'Size',
    'color' => 'Color',
    'condition' => 'Condition',
    'category' => 'Category',
    'cost' => 'What you paid',
    'description' => 'Description',
    'date_listed' => 'Date listed',
    'sold_price' => 'Sold price',
    'date_sold' => 'Date sold',
  ];

  /**
   * Per-platform targets: "link:poshmark" and so on.
   */
  public const PLATFORM_TARGETS = [
    'link' => '@platform link',
    'price' => '@platform price',
    'on' => '@platform status (Posted / To post / Sold here / yes / link)',
  ];

  /**
   * Header words for each target, most specific first (lower case, words).
   */
  private const HINTS = [
    'sold_price' => ['sold price', 'sale price', 'sold for', 'price sold', 'sold amount', 'sale amount'],
    'date_sold' => ['date sold', 'sold date', 'sale date', 'date of sale'],
    'sold_on' => ['sold on', 'where sold', 'sold where', 'sold platform', 'sold via'],
    'sold_flag' => ['sold', 'is sold', 'sold yes no', 'sold y n'],
    'date_listed' => [
      'date listed',
      'listed date',
      'list date',
      'listing date',
      'date posted',
      'posted date',
      'date added',
    ],
    'cost' => ['cost', 'paid', 'cost of goods', 'cogs', 'purchase price', 'bought for', 'price paid', 'my cost'],
    'sku' => [
      'sku',
      'crosslister',
      'crosslister number',
      'crosslister id',
      'item number',
      'item no',
      'item id',
      'id',
      'inventory number',
      'inventory id',
      'stock number',
      'tag',
      'number',
    ],
    'title' => [
      'title',
      'item',
      'items',
      'item name',
      'listing',
      'name',
      'listing title',
      'product',
      'product name',
      'description title',
      'what',
    ],
    'platform' => [
      'platform',
      'platforms',
      'site',
      'sites',
      'where listed',
      'listed on',
      'marketplace',
      'marketplaces',
      'store',
      'channel',
    ],
    'link' => ['link', 'url', 'listing link', 'listing url', 'web address'],
    'price' => ['price', 'list price', 'listed price', 'listing price', 'asking price', 'asking', 'current price'],
    'status' => ['status', 'state', 'listing status', 'active'],
    'quantity' => ['quantity', 'qty', 'count', 'how many', 'units'],
    'location' => ['location', 'bin', 'shelf', 'storage', 'where', 'box', 'spot', 'storage location'],
    'notes' => ['notes', 'note', 'comments', 'comment', 'remarks', 'memo'],
    'brand' => ['brand', 'maker', 'manufacturer', 'designer'],
    'size' => ['size', 'sizes'],
    'color' => ['color', 'colour', 'colors'],
    'condition' => ['condition', 'cond'],
    'category' => ['category', 'type', 'department', 'kind'],
    'description' => ['description', 'desc', 'details'],
  ];

  /**
   * Hints too general to match inside a longer header ("Item description").
   */
  private const EXACT_ONLY = [
    'item',
    'id',
    'number',
    'tag',
    'name',
    'what',
    'where',
    'type',
    'kind',
    'box',
    'spot',
    'count',
    'sold',
    'active',
    'state',
    'site',
    'store',
    'details',
    'paid',
    'units',
  ];

  /**
   * Header words that rule out a partial match ("Shipping cost", "Fees").
   */
  private const NOT_PARTIAL = [
    'ship',
    'shipping',
    'postage',
    'fee',
    'fees',
    'tax',
    'profit',
    'net',
    'payout',
    'earnings',
  ];

  /**
   * Header words for buyer details: never imported, never stored.
   */
  private const PRIVATE_WORDS = [
    'buyer',
    'customer',
    'address',
    'ship to',
    'shipping to',
    'recipient',
    'email',
    'e mail',
    'phone',
    'zip',
    'postal',
    'street',
    'city',
    'username',
  ];

  /**
   * All targets as options for a column (grouped).
   *
   * @return array<string, string|array<string, string>>
   *   Option key => label, with a group per platform.
   */
  public static function options(): array {
    $options = [self::IGNORE => 'Ignore this column'] + self::TARGETS;
    foreach (Platforms::PLATFORMS as $key => $name) {
      foreach (self::PLATFORM_TARGETS as $kind => $label) {
        $options[$name][$kind . ':' . $key] = strtr($label, ['@platform' => $name]);
      }
    }
    return $options;
  }

  /**
   * Whether $target is something a column can be set to.
   */
  public static function isTarget(string $target): bool {
    if ($target === self::IGNORE || isset(self::TARGETS[$target])) {
      return TRUE;
    }
    [$kind, $platform] = array_pad(explode(':', $target, 2), 2, '');
    return isset(self::PLATFORM_TARGETS[$kind], Platforms::PLATFORMS[$platform]);
  }

  /**
   * Whether a header looks like buyer details (names, addresses, contact).
   */
  public static function isPrivate(string $header): bool {
    $words = ' ' . Values::titleKey($header) . ' ';
    foreach (self::PRIVATE_WORDS as $word) {
      if (str_contains($words, ' ' . $word . ' ') || str_contains($words, ' ' . $word . 's ')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A key for a header, so a remembered match survives small changes.
   */
  public static function headerKey(string $header): string {
    return Values::titleKey($header);
  }

  /**
   * The site's guess for every column.
   *
   * @param string[] $headers
   *   Column headers, by column index.
   * @param array<int, mixed[]> $samples
   *   A few data rows (cells by column index), to tell links from marks.
   * @param array<string, string> $remembered
   *   Header key => target, from the last import.
   *
   * @return array<int, string>
   *   Column index => target (IGNORE for "Ignore"). Each row target is
   *   used once; the first column that fits gets it.
   */
  public static function guess(array $headers, array $samples, array $remembered = []): array {
    $guess = [];
    $taken = [];
    // Remembered matches first, then the exact header words, then partial.
    foreach ($headers as $i => $header) {
      $key = self::headerKey((string) $header);
      if (self::isPrivate((string) $header) || $key === '') {
        $guess[$i] = self::IGNORE;
        continue;
      }
      if (isset($remembered[$key]) && self::isTarget($remembered[$key]) && !isset($taken[$remembered[$key]])) {
        $guess[$i] = $remembered[$key];
        if ($remembered[$key] !== self::IGNORE) {
          $taken[$remembered[$key]] = TRUE;
        }
      }
    }
    foreach ([TRUE, FALSE] as $exact) {
      foreach ($headers as $i => $header) {
        if (array_key_exists($i, $guess)) {
          continue;
        }
        $target = self::guessOne(self::headerKey((string) $header), array_column($samples, $i), $exact, $taken);
        if ($target !== NULL) {
          $guess[$i] = $target;
          $taken[$target] = TRUE;
        }
      }
    }
    foreach (array_keys($headers) as $i) {
      $guess[$i] ??= self::IGNORE;
    }
    ksort($guess);
    return $guess;
  }

  /**
   * The guess for one header, or NULL.
   */
  private static function guessOne(string $key, array $samples, bool $exact, array $taken): ?string {
    $values = array_values(array_filter(array_map([Values::class, 'text'], $samples), fn ($v) => $v !== ''));
    // A platform's own column: "Poshmark", "Posh link", "Mercari price".
    $words = explode(' ', $key);
    foreach ($words as $n => $word) {
      $platform = Values::PLATFORM_NAMES[$word] ?? NULL;
      if ($platform === NULL || ($word === 'marketplace' && count($words) === 1)) {
        continue;
      }
      $rest = implode(' ', array_diff_key($words, [$n => TRUE]));
      $rest = trim(str_replace(['marketplace', 'listing'], '', $rest));
      if (preg_match('/\b(link|url)\b/', $rest)) {
        $kind = 'link';
      }
      elseif (preg_match('/\b(price|asking|list)\b/', $rest)) {
        $kind = 'price';
      }
      elseif ($rest === '' || preg_match('/^(listed|posted|on|active)$/', $rest)) {
        // A bare platform column: links if the cells are links, else marks.
        $links = count(array_filter($values, [Values::class, 'isUrl']));
        $kind = $values && $links >= count($values) / 2 ? 'link' : 'on';
      }
      else {
        return NULL;
      }
      $target = $kind . ':' . $platform;
      return isset($taken[$target]) ? NULL : $target;
    }
    if (!$exact && array_intersect($words, self::NOT_PARTIAL)) {
      return NULL;
    }
    foreach (self::HINTS as $target => $hints) {
      if (isset($taken[$target])) {
        continue;
      }
      foreach ($hints as $hint) {
        $match = $exact ? $key === $hint
          : !in_array($hint, self::EXACT_ONLY, TRUE) && preg_match('/\b' . preg_quote($hint, '/') . '\b/', $key);
        if ($match) {
          return $target;
        }
      }
    }
    // No header words: a column of links is the listing link.
    if (!$exact && $values && !isset($taken['link']) && count(array_filter($values, [Values::class, 'isUrl'])) >= count($values) / 2) {
      return 'link';
    }
    return NULL;
  }

  /**
   * The label of a target, for the preview.
   */
  public static function label(string $target): string {
    if ($target === self::IGNORE) {
      return 'Ignore';
    }
    if (isset(self::TARGETS[$target])) {
      return self::TARGETS[$target];
    }
    [$kind, $platform] = explode(':', $target, 2);
    return strtr(self::PLATFORM_TARGETS[$kind], ['@platform' => Platforms::PLATFORMS[$platform]]);
  }

}
