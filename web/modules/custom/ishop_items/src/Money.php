<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The money numbers (Phase 4): sales, profit, days to sell, sitting stock.
 *
 * One calculation for the Money page, the "This season" scoreboard and the
 * tax export, so they never disagree. Real data only: a sale with no sale
 * price or no cost is counted, but flagged, never guessed.
 *
 * Profit = sale price - platform fees - shipping paid by the seller - what
 * the item cost (Liam, Phase 4 plan).
 */
final class Money {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Sales with a Date sold between two dates (inclusive, Y-m-d), oldest first.
   *
   * @return array<int, array>
   *   One row per sold listing: item, platform, date_sold, date_posted,
   *   price, fees, shipping, cost (floats or NULL).
   */
  public function sales(?string $from, ?string $to): array {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->exists('date_sold')
      ->sort('date_sold');
    if ($from) {
      $query->condition('date_sold', $from, '>=');
    }
    if ($to) {
      $query->condition('date_sold', $to, '<=');
    }
    $number = fn ($value) => $value === NULL || $value === '' ? NULL : (float) $value;
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $listing) {
      $item = $listing->getItem();
      if (!$item) {
        continue;
      }
      $rows[] = [
        'item' => $item,
        'platform' => $listing->getPlatform(),
        'date_sold' => (string) $listing->get('date_sold')->value,
        'date_posted' => (string) ($listing->get('date_posted')->value ?? ''),
        'price' => $number($listing->get('sold_price')->value),
        'fees' => $number($listing->get('fees')->value),
        'shipping' => $number($listing->get('shipping_paid')->value),
        'cost' => $number($item->get('field_cost_paid')->value),
      ];
    }
    return $rows;
  }

  /**
   * Totals and per-platform figures for a list of sales.
   *
   * Pure: works on the plain rows from sales().
   *
   * @return array{count: int, revenue: float, fees: float, shipping: float, cost: float, profit: float, avg_days: ?float, missing_price: int, missing_cost: int, by_platform: array}
   *   Money in dollars; avg_days is NULL when no sale has both dates.
   */
  public static function summarize(array $rows): array {
    $sum = ['count' => 0, 'revenue' => 0.0, 'fees' => 0.0, 'shipping' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
    $days = [];
    $missing_price = $missing_cost = 0;
    $by_platform = [];
    foreach ($rows as $row) {
      $profit = self::profit($row);
      $sum['count']++;
      $sum['revenue'] += $row['price'] ?? 0.0;
      $sum['fees'] += $row['fees'] ?? 0.0;
      $sum['shipping'] += $row['shipping'] ?? 0.0;
      $sum['cost'] += $row['cost'] ?? 0.0;
      $sum['profit'] += $profit;
      $missing_price += $row['price'] === NULL ? 1 : 0;
      $missing_cost += $row['cost'] === NULL ? 1 : 0;
      if ($row['date_posted'] !== '' && $row['date_sold'] !== '') {
        $days[] = self::daysBetween($row['date_posted'], $row['date_sold']);
      }
      $platform = $row['platform'];
      $by_platform[$platform] ??= ['count' => 0, 'revenue' => 0.0, 'profit' => 0.0];
      $by_platform[$platform]['count']++;
      $by_platform[$platform]['revenue'] += $row['price'] ?? 0.0;
      $by_platform[$platform]['profit'] += $profit;
    }
    // Platform order as everywhere else on the site.
    $ordered = array_intersect_key(array_replace(Platforms::PLATFORMS, $by_platform), $by_platform);
    return $sum + [
      'avg_days' => $days ? (float) (array_sum($days) / count($days)) : NULL,
      'missing_price' => $missing_price,
      'missing_cost' => $missing_cost,
      'by_platform' => $ordered,
    ];
  }

  /**
   * One sale's profit: price - fees - shipping - cost (missing ones as 0).
   */
  public static function profit(array $row): float {
    return round(($row['price'] ?? 0.0) - ($row['fees'] ?? 0.0) - ($row['shipping'] ?? 0.0) - ($row['cost'] ?? 0.0), 2);
  }

  /**
   * Whole days from one Y-m-d date to another.
   */
  public static function daysBetween(string $from, string $to): int {
    return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
  }

  /**
   * How many listings went live between two dates (by Date posted).
   */
  public function listedCount(?string $from, ?string $to): int {
    $query = $this->entityTypeManager->getStorage('platform_listing')->getQuery()->accessCheck(FALSE)->exists('date_posted');
    if ($from) {
      $query->condition('date_posted', $from, '>=');
    }
    if ($to) {
      $query->condition('date_posted', $to, '<=');
    }
    return (int) $query->count()->execute();
  }

  /**
   * Live listings that have sat longest, oldest posting first.
   *
   * @return array<int, array{item: \Drupal\node\NodeInterface, platform: string, date_posted: string, days: int}>
   *   Up to $limit rows.
   */
  public function longestSitting(int $limit = 5): array {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Stale::LIVE, 'IN')
      ->exists('date_posted')
      ->sort('date_posted')
      ->range(0, $limit * 3)
      ->execute();
    $today = Stale::today();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      $item = $listing->getItem();
      if (!$item || in_array((string) $item->get('field_item_status')->value, Stale::ITEM_DONE, TRUE)) {
        continue;
      }
      $posted = (string) $listing->get('date_posted')->value;
      $rows[] = [
        'item' => $item,
        'platform' => $listing->getPlatform(),
        'date_posted' => $posted,
        'days' => self::daysBetween($posted, $today),
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }
    return $rows;
  }

}
