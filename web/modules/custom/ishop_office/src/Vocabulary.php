<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

/**
 * The fixed lists the site office uses, shared by fields, parsers and Drush.
 */
final class Vocabulary {

  public const PHASE_STATUS = [
    'not_started' => 'Not started',
    'in_progress' => 'In progress',
    'blocked' => 'Blocked',
    'done' => 'Done',
  ];

  public const PEOPLE = [
    'liam' => 'Liam',
    'cody' => 'Cody',
    'claude' => 'Claude',
  ];

  public const PUNCH_STATUS = [
    'open' => 'Open',
    'closed' => 'Closed',
  ];

  /**
   * Maps a key or label (any case, spaces or dashes) to a list key.
   *
   * @param array<string, string> $list
   *   One of the constants above.
   *
   * @throws \InvalidArgumentException
   *   When the value is in neither the keys nor the labels.
   */
  public static function normalize(array $list, string $value): string {
    $wanted = strtolower(str_replace([' ', '-'], '_', trim($value)));
    foreach ($list as $key => $label) {
      if ($wanted === $key || $wanted === strtolower(str_replace([' ', '-'], '_', $label))) {
        return $key;
      }
    }
    throw new \InvalidArgumentException(sprintf('"%s" is not one of: %s.', $value, implode(', ', $list)));
  }

}
