<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_matrix\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_matrix\Season;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the season start and game days.
 */
#[CoversClass(Season::class)]
final class SeasonTest extends UnitTestCase {

  /**
   * In-memory State.
   */
  private array $store = [];

  /**
   * The season at a given moment (Pacific).
   */
  private function season(string $now, ?array $settings = NULL): Season {
    $this->store = $settings === NULL ? [] : [Season::STATE => $settings];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn ($key) => $this->store[$key] ?? NULL);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->store[$key] = $value;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn((new \DateTimeImmutable($now, new \DateTimeZone('America/Los_Angeles')))->getTimestamp());
    return new Season($state, $time, $this->createMock(CacheTagsInvalidatorInterface::class));
  }

  /**
   * The season started on the last start date on or before today.
   */
  public function testStartOfSeason(): void {
    $this->assertSame('2026-01-01 00:00', $this->season('2026-10-15 12:00')->startOfSeason()->format('Y-m-d H:i'));
    $this->assertSame('2026-09-04', $this->season('2026-10-15 12:00', ['start' => '09-04'])->startOfSeason()->format('Y-m-d'));
    $this->assertSame('2025-09-04', $this->season('2026-03-01 12:00', ['start' => '09-04'])->startOfSeason()->format('Y-m-d'));
    $this->assertSame('2026-09-04', $this->season('2026-09-04 00:00', ['start' => '09-04'])->startOfSeason()->format('Y-m-d'));
  }

  /**
   * Today's game, by the Pacific date.
   */
  public function testTodaysGame(): void {
    $settings = ['game_days' => [['date' => '2026-11-08', 'kickoff' => '10:00']]];
    $this->assertSame('10:00', $this->season('2026-11-08 23:30', $settings)->todaysGame()['kickoff']);
    $this->assertNull($this->season('2026-11-09 00:00', $settings)->todaysGame());
  }

  /**
   * Saving keeps valid dates only, sorted, one per date; bad start resets.
   */
  public function testSaveCleansUp(): void {
    $season = $this->season('2026-10-15 12:00');
    $season->save('nope', [
      ['date' => '2026-11-08', 'kickoff' => '10:00:00'],
      ['date' => '', 'kickoff' => '13:00'],
      ['date' => '2026-10-20', 'kickoff' => ''],
      ['date' => '2026-11-08', 'kickoff' => '13:25'],
    ]);
    $this->assertSame([
      'start' => '01-01',
      'game_days' => [
        ['date' => '2026-10-20', 'kickoff' => ''],
        ['date' => '2026-11-08', 'kickoff' => '13:25'],
      ],
    ], $season->settings());
  }

}
