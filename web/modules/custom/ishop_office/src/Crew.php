<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

use Drupal\ishop_office\Parser\SecretScanner;

/**
 * The crew-on-site beacon: pure logic, no Drupal services.
 *
 * The stored state is ['active' => bool, 'task' => string, 'since' => int,
 * 'heartbeat' => int] (Unix times). An active crew whose last heartbeat is
 * older than STALE_AFTER seconds counts as gone.
 */
final class Crew {

  /**
   * Drupal State key.
   */
  public const STATE_KEY = 'ishop_office.crew';

  /**
   * Cache tag invalidated whenever the beacon changes.
   */
  public const CACHE_TAG = 'ishop_office:crew';

  /**
   * Seconds without a heartbeat before an active crew counts as gone.
   */
  public const STALE_AFTER = 900;

  /**
   * Longest task text shown.
   */
  public const TASK_MAX = 80;

  /**
   * Turns a prompt into a short, safe task line.
   *
   * First non-empty line, whitespace collapsed, at most TASK_MAX characters.
   * Pasted blocks and anything that looks like a secret or an email address
   * are replaced with a neutral label.
   */
  public static function cleanTask(string $text): string {
    $line = '';
    foreach (preg_split('/\R/', $text) as $candidate) {
      if (trim($candidate) !== '') {
        $line = trim($candidate);
        break;
      }
    }
    if ($line === '') {
      return 'Working';
    }
    if (str_starts_with($line, '<pasted_content') || str_starts_with($line, '<')) {
      return 'Working on pasted instructions';
    }
    $line = (string) preg_replace('/\s+/u', ' ', $line);
    // Check the whole line, not just what fits, so a cut can't hide a secret.
    if (SecretScanner::scan($line)) {
      return 'Working (task hidden)';
    }
    if (mb_strlen($line) > self::TASK_MAX) {
      $line = rtrim(mb_substr($line, 0, self::TASK_MAX - 1)) . '…';
    }
    return $line;
  }

  /**
   * The state as shown: 'active' is FALSE once the heartbeat is stale.
   *
   * @return array{active: bool, task: string, since: ?int, heartbeat: ?int}
   */
  public static function view(?array $state, int $now): array {
    $heartbeat = isset($state['heartbeat']) ? (int) $state['heartbeat'] : NULL;
    return [
      'active' => !empty($state['active']) && $heartbeat !== NULL && $now - $heartbeat < self::STALE_AFTER,
      'task' => (string) ($state['task'] ?? ''),
      'since' => isset($state['since']) ? (int) $state['since'] : NULL,
      'heartbeat' => $heartbeat,
    ];
  }

  /**
   * How long anything showing the beacon may be cached, in seconds.
   *
   * While active, only until the heartbeat would go stale, so the beacon
   * turns grey on time without invalidating caches on every heartbeat.
   * Otherwise -1 (permanent): the cache tag covers every change.
   */
  public static function maxAge(?array $state, int $now): int {
    $view = self::view($state, $now);
    if (!$view['active']) {
      return -1;
    }
    return max(1, self::STALE_AFTER - ($now - (int) $view['heartbeat']));
  }

}
