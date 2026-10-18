<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

/**
 * Proposes a site category or condition for each value in the sheet.
 *
 * Phase 10. Cody confirms or changes each one on the matching screen.
 */
final class ValueGuess {

  /**
   * Words that mean the same thing as a word in a category name.
   */
  private const SYNONYMS = [
    'toy' => 'toys',
    'game' => 'toys',
    'games' => 'toys',
    'hobby' => 'hobbies',
    'collectible' => 'collectibles',
    'electronic' => 'electronics',
    'device' => 'electronics',
    'devices' => 'electronics',
    'tech' => 'electronics',
    'outdoor' => 'sporting',
    'outdoors' => 'sporting',
    'sport' => 'sporting',
    'sports' => 'sporting',
    'personal' => 'beauty',
    'care' => 'health',
    'cosmetics' => 'beauty',
    'jewellery' => 'jewelry',
    'accessory' => 'accessories',
    'desk' => 'office',
    'crafts' => 'craft',
    'car' => 'automotive',
    'auto' => 'automotive',
    'kitchen' => 'kitchen',
    'household' => 'home',
    'decor' => 'home',
    'christmas' => 'holiday',
    'halloween' => 'holiday',
    'clothes' => 'clothing',
    'apparel' => 'clothing',
    'men' => 'mens',
    'women' => 'womens',
    'ladies' => 'womens',
  ];

  /**
   * Words that carry no meaning for matching.
   */
  private const STOP = ['and', 'or', 'the', 'of', 'goods', 'supplies', 'other', 'misc', 'shoes', 'gear'];

  /**
   * The best term for a sheet value, or NULL when nothing fits.
   *
   * @param string $value
   *   The sheet's text ("Toy / game / collectible").
   * @param array<int, string> $terms
   *   Term ID => name, in the site's order (ties go to the first).
   */
  public static function guess(string $value, array $terms): ?int {
    $key = mb_strtolower(trim($value));
    foreach ($terms as $tid => $name) {
      if (mb_strtolower($name) === $key) {
        return (int) $tid;
      }
    }
    $words = self::words($value);
    $best = NULL;
    $best_score = 0;
    foreach ($terms as $tid => $name) {
      // More shared words wins; then the term with fewer other words
      // ("Automotive accessory": Automotive, not Jewelry & Accessories).
      $term_words = self::words($name);
      $shared = count(array_intersect($words, $term_words));
      $score = $shared ? $shared + $shared / (2 * count($term_words)) : 0;
      if ($score > $best_score) {
        $best = (int) $tid;
        $best_score = $score;
      }
    }
    return $best;
  }

  /**
   * Words for matching: lower case, no apostrophes, synonyms applied.
   *
   * @return string[]
   *   Unique words.
   */
  public static function words(string $text): array {
    $text = str_replace(["'", '’'], '', mb_strtolower($text));
    $words = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
      $word = self::SYNONYMS[$word] ?? $word;
      if (!in_array($word, self::STOP, TRUE)) {
        $words[] = $word;
      }
    }
    return array_values(array_unique($words));
  }

}
