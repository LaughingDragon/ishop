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
    // A phase taken out of the plan (Phase 6, redesign 2026-10-16).
    'removed' => 'Removed',
  ];

  /**
   * Who an item waits on, who decided, who answered.
   *
   * "site-foreman" is Claude working on the server (Drush, code, the VM).
   * "Claude (review)" is Claude in a browser or chat, for reviews and judgment;
   * it answers its punch list items through the site's answer form.
   */
  public const PEOPLE = [
    'liam' => 'Liam',
    'cody' => 'Cody',
    'site_foreman' => 'site-foreman',
    'claude' => 'Claude (review)',
  ];

  /**
   * People whose punch list items need step-by-step instructions.
   */
  public const NEEDS_INSTRUCTIONS = ['liam', 'cody', 'claude'];

  /**
   * Which person each site office account belongs to.
   */
  public const ACCOUNTS = [
    'webmaster' => 'liam',
    'theowner' => 'cody',
    'site-foreman' => 'site_foreman',
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
   * @param string $value
   *   What the user typed.
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
