<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The price book (Phase 7.5, items 270/274): what Cody's own sales fetched.
 *
 * Every "Sold here" listing keeps its sold price plus the item's category
 * and condition at the time of the sale (PlatformListing::preSave). Similar
 * items = same site category; when at least two of those were also in the
 * same condition, only those count. Shown before any AI estimate.
 */
final class PriceBook {

  /**
   * Same-condition sales needed before the range narrows to that condition.
   */
  public const MIN_SAME_CONDITION = 2;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Cody's sales similar to an item, or NULL when there are none.
   *
   * @return array{count: int, min: float, max: float, category: string, condition: ?string}|null
   *   The condition is set only when the range is narrowed to it.
   */
  public function similar(NodeInterface $item): ?array {
    $category = $item->get('field_category')->target_id;
    if (!$category) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->condition('sold_category', $category)
      ->condition('item', $item->id(), '<>')
      ->exists('sold_price')
      ->execute();
    $sales = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      $sales[] = [
        'price' => (float) $listing->get('sold_price')->value,
        'condition' => $listing->get('sold_condition')->target_id,
      ];
    }
    $summary = self::summarize($sales, $item->get('field_condition')->target_id);
    if (!$summary) {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $summary['category'] = (string) $terms->load($category)?->label();
    $summary['condition'] = $summary['condition'] !== NULL ? (string) $terms->load($summary['condition'])?->label() : NULL;
    return $summary;
  }

  /**
   * Count and price range, narrowed to one condition when there are enough.
   *
   * @param array<int, array{price: float, condition: int|string|null}> $sales
   *   The similar sales.
   * @param int|string|null $condition
   *   The item's condition term ID.
   *
   * @return array{count: int, min: float, max: float, condition: int|string|null}|null
   *   NULL when there are no sales.
   */
  public static function summarize(array $sales, int|string|null $condition): ?array {
    if (!$sales) {
      return NULL;
    }
    $same = $condition === NULL ? [] : array_filter($sales, fn ($sale) => (string) $sale['condition'] === (string) $condition);
    $use = count($same) >= self::MIN_SAME_CONDITION ? $same : $sales;
    $prices = array_column($use, 'price');
    return [
      'count' => count($prices),
      'min' => min($prices),
      'max' => max($prices),
      'condition' => $use === $same ? $condition : NULL,
    ];
  }

  /**
   * The sentence: "You sold 3 similar items for $8-$15 (Home & Kitchen)".
   */
  public static function sentence(array $summary): string {
    $range = $summary['min'] === $summary['max']
      ? self::money($summary['min'])
      : self::money($summary['min']) . '-' . self::money($summary['max']);
    $what = $summary['count'] === 1 ? 'similar item' : 'similar items';
    $where = $summary['condition'] !== NULL
      ? sprintf('%s, %s', $summary['category'], $summary['condition'])
      : $summary['category'];
    return sprintf('You sold %d %s for %s (%s).', $summary['count'], $what, $range, $where);
  }

  /**
   * Money without needless cents: "$8" or "$8.50".
   */
  private static function money(float $amount): string {
    return '$' . (fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2));
  }

}
