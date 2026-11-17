<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Form\ListingsGridForm;
use Drupal\ishop_items\Platforms;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that a typed "Sold for" price on an untouched row marks a sale.
 */
#[CoversClass(ListingsGridForm::class)]
final class ListingsGridStatusTest extends UnitTestCase {

  /**
   * Submitted rows and the status they mean.
   */
  #[DataProvider('rowProvider')]
  public function testRowStatus(array $row, string $was, ?string $old, string $expected): void {
    $this->assertSame($expected, ListingsGridForm::rowStatus($row, $was, $old));
  }

  /**
   * Rows as the grid submits them.
   */
  public static function rowProvider(): array {
    $p = Platforms::POSTED;
    $s = Platforms::SOLD_HERE;
    $t = Platforms::TO_POST;
    $n = Platforms::NOT_NEEDED_SOLD;
    $d = Platforms::TAKE_DOWN;
    return [
      'punch 303: price typed, status left on Posted' => [['status' => $p, 'sold_price' => '16.45'], $p, NULL, $s],
      'price typed on To post' => [['status' => $t, 'sold_price' => '5'], $t, NULL, $s],
      'no price' => [['status' => $p, 'sold_price' => ''], $p, NULL, $p],
      'price already saved (undone sale)' => [['status' => $p, 'sold_price' => '16.45'], $p, '16.45', $p],
      'status changed by hand wins' => [['status' => $d, 'sold_price' => '9'], $p, NULL, $d],
      'already sold here' => [['status' => $s, 'sold_price' => '9'], $s, NULL, $s],
      'not needed - sold elsewhere' => [['status' => $n, 'sold_price' => '9'], $n, NULL, $n],
    ];
  }

}
