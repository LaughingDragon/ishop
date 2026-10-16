<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\ShipBy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests ship-by dates.
 */
#[CoversClass(ShipBy::class)]
final class ShipByTest extends UnitTestCase {

  /**
   * The estimate adds the platform's days to the sale date.
   */
  public function testEstimate(): void {
    $this->assertSame('2026-10-19', ShipBy::estimate('2026-10-16', 3));
    $this->assertSame('2026-11-02', ShipBy::estimate('2026-10-26', 7));
    $this->assertSame('2026-10-16', ShipBy::estimate('2026-10-16', -2));
  }

  /**
   * Late, today, tomorrow, later.
   */
  public function testStatus(): void {
    $this->assertSame(['key' => 'late', 'days' => -2], ShipBy::status('2026-10-14', '2026-10-16'));
    $this->assertSame(['key' => 'today', 'days' => 0], ShipBy::status('2026-10-16', '2026-10-16'));
    $this->assertSame(['key' => 'tomorrow', 'days' => 1], ShipBy::status('2026-10-17', '2026-10-16'));
    $this->assertSame(['key' => 'later', 'days' => 3], ShipBy::status('2026-10-19', '2026-10-16'));
  }

  /**
   * Plain words.
   */
  public function testWords(): void {
    $this->assertSame('1 day late', ShipBy::words('2026-10-15', '2026-10-16'));
    $this->assertSame('3 days late', ShipBy::words('2026-10-13', '2026-10-16'));
    $this->assertSame('Ship today', ShipBy::words('2026-10-16', '2026-10-16'));
    $this->assertSame('Ship by tomorrow', ShipBy::words('2026-10-17', '2026-10-16'));
    $this->assertSame('Ship by Mon Oct 19', ShipBy::words('2026-10-19', '2026-10-16'));
  }

}
