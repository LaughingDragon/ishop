<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * The site's categories, matched to each platform's own category (2026-10-16).
 *
 * After Liam's first real item (2026-10-16): "Check first" was confusing. With a
 * category set, each platform now says where the item goes there (e.g.
 * "Poshmark: Home > Holiday") or that there is no place for it ("Skip").
 * Without one, it asks for the category first.
 *
 * Eligibility (the ported rules) still runs first: its bans (electronics on
 * Depop, chemicals, clothing-only Grailed) win, its flag cautions are shown
 * under "Before posting", and a manual override still beats everything.
 *
 * Checked 2026-10-16: Poshmark's Home menu (poshmark.com/category/Home: has
 * Holiday and Office), Vinted's top-level catalog and its Home >
 * Celebrations & holidays > Holiday decor breadcrumb, Mercari's "Christmas
 * Seasonal Decor" category page. Mercari's other names and Grailed's come
 * from the Eligibility notes (checked 2026-08-17). Entries marked FALSE in
 * the third slot were not confirmed on the platform: the page says to check
 * the name in that platform's picker. EBay's names come from its live All
 * Categories page (checked 2026-10-16).
 */
final class PlatformCategories {

  /**
   * Shown when a platform has no place for the item.
   */
  public const SKIP = 'Skip';

  /**
   * Shown when the item has no category yet.
   */
  public const NEEDS_CATEGORY = 'No category yet';

  /**
   * Shown when the category is too general to match (for example "Other").
   */
  public const TOO_GENERAL = 'Category too general';

  /**
   * Site category name => platform => [path, confirmed] or NULL (no place).
   *
   * Keys are the names of the site's category terms. A child term uses its
   * own entry (Holiday & Seasonal sits under Home & Kitchen).
   */
  public const MAP = [
    "Men's Clothing" => [
      'poshmark' => ['Men', TRUE],
      'mercari' => ['Men', TRUE],
      'ebay' => ['Clothing, Shoes & Accessories > Men', TRUE],
      'depop' => ['Menswear', TRUE],
      'grailed' => ['Menswear', TRUE],
      'vinted' => ['Men', TRUE],
    ],
    "Women's Clothing" => [
      'poshmark' => ['Women', TRUE],
      'mercari' => ['Women', TRUE],
      'ebay' => ['Clothing, Shoes & Accessories > Women', TRUE],
      'depop' => ['Womenswear', TRUE],
      'grailed' => ['Womenswear', TRUE],
      'vinted' => ['Women', TRUE],
    ],
    'Shoes' => [
      'poshmark' => ['Women or Men > Shoes', FALSE],
      'mercari' => ['Women or Men > Shoes', FALSE],
      'ebay' => ['Clothing, Shoes & Accessories > Men or Women > Shoes', FALSE],
      'depop' => ['Womenswear or Menswear > Shoes', FALSE],
      'grailed' => ['Menswear or Womenswear > Footwear', TRUE],
      'vinted' => ['Women or Men > Shoes', FALSE],
    ],
    'Jewelry & Accessories' => [
      'poshmark' => ['Women or Men > Accessories / Jewelry', FALSE],
      'mercari' => ['Women or Men > Jewelry / Accessories', FALSE],
      'ebay' => ['Jewelry & Watches (or Clothing, Shoes & Accessories)', TRUE],
      'depop' => ['Womenswear or Menswear > Jewellery / Accessories', FALSE],
      'grailed' => ['Menswear or Womenswear > Accessories', TRUE],
      'vinted' => ['Women or Men > Accessories / Jewellery', FALSE],
    ],
    'Toys & Hobbies' => [
      'poshmark' => ['Kids > Toys', FALSE],
      'mercari' => ['Toys', TRUE],
      'ebay' => ['Toys & Hobbies', TRUE],
      'depop' => ['closest match', FALSE],
      'grailed' => NULL,
      'vinted' => ['Hobbies & collectibles', TRUE],
    ],
    'Collectibles' => [
      'poshmark' => NULL,
      'mercari' => ['Toys', FALSE],
      'ebay' => ['Collectibles & Art', TRUE],
      'depop' => ['closest match', FALSE],
      'grailed' => NULL,
      'vinted' => ['Hobbies & collectibles', TRUE],
    ],
    'Home & Kitchen' => [
      'poshmark' => ['Home', TRUE],
      'mercari' => ['Home', TRUE],
      'ebay' => ['Home & Garden', TRUE],
      'depop' => ['Home', FALSE],
      'grailed' => NULL,
      'vinted' => ['Home', TRUE],
    ],
    'Holiday & Seasonal' => [
      'poshmark' => ['Home > Holiday', TRUE],
      'mercari' => ['Home > Seasonal Decor', FALSE],
      'ebay' => ['Home & Garden > Holiday & Seasonal Décor', TRUE],
      'depop' => ['Home', FALSE],
      'grailed' => NULL,
      'vinted' => ['Home > Celebrations & holidays > Holiday decor', TRUE],
    ],
    'Electronics' => [
      'poshmark' => ['Electronics', TRUE],
      'mercari' => ['Electronics', TRUE],
      'ebay' => ['Electronics', TRUE],
      'depop' => NULL,
      'grailed' => NULL,
      'vinted' => ['Electronics', TRUE],
    ],
    'Sporting Goods' => [
      'poshmark' => NULL,
      'mercari' => ['Sports', TRUE],
      'ebay' => ['Sporting Goods', TRUE],
      'depop' => ['closest match', FALSE],
      'grailed' => NULL,
      'vinted' => ['Sports', TRUE],
    ],
    'Health & Beauty' => [
      'poshmark' => ['Beauty & Wellness', TRUE],
      'mercari' => ['Beauty', TRUE],
      'ebay' => ['Health & Beauty', TRUE],
      'depop' => ['closest match', FALSE],
      'grailed' => NULL,
      'vinted' => ['Women > Beauty', FALSE],
    ],
    'Office & Craft' => [
      'poshmark' => ['Home > Office', TRUE],
      'mercari' => ['Office (or Arts & Crafts)', TRUE],
      'ebay' => ['Business & Industrial (or Home & Garden)', FALSE],
      'depop' => ['closest match', FALSE],
      'grailed' => NULL,
      'vinted' => ['Home', TRUE],
    ],
    'Automotive' => [
      'poshmark' => NULL,
      'mercari' => ['Other', TRUE],
      'ebay' => ['eBay Motors > Parts & Accessories', FALSE],
      'depop' => NULL,
      'grailed' => NULL,
      'vinted' => NULL,
    ],
  ];

  /**
   * The site category for each Eligibility kind (closest-match fallback).
   */
  public const KIND_SITE = [
    'mens_wear' => "Men's Clothing",
    'womens_wear' => "Women's Clothing",
    'unisex_wear' => 'Jewelry & Accessories',
    'beauty' => 'Health & Beauty',
    'electronics' => 'Electronics',
    'toy' => 'Toys & Hobbies',
    'home' => 'Home & Kitchen',
    'office' => 'Office & Craft',
    'sporting' => 'Sporting Goods',
    'auto' => 'Automotive',
  ];

  /**
   * Words that mean the holiday category.
   */
  private const HOLIDAY_WORDS = [
    'holiday',
    'seasonal',
    'christmas',
    'halloween',
    'easter',
    'thanksgiving',
    'hanukkah',
    'valentine',
  ];

  /**
   * Kinds whose items have a clothing or shoe size.
   */
  public const SIZED_KINDS = ['mens_wear', 'womens_wear', 'kids_wear', 'unisex_wear'];

  /**
   * The closest site category path for any category text (e.g. the AI's).
   *
   * Order: the same path; then any part of it that is a site category name
   * (most specific first); then holiday words; then the Eligibility kind;
   * then "Other". Comparison ignores case, punctuation and "&" vs "and".
   *
   * @param string $text
   *   For example "Home & Garden > Holiday & Seasonal Decor > Ornaments".
   * @param string[] $paths
   *   The site's category paths ("Parent > Child").
   *
   * @return string
   *   One of $paths ("Other" if present, else the first path).
   */
  public static function closest(string $text, array $paths): string {
    $norm = fn (string $s) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', str_replace('&', ' and ', mb_strtolower($s))));
    $byPath = [];
    $byName = [];
    foreach ($paths as $path) {
      $byPath[$norm($path)] = $path;
      $parts = explode('>', $path);
      $byName[$norm(end($parts))] = $path;
    }
    if (isset($byPath[$norm($text)])) {
      return $byPath[$norm($text)];
    }
    foreach (array_reverse(explode('>', $text)) as $part) {
      if (isset($byName[$norm($part)])) {
        return $byName[$norm($part)];
      }
    }
    $words = ' ' . $norm($text) . ' ';
    foreach (self::HOLIDAY_WORDS as $word) {
      if (str_contains($words, " $word") && isset($byName[$norm('Holiday & Seasonal')])) {
        return $byName[$norm('Holiday & Seasonal')];
      }
    }
    $site = self::KIND_SITE[Eligibility::classify(['category' => $text])] ?? 'Other';
    return $byName[$norm($site)] ?? $byName['other'] ?? reset($paths);
  }

  /**
   * Whether items in this category have a clothing or shoe size.
   */
  public static function hasSize(string $path): bool {
    return in_array(Eligibility::classify(['category' => $path]), self::SIZED_KINDS, TRUE);
  }

  /**
   * The ported verdict, made concrete with the platform's own category.
   *
   * @param array $facts
   *   As for Eligibility::evaluate(); category is the site path "A > B".
   * @param string $platform
   *   A Platforms::PLATFORMS key.
   *
   * @return array
   *   Eligibility::evaluate()'s array; label and reason may be replaced, and
   *   platform_category is the platform's path (or NULL).
   */
  public static function evaluate(array $facts, string $platform): array {
    $result = Eligibility::evaluate($facts, $platform) + ['platform_category' => NULL];
    if ($result['overridden'] || $platform === 'facebook') {
      return $result;
    }
    $name = Eligibility::RULES[$platform]['display_name'];
    $category = trim((string) ($facts['category'] ?? ''));
    if ($category === '') {
      return [
        'verdict' => Eligibility::CHECK,
        'label' => self::NEEDS_CATEGORY,
        'reason' => "Set the item's category first; then this platform will say Post it or Not allowed.",
      ] + $result;
    }
    $key = self::key($category);
    if ($key === NULL) {
      // "Other", or a category added later without an entry here.
      return [
        'verdict' => Eligibility::CHECK,
        'label' => self::TOO_GENERAL,
        'reason' => "\"$category\" doesn't match a $name category. Pick a more specific category for the item, or choose $name's closest category yourself.",
      ] + $result;
    }
    $entry = self::MAP[$key][$platform] ?? NULL;
    if ($entry === NULL) {
      return [
        'verdict' => Eligibility::NO,
        'label' => self::SKIP,
        'reason' => "No category for this on $name.",
      ] + $result;
    }
    if ($result['verdict'] === Eligibility::NO) {
      // A ban from the ported rules (for example a battery on Depop).
      return $result;
    }
    [$path, $confirmed] = $entry;
    $reason = $path === 'closest match'
      ? "$name: pick the closest category in $name's picker."
      : "$name: $path" . ($confirmed ? '' : " (check the name in $name's picker)") . '.';
    if ($result['verdict'] === Eligibility::CHECK && $result['flags']) {
      // A caution about this item itself (power, chemicals, size).
      $reason .= ' Before posting: ' . $result['reason'];
    }
    return [
      'verdict' => Eligibility::YES,
      'label' => Eligibility::VERDICT_LABELS[Eligibility::YES],
      'reason' => $reason,
      'platform_category' => $path === 'closest match' ? NULL : $path,
    ] + $result;
  }

  /**
   * The MAP key for a site category path: the most specific part that has one.
   */
  public static function key(string $path): ?string {
    $parts = array_reverse(array_map('trim', explode('>', $path)));
    foreach ($parts as $part) {
      if (isset(self::MAP[$part])) {
        return $part;
      }
    }
    return NULL;
  }

}
