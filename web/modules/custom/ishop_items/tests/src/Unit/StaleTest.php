<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Stale;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the stale-listing nudges.
 */
#[CoversClass(Stale::class)]
final class StaleTest extends UnitTestCase {

  /**
   * Under 30 days: nothing. Then about 10% off, and donate at 90.
   */
  public function testSteps(): void {
    $this->assertNull(Stale::nudge('2026-09-16', '2026-10-15', 30.0));
    $n30 = Stale::nudge('2026-09-15', '2026-10-15', 30.0);
    $this->assertSame([30, 27, FALSE], [$n30['days'], $n30['suggested'], $n30['donate']]);
    $n89 = Stale::nudge('2026-07-18', '2026-10-15', 30.0);
    $this->assertSame([89, 30, 27], [$n89['days'], $n89['step'], $n89['suggested']]);
    $n90 = Stale::nudge('2026-07-17', '2026-10-15', 30.0);
    $this->assertSame([90, 90, NULL, TRUE], [$n90['days'], $n90['step'], $n90['suggested'], $n90['donate']]);
  }

  /**
   * Whole dollars, rounded down, never below $1; no price, no amount.
   */
  public function testSuggestedPrice(): void {
    $this->assertSame(17, Stale::nudge('2026-09-01', '2026-10-15', 19.99)['suggested']);
    $this->assertSame(1, Stale::nudge('2026-09-01', '2026-10-15', 1.0)['suggested']);
    $this->assertNull(Stale::nudge('2026-09-01', '2026-10-15', NULL)['suggested']);
  }

  /**
   * Cuts come from the asking price, so a lowered listing isn't nagged again.
   */
  public function testAlreadyLowered(): void {
    $this->assertTrue(Stale::nudge('2026-09-01', '2026-10-15', 30.0, 27.0)['lowered']);
    $this->assertFalse(Stale::nudge('2026-09-01', '2026-10-15', 30.0, 28.0)['lowered']);
    // At 90 days the next step (donate) applies whatever the price.
    $this->assertFalse(Stale::nudge('2026-07-01', '2026-10-15', 30.0, 27.0)['lowered']);
  }

  /**
   * The line people read.
   */
  public function testLine(): void {
    $this->assertSame('Up 44 days: try about 10% off ($27)', Stale::line(Stale::nudge('2026-09-01', '2026-10-15', 30.0)));
    $this->assertSame('Up 44 days: price already lowered, nothing to do for now', Stale::line(Stale::nudge('2026-09-01', '2026-10-15', 30.0, 25.0)));
    $this->assertSame('Up 100 days: consider donating it', Stale::line(Stale::nudge('2026-07-07', '2026-10-15', 30.0)));
  }

}
