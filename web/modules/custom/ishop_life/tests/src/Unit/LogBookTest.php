<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_life\Unit;

use Drupal\ishop_life\LogBook;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the mileage and expense log titles.
 */
#[CoversClass(LogBook::class)]
final class LogBookTest extends UnitTestCase {

  /**
   * Trip titles drop a trailing ".0"; expenses show dollars and cents.
   */
  public function testTitle(): void {
    $this->assertSame('2026-10-15 Post office, 6.2 mi', LogBook::title('mileage', [
      'date' => '2026-10-15',
      'trip' => 'Post office',
      'miles' => 6.2,
    ]));
    $this->assertSame('2026-10-15 Post office, 6 mi', LogBook::title('mileage', [
      'date' => '2026-10-15',
      'trip' => 'Post office',
      'miles' => 6.0,
    ]));
    $this->assertSame('2026-10-15 Tape, $4.99', LogBook::title('expense', [
      'date' => '2026-10-15',
      'what' => 'Tape',
      'amount' => 4.99,
    ]));
  }

}
