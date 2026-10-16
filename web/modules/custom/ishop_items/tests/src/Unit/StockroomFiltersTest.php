<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\StockroomFilters;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests reading the Stockroom filters from the query string.
 */
#[CoversClass(StockroomFilters::class)]
final class StockroomFiltersTest extends UnitTestCase {

  /**
   * Empty and unknown values are dropped; the rest are cleaned.
   */
  public function testParse(): void {
    $this->assertSame([], StockroomFilters::parse([]));
    $this->assertSame([], StockroomFilters::parse([
      'q' => '  ',
      'brand' => '',
      'platform' => 'etsy',
      'status' => 'DROP TABLE',
      'min' => 'abc',
      'location' => '-3',
    ]));
    $this->assertSame([
      'q' => 'lamp',
      'brand' => 7,
      'location' => 20,
      'size' => 'M',
      'status' => 'listed',
      'platform' => 'depop',
      'min' => 5.0,
      'max' => 1200.5,
    ], StockroomFilters::parse([
      'q' => ' lamp ',
      'brand' => '7',
      'size' => 'M',
      'status' => 'listed',
      'platform' => 'depop',
      'location' => '20',
      'min' => '$5',
      'max' => '1,200.50',
    ]));
  }

}
