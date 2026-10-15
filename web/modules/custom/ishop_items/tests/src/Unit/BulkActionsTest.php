<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\BulkActions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the bulk asking-price change.
 */
#[CoversClass(BulkActions::class)]
final class BulkActionsTest extends UnitTestCase {

  /**
   * Set takes the amount; minus % rounds to the cent, never below $0.01.
   */
  public function testNewPrice(): void {
    $this->assertSame(25.0, BulkActions::newPrice(30.0, 'set', 25));
    $this->assertSame(12.5, BulkActions::newPrice(NULL, 'set', 12.5));
    $this->assertSame(27.0, BulkActions::newPrice(30.0, 'minus', 10));
    $this->assertSame(17.99, BulkActions::newPrice(19.99, 'minus', 10));
    $this->assertSame(0.01, BulkActions::newPrice(0.02, 'minus', 99));
    $this->assertNull(BulkActions::newPrice(NULL, 'minus', 10));
    $this->assertNull(BulkActions::newPrice(0.0, 'minus', 10));
  }

}
