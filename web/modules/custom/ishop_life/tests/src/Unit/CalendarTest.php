<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_life\Unit;

use Drupal\ishop_life\Calendar;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests how repeating events fill a date range.
 */
#[CoversClass(Calendar::class)]
final class CalendarTest extends UnitTestCase {

  /**
   * One-off, weekly, monthly (keeping its day) and yearly repeats.
   */
  public function testExpand(): void {
    $this->assertSame(['2026-10-20'], Calendar::expand('2026-10-20', 'once', NULL, '2026-10-01', '2026-10-31'));
    $this->assertSame([], Calendar::expand('2026-10-20', 'once', NULL, '2026-11-01', '2026-11-30'));
    $this->assertSame(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'], Calendar::expand('2026-09-29', 'weekly', NULL, '2026-10-01', '2026-10-31'));
    $this->assertSame(['2027-02-28', '2027-03-31'], Calendar::expand('2027-01-31', 'monthly', 31, '2027-02-01', '2027-03-31'));
    $this->assertSame(['2027-10-19'], Calendar::expand('2026-10-19', 'yearly', 19, '2027-01-01', '2027-12-31'));
  }

  /**
   * Rows sort by date, then time (all-day first), then title.
   */
  public function testSort(): void {
    $rows = Calendar::sort([
      ['date' => '2026-10-16', 'time' => '09:00', 'title' => 'B'],
      ['date' => '2026-10-15', 'time' => '13:25', 'title' => 'Game'],
      ['date' => '2026-10-15', 'time' => '', 'title' => 'Phone due'],
    ]);
    $this->assertSame(['Phone due', 'Game', 'B'], array_column($rows, 'title'));
  }

}
