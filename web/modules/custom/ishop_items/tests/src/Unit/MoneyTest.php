<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Money;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the money figures.
 */
#[CoversClass(Money::class)]
final class MoneyTest extends UnitTestCase {

  /**
   * A sale row.
   */
  private function sale(string $platform, ?float $price, ?float $fees, ?float $shipping, ?float $cost, string $posted = '', string $sold = '2026-10-15'): array {
    return [
      'platform' => $platform,
      'price' => $price,
      'fees' => $fees,
      'shipping' => $shipping,
      'cost' => $cost,
      'date_posted' => $posted,
      'date_sold' => $sold,
    ];
  }

  /**
   * Profit is price - fees - shipping - cost; totals and platforms add up.
   */
  public function testSummarize(): void {
    $sum = Money::summarize([
      $this->sale('mercari', 30.0, 3.0, 5.0, 10.0, '2026-10-01', '2026-10-11'),
      $this->sale('poshmark', 50.0, 10.0, NULL, 20.0, '2026-09-15', '2026-10-15'),
      $this->sale('mercari', 20.0, 2.0, 0.0, NULL),
    ]);
    $this->assertSame(3, $sum['count']);
    $this->assertSame(100.0, $sum['revenue']);
    $this->assertSame(15.0, $sum['fees']);
    $this->assertSame(5.0, $sum['shipping']);
    $this->assertSame(30.0, $sum['cost']);
    $this->assertSame(50.0, $sum['profit']);
    // (10 + 30) / 2: the third sale has no Date posted.
    $this->assertSame(20.0, $sum['avg_days']);
    $this->assertSame(1, $sum['missing_cost']);
    $this->assertSame(0, $sum['missing_price']);
    // Site platform order: Poshmark before Mercari.
    $this->assertSame(['poshmark', 'mercari'], array_keys($sum['by_platform']));
    $this->assertSame(['count' => 2, 'revenue' => 50.0, 'profit' => 30.0], $sum['by_platform']['mercari']);
  }

  /**
   * No sales: zeros, no average, nothing by platform.
   */
  public function testEmpty(): void {
    $sum = Money::summarize([]);
    $this->assertSame([0, 0.0, NULL, []], [$sum['count'], $sum['profit'], $sum['avg_days'], $sum['by_platform']]);
  }

  /**
   * A sale with no price counts, adds nothing, and is flagged.
   */
  public function testMissingPrice(): void {
    $sum = Money::summarize([$this->sale('depop', NULL, 1.0, NULL, 4.0)]);
    $this->assertSame([1, 0.0, -5.0, 1], [$sum['count'], $sum['revenue'], $sum['profit'], $sum['missing_price']]);
  }

}
