<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The money numbers (Phase 4): sales, profit, days to sell, sitting stock.
 *
 * One calculation for the Money page, the "This season" scoreboard and the
 * tax export, so they never disagree. Real data only: a sale with no sale
 * price is counted, but flagged, never guessed.
 *
 * Earned after fees = sale price - platform fees - shipping paid by the
 * seller. That is the main number (redesign 2026-10-16: Cody sells
 * household things already in the house, so what was paid is often
 * unknown). Profit = earned - what was paid, only for sales where "What I
 * paid" was entered; a blank is fine and never flagged.
 */
final class Money {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Sales with a Date sold between two dates (inclusive, Y-m-d), oldest first.
   *
   * Sales from before the site (date unknown) are only in All time (no
   * $from), unless $undated says otherwise (the tax export wants them).
   *
   * @return array<int, array>
   *   One row per sold listing: item, platform, date_sold, date_unknown,
   *   date_posted, price, fees, shipping, cost (floats or NULL), stage
   *   (Shipping::STAGES).
   */
  public function sales(?string $from, ?string $to, ?bool $undated = NULL): array {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->exists('date_sold')
      ->sort('date_sold');
    if (!($undated ?? $from === NULL)) {
      $query->condition('date_sold_unknown', 0);
    }
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
        'date_unknown' => (bool) $listing->get('date_sold_unknown')->value,
        'date_posted' => (string) ($listing->get('date_posted')->value ?? ''),
        'price' => $number($listing->get('sold_price')->value),
        'fees' => $number($listing->get('fees')->value),
        'shipping' => $number($listing->get('shipping_paid')->value),
        'cost' => $number($item->get('field_cost_paid')->value),
        // Phase 10 A: where the sale is in the Shipping room.
        'stage' => Shipping::stage($listing->get('date_shipped')->value, $listing->get('date_delivered')->value),
      ];
    }
    return $rows;
  }

  /**
   * Totals and per-platform figures for a list of sales.
   *
   * Pure: works on the plain rows from sales().
   *
   * @return array{count: int, revenue: float, fees: float, shipping: float, earned: float, cost: float, profit: float, with_cost: int, avg_days: ?float, missing_price: int, undated: int, by_platform: array, stages: array}
   *   Money in dollars. cost and profit cover only the with_cost sales that
   *   have "What I paid"; avg_days is NULL when no sale has both dates
   *   (sales from before the site never count there). undated: how many
   *   are from before the site.
   *   stages: Shipping stage => count and earned (rows without a stage
   *   count as delivered).
   */
  public static function summarize(array $rows): array {
    $sum = [
      'count' => 0,
      'revenue' => 0.0,
      'fees' => 0.0,
      'shipping' => 0.0,
      'earned' => 0.0,
      'cost' => 0.0,
      'profit' => 0.0,
      'with_cost' => 0,
    ];
    $days = [];
    $missing_price = 0;
    $undated = 0;
    $by_platform = [];
    $stages = array_fill_keys(array_keys(Shipping::STAGES), ['count' => 0, 'earned' => 0.0]);
    foreach ($rows as $row) {
      $earned = self::earned($row);
      $profit = self::profit($row);
      $sum['count']++;
      $sum['revenue'] += $row['price'] ?? 0.0;
      $sum['fees'] += $row['fees'] ?? 0.0;
      $sum['shipping'] += $row['shipping'] ?? 0.0;
      $sum['earned'] += $earned;
      if ($profit !== NULL) {
        $sum['cost'] += $row['cost'];
        $sum['profit'] += $profit;
        $sum['with_cost']++;
      }
      $missing_price += $row['price'] === NULL ? 1 : 0;
      $undated += empty($row['date_unknown']) ? 0 : 1;
      // Imported sales can have the import day as Date posted, after they
      // sold: such spans (and sales from before the site) aren't counted.
      if ($row['date_posted'] !== '' && $row['date_sold'] !== '' && empty($row['date_unknown']) && $row['date_posted'] <= $row['date_sold']) {
        $days[] = self::daysBetween($row['date_posted'], $row['date_sold']);
      }
      $platform = $row['platform'];
      $by_platform[$platform] ??= ['count' => 0, 'revenue' => 0.0, 'earned' => 0.0];
      $by_platform[$platform]['count']++;
      $by_platform[$platform]['revenue'] += $row['price'] ?? 0.0;
      $by_platform[$platform]['earned'] += $earned;
      $stage = $row['stage'] ?? Shipping::DELIVERED;
      $stages[$stage]['count']++;
      $stages[$stage]['earned'] += $earned;
    }
    // Platform order as everywhere else on the site.
    $ordered = array_intersect_key(array_replace(Platforms::PLATFORMS, $by_platform), $by_platform);
    return $sum + [
      'avg_days' => $days ? (float) (array_sum($days) / count($days)) : NULL,
      'missing_price' => $missing_price,
      'undated' => $undated,
      'by_platform' => $ordered,
      'stages' => $stages,
    ];
  }

  /**
   * One sale's earnings after fees: price - fees - shipping (missing as 0).
   */
  public static function earned(array $row): float {
    return round(($row['price'] ?? 0.0) - ($row['fees'] ?? 0.0) - ($row['shipping'] ?? 0.0), 2);
  }

  /**
   * One sale's profit: earned - what was paid; NULL when that is blank.
   */
  public static function profit(array $row): ?float {
    return $row['cost'] === NULL ? NULL : round(self::earned($row) - $row['cost'], 2);
  }

  /**
   * Whole days from one Y-m-d date to another.
   */
  public static function daysBetween(string $from, string $to): int {
    return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
  }

  /**
   * How many sales are from before the site (date unknown).
   */
  public function undatedCount(): int {
    return (int) $this->entityTypeManager->getStorage('platform_listing')->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->condition('date_sold_unknown', 1)
      ->count()->execute();
  }

  /**
   * How many items have a listing that went live between two dates.
   */
  public function listedItemsCount(?string $from, ?string $to): int {
    $query = $this->listedQuery($from, $to);
    $query->addExpression('COUNT(DISTINCT l.item)');
    return (int) $query->execute()->fetchField();
  }

  /**
   * How many listings went live between two dates (by Date posted).
   */
  public function listedCount(?string $from, ?string $to): int {
    $query = $this->listedQuery($from, $to);
    $query->addExpression('COUNT(*)');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Listings posted between two dates, without items sold before the site.
   *
   * An imported item sold before the site has the import day as Date posted
   * on its listings; it was listed and sold before then, so it isn't counted.
   */
  private function listedQuery(?string $from, ?string $to): SelectInterface {
    $before_site = $this->database->select('platform_listing', 'u')
      ->fields('u', ['item'])
      ->condition('u.date_sold_unknown', 1);
    $query = $this->database->select('platform_listing', 'l')
      ->isNotNull('l.date_posted')
      ->condition('l.item', $before_site, 'NOT IN');
    if ($from) {
      $query->condition('l.date_posted', $from, '>=');
    }
    if ($to) {
      $query->condition('l.date_posted', $to, '<=');
    }
    return $query;
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
