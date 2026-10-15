<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;

/**
 * The football and Gold Rush accents' data: season start and game days.
 *
 * Cody edits both in My office. They are the owner's data, not site
 * structure, so they live in State: editing them never makes config differ
 * from git.
 * Football flavour only: no team names, nicknames, logos or slogans anywhere
 * (punch item 135, part 2).
 */
final class Season {

  public const STATE = 'ishop_matrix.season';

  public const CACHE_TAG = 'ishop_season';

  /**
   * Season start when none is set: January 1.
   */
  public const DEFAULT_START = '01-01';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Start ("MM-DD") and game days ([date Y-m-d, kickoff H:i or ""]), sorted.
   */
  public function settings(): array {
    return ($this->state->get(self::STATE) ?? []) + ['start' => self::DEFAULT_START, 'game_days' => []];
  }

  /**
   * Saves the settings: valid game days only, sorted, no duplicate dates.
   */
  public function save(string $start, array $game_days): void {
    $days = [];
    foreach ($game_days as $day) {
      $date = trim((string) ($day['date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        continue;
      }
      $kickoff = trim((string) ($day['kickoff'] ?? ''));
      $days[$date] = [
        'date' => $date,
        'kickoff' => preg_match('/^\d{2}:\d{2}/', $kickoff) ? substr($kickoff, 0, 5) : '',
      ];
    }
    ksort($days);
    $this->state->set(self::STATE, [
      'start' => preg_match('/^\d{2}-\d{2}$/', $start) ? $start : self::DEFAULT_START,
      'game_days' => array_values($days),
    ]);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * When the current season started: the last start date on or before today.
   */
  public function startOfSeason(): \DateTimeImmutable {
    $now = $this->now();
    [$month, $day] = array_map('intval', explode('-', $this->settings()['start']));
    $start = $now->setDate((int) $now->format('Y'), $month, $day)->setTime(0, 0);
    return $start > $now ? $start->modify('-1 year') : $start;
  }

  /**
   * Today's game ([date, kickoff]), or NULL when today isn't a game day.
   */
  public function todaysGame(): ?array {
    $today = $this->now()->format('Y-m-d');
    foreach ($this->settings()['game_days'] as $day) {
      if ($day['date'] === $today) {
        return $day;
      }
    }
    return NULL;
  }

  /**
   * Now, in the site's (Pacific) time zone.
   */
  private function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new \DateTimeZone('America/Los_Angeles'));
  }

}
