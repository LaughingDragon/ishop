<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\StaleNudges;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests how Cody's answers hide stale-listing nudges.
 */
#[CoversClass(StaleNudges::class)]
final class StaleNudgesTest extends UnitTestCase {

  /**
   * Snooze hides for two weeks; Done and Keep until the next step.
   */
  public function testHides(): void {
    $price = ['step' => 30];
    $donate = ['step' => 90];
    $this->assertFalse(StaleNudges::hides(NULL, $price, '2026-10-15'));

    $snooze = ['action' => StaleNudges::SNOOZE, 'step' => 30, 'until' => '2026-10-29'];
    $this->assertTrue(StaleNudges::hides($snooze, $price, '2026-10-28'));
    $this->assertFalse(StaleNudges::hides($snooze, $price, '2026-10-29'));

    foreach ([StaleNudges::DONE, StaleNudges::KEEP] as $action) {
      $answered = ['action' => $action, 'step' => 30, 'until' => '2026-10-29'];
      $this->assertTrue(StaleNudges::hides($answered, $price, '2027-01-01'), $action);
      $this->assertFalse(StaleNudges::hides($answered, $donate, '2026-12-01'), $action);
      $this->assertTrue(StaleNudges::hides(['step' => 90] + $answered, $donate, '2027-06-01'), $action);
    }
  }

}
