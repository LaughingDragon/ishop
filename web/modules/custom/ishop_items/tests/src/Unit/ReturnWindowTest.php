<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ishop_items\ReturnWindow;

/**
 * Tests the default return-by date.
 *
 * @group ishop_items
 * @coversDefaultClass \Drupal\ishop_items\ReturnWindow
 */
final class ReturnWindowTest extends UnitTestCase {

  /**
   * Plain 30 days, the holiday rule, and the rule switched off.
   *
   * @covers ::compute
   */
  public function testCompute(): void {
    $d = ReturnWindow::DEFAULTS;
    $this->assertSame('2026-11-14', ReturnWindow::compute('2026-10-15', $d));
    $this->assertSame('2026-03-01', ReturnWindow::compute('2026-01-30', $d));
    // Oct 31 is outside the holiday window: plain 30 days.
    $this->assertSame('2026-11-30', ReturnWindow::compute('2026-10-31', $d));
    // Nov 1 to Dec 31 orders: until Jan 31 next year.
    $this->assertSame('2027-01-31', ReturnWindow::compute('2026-11-01', $d));
    $this->assertSame('2027-01-31', ReturnWindow::compute('2026-12-31', $d));
    // Time after the date is ignored.
    $this->assertSame('2027-01-31', ReturnWindow::compute('2026-12-31T23:00:00', $d));
    // The rule off.
    $this->assertSame('2026-12-01', ReturnWindow::compute('2026-11-01', ['holiday' => FALSE] + $d));
    // A longer setting than the holiday date wins.
    $this->assertSame('2027-03-01', ReturnWindow::compute('2026-12-31', ['days' => 60] + $d));
    // Other days.
    $this->assertSame('2026-10-29', ReturnWindow::compute('2026-10-15', ['days' => 14] + $d));
  }

}
