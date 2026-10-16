<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\TaxExport;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the year-end tax export rows.
 */
#[CoversClass(TaxExport::class)]
final class TaxExportTest extends UnitTestCase {

  /**
   * A complete sale: plain numbers, earned, profit, nothing to check.
   */
  public function testSaleRow(): void {
    $row = TaxExport::saleRow('2026-10-15', 'Lamp, brass', 'Mercari', [
      'price' => 30.0,
      'fees' => 3.0,
      'shipping' => 5.0,
      'cost' => 10.0,
    ]);
    $this->assertSame(TaxExport::SALES_COLUMNS, array_keys($row));
    $this->assertSame(['30.00', '3.00', '5.00', '22.00', '10.00', '12.00', ''], [
      $row['Sale price'], $row['Platform fees'], $row['Shipping paid'], $row['Earned after fees'], $row['What I paid'], $row['Profit'], $row['Check'],
    ]);
  }

  /**
   * A missing sale price is named in Check; a blank "What I paid" is fine.
   */
  public function testMissingFlagged(): void {
    $none = ['price' => NULL, 'fees' => NULL, 'shipping' => NULL, 'cost' => NULL];
    $row = TaxExport::saleRow('2026-10-15', 'Vase', 'Poshmark', $none);
    $this->assertSame('', $row['Sale price']);
    $this->assertSame('', $row['What I paid']);
    $this->assertSame('', $row['Profit']);
    $this->assertSame('no sale price entered', $row['Check']);

    $no_cost = TaxExport::saleRow('2026-10-15', 'Vase', 'Poshmark', [
      'price' => 20.0,
      'fees' => 2.0,
      'shipping' => NULL,
      'cost' => NULL,
    ]);
    $this->assertSame(['18.00', '', '', ''], [
      $no_cost['Earned after fees'], $no_cost['What I paid'], $no_cost['Profit'], $no_cost['Check'],
    ]);
  }

  /**
   * Amounts are plain numbers for spreadsheets; the CSV quotes commas.
   */
  public function testAmountAndCsv(): void {
    $this->assertSame('1234.50', TaxExport::amount(1234.5));
    $this->assertSame('-5.00', TaxExport::amount(-5.0));
    $this->assertSame('', TaxExport::amount(NULL));
    $csv = TaxExport::csv(['A', 'B'], [['A' => 'x, y', 'B' => '1.00']]);
    $this->assertSame("A,B\n\"x, y\",1.00\n", $csv);
  }

}
