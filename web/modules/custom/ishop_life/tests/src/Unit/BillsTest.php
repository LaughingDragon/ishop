<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_life\Unit;

use Drupal\ishop_life\Bills;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the bill dates and wording.
 */
#[CoversClass(Bills::class)]
final class BillsTest extends UnitTestCase {

  /**
   * Monthly keeps its day; short months use their last day.
   */
  public function testNextDueMonthly(): void {
    $this->assertSame('2026-11-15', Bills::nextDue('2026-10-15', 'monthly'));
    $this->assertSame('2027-01-15', Bills::nextDue('2026-12-15', 'monthly'));
    $this->assertSame('2027-02-28', Bills::nextDue('2027-01-31', 'monthly'));
    // Back to the 31st after February, thanks to the remembered day.
    $this->assertSame('2027-03-31', Bills::nextDue('2027-02-28', 'monthly', 31));
    $this->assertSame('2028-02-29', Bills::nextDue('2028-01-31', 'monthly', 31));
  }

  /**
   * Yearly, weekly and one-off bills.
   */
  public function testNextDueOthers(): void {
    $this->assertSame('2027-10-15', Bills::nextDue('2026-10-15', 'yearly'));
    $this->assertSame('2029-02-28', Bills::nextDue('2028-02-29', 'yearly', 29));
    $this->assertSame('2026-10-22', Bills::nextDue('2026-10-15', 'weekly'));
    $this->assertSame('2027-01-01', Bills::nextDue('2026-12-25', 'weekly'));
    $this->assertNull(Bills::nextDue('2026-10-15', 'once'));
  }

  /**
   * Wording such as "due tomorrow" or "3 days overdue", with or without $.
   */
  public function testLine(): void {
    $today = '2026-10-15';
    $this->assertSame('Phone, $45.00, due tomorrow', Bills::line('Phone', 45.0, '2026-10-16', $today));
    $this->assertSame('Phone, $45.00, due today', Bills::line('Phone', 45.0, '2026-10-15', $today));
    $this->assertSame('Rent, 3 days overdue', Bills::line('Rent', NULL, '2026-10-12', $today));
    $this->assertSame('Phone, $45.00, due Sat Oct 17', Bills::line('Phone', 45.0, '2026-10-17', $today));
  }

}
