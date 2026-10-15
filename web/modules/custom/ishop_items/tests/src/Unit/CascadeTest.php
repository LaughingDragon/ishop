<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Cascade;
use Drupal\ishop_items\Platforms as P;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the "Sold here" cascade rules.
 */
#[CoversClass(Cascade::class)]
final class CascadeTest extends UnitTestCase {

  /**
   * Live listings come down, unposted ones are not needed, others untouched.
   */
  public function testPlan(): void {
    $statuses = [
      'poshmark' => P::POSTED,
      'mercari' => P::SOLD_HERE,
      'depop' => P::TO_POST,
      'grailed' => P::NOT_ALLOWED,
      'vinted' => P::AT_RISK,
      'facebook' => P::TAKE_DOWN,
    ];
    $this->assertSame([
      'poshmark' => ['from' => P::POSTED, 'to' => P::TAKE_DOWN],
      'depop' => ['from' => P::TO_POST, 'to' => P::NOT_NEEDED_SOLD],
      'vinted' => ['from' => P::AT_RISK, 'to' => P::TAKE_DOWN],
    ], Cascade::plan($statuses, 'mercari'));
  }

  /**
   * Nothing else listed: no changes.
   */
  public function testPlanNothingElse(): void {
    $this->assertSame([], Cascade::plan(['mercari' => P::SOLD_HERE, 'grailed' => P::NOT_ALLOWED], 'mercari'));
  }

  /**
   * Un-marking restores what the cascade changed, unless changed since.
   */
  public function testRestore(): void {
    $listings = [
      // Changed by the cascade, untouched since: restored.
      'poshmark' => ['status' => P::TAKE_DOWN, 'before' => P::POSTED, 'from_sale' => TRUE],
      'depop' => ['status' => P::NOT_NEEDED_SOLD, 'before' => P::TO_POST, 'from_sale' => TRUE],
      // Changed by the cascade, then taken down by hand: stays as it is now.
      'vinted' => ['status' => P::NOT_ALLOWED, 'before' => P::AT_RISK, 'from_sale' => TRUE],
      // Not touched by the cascade.
      'grailed' => ['status' => P::TAKE_DOWN, 'before' => NULL, 'from_sale' => FALSE],
    ];
    $this->assertSame(['poshmark' => P::POSTED, 'depop' => P::TO_POST], Cascade::restore($listings));
  }

}
