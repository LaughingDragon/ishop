<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\PriceBook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the price book's range and sentence.
 */
#[CoversClass(PriceBook::class)]
final class PriceBookTest extends TestCase {

  /**
   * No sales: nothing to show.
   */
  public function testNoSales(): void {
    $this->assertNull(PriceBook::summarize([], 5));
  }

  /**
   * Fewer than two in the same condition: the whole category counts.
   */
  public function testWholeCategory(): void {
    $sales = [
      ['price' => 8.0, 'condition' => 5],
      ['price' => 15.0, 'condition' => 6],
      ['price' => 12.5, 'condition' => NULL],
    ];
    $this->assertSame(['count' => 3, 'min' => 8.0, 'max' => 15.0, 'condition' => NULL], PriceBook::summarize($sales, 5));
  }

  /**
   * Two or more in the same condition: only those count.
   */
  public function testSameCondition(): void {
    $sales = [
      ['price' => 8.0, 'condition' => '5'],
      ['price' => 15.0, 'condition' => 6],
      ['price' => 10.0, 'condition' => 5],
    ];
    $this->assertSame(['count' => 2, 'min' => 8.0, 'max' => 10.0, 'condition' => 5], PriceBook::summarize($sales, 5));
  }

  /**
   * The sentence for one sale, and for a range in one condition.
   */
  public function testSentence(): void {
    $one = [
      'count' => 1,
      'min' => 12.5,
      'max' => 12.5,
      'category' => 'Home & Kitchen',
      'condition' => NULL,
    ];
    $this->assertSame('You sold 1 similar item for $12.50 (Home & Kitchen).', PriceBook::sentence($one));
    $range = ['count' => 2, 'min' => 8.0, 'max' => 10.0, 'condition' => 'Good'] + $one;
    $this->assertSame('You sold 2 similar items for $8-$10 (Home & Kitchen, Good).', PriceBook::sentence($range));
  }

}
