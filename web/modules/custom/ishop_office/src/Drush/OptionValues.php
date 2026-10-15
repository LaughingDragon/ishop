<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Drush;

/**
 * Turns raw Drush option values into what the office expects.
 */
final class OptionValues {

  /**
   * A --phase option as a phase key, or NULL when not given.
   *
   * Drush hands an option value of "0" over as FALSE (and sometimes 0), which
   * once reached OfficeManager as FALSE and threw a TypeError. That is phase 0.
   */
  public static function phase(mixed $value): ?string {
    return match (TRUE) {
      $value === FALSE, $value === 0, $value === '0' => '0',
      $value === NULL, $value === '', $value === TRUE => NULL,
      default => (string) $value,
    };
  }

}
