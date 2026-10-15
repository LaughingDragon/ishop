<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Drush\OptionValues;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests --phase option handling.
 *
 * Regression: on 2026-10-15 "drush ishop:decision ... --phase=0" passed FALSE
 * to OfficeManager::addDecision() and threw a TypeError (four log entries).
 */
#[CoversClass(OptionValues::class)]
#[Group('ishop_office')]
final class OptionValuesTest extends UnitTestCase {

  /**
   * Raw Drush values and the phase key they must become.
   */
  public static function phases(): array {
    return [
      'FALSE from --phase=0' => [FALSE, '0'],
      'integer 0' => [0, '0'],
      'string 0' => ['0', '0'],
      'not given' => [NULL, NULL],
      'empty' => ['', NULL],
      'flag without value' => [TRUE, NULL],
      'decimal' => ['0.5', '0.5'],
      'float' => [0.5, '0.5'],
      'foundation' => ['foundation', 'foundation'],
      'integer 3' => [3, '3'],
    ];
  }

  /**
   * Tests that each raw value maps to the right phase key.
   */
  #[DataProvider('phases')]
  public function testPhase(mixed $raw, ?string $expected): void {
    $this->assertSame($expected, OptionValues::phase($raw));
  }

}
