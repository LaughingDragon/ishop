<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_shop\Unit;

use Drupal\ishop_shop\Shop;
use Drupal\ishop_shop\ShopPhotos;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the public shop rules (Phase 8, decision 266).
 */
#[CoversClass(Shop::class)]
#[CoversClass(ShopPhotos::class)]
final class ShopTest extends UnitTestCase {

  /**
   * Every condition must hold for an item to show.
   */
  public function testQualifies(): void {
    $this->assertTrue(Shop::qualifies(TRUE, TRUE, 'ready', TRUE, '24.00', 1));
    $this->assertTrue(Shop::qualifies(TRUE, TRUE, 'listed', TRUE, '0.50', 3));
    $this->assertFalse(Shop::qualifies(FALSE, TRUE, 'listed', TRUE, '24', 1), 'unpublished');
    $this->assertFalse(Shop::qualifies(TRUE, FALSE, 'listed', TRUE, '24', 1), 'not ticked');
    foreach (['draft', 'sold', 'donated', 'kept', NULL] as $status) {
      $this->assertFalse(Shop::qualifies(TRUE, TRUE, $status, TRUE, '24', 1), (string) $status);
    }
    $this->assertFalse(Shop::qualifies(TRUE, TRUE, 'listed', FALSE, '24', 1), 'not cleared');
    $this->assertFalse(Shop::qualifies(TRUE, TRUE, 'listed', TRUE, NULL, 1), 'no price');
    $this->assertFalse(Shop::qualifies(TRUE, TRUE, 'listed', TRUE, '0', 1), 'zero price');
    $this->assertFalse(Shop::qualifies(TRUE, TRUE, 'listed', TRUE, '24', 0), 'no photo');
  }

  /**
   * Slugs are readable, end in the item ID, and round-trip.
   */
  public function testSlug(): void {
    $this->assertSame('wooden-puzzle-1000-pieces-121', Shop::slugFor('Wooden Puzzle, 1000 Pieces!', 121));
    $this->assertSame('7', Shop::slugFor('!!!', 7));
    $long = Shop::slugFor(str_repeat('abcdefgh ', 12), 9);
    $this->assertLessThanOrEqual(62, strlen($long));
    $this->assertStringEndsWith('abcdefgh-9', $long);
    $this->assertSame(121, Shop::nidFromSlug('wooden-puzzle-1000-pieces-121'));
    $this->assertSame(7, Shop::nidFromSlug('7'));
    $this->assertNull(Shop::nidFromSlug('puzzle'));
  }

  /**
   * Buy links follow the platform order, with labels.
   */
  public function testOrderLinks(): void {
    $links = Shop::orderLinks(['ebay' => 'https://www.ebay.com/itm/1', 'poshmark' => 'https://poshmark.com/listing/x']);
    $this->assertSame(['poshmark', 'ebay'], array_keys($links));
    $this->assertSame('eBay', $links['ebay']['label']);
  }

  /**
   * Prices drop ".00".
   */
  public function testPrice(): void {
    $this->assertSame('$24', Shop::price('24.00'));
    $this->assertSame('$24.50', Shop::price('24.5'));
    $this->assertSame('$1,200', Shop::price('1200'));
  }

  /**
   * Copy names hide the file ID and change with the key and the file.
   */
  public function testNameFor(): void {
    $a = ShopPhotos::nameFor(5, 'private://items/a.jpg', 100, 'k');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $a);
    $this->assertSame($a, ShopPhotos::nameFor(5, 'private://items/a.jpg', 100, 'k'));
    $this->assertNotSame($a, ShopPhotos::nameFor(5, 'private://items/a.jpg', 101, 'k'));
    $this->assertNotSame($a, ShopPhotos::nameFor(5, 'private://items/a.jpg', 100, 'j'));
  }

}
