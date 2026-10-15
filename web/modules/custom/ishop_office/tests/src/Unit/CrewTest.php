<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Crew;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the crew-on-site beacon rules.
 */
#[CoversClass(Crew::class)]
final class CrewTest extends UnitTestCase {

  /**
   * Task text from a prompt.
   */
  #[DataProvider('taskProvider')]
  public function testCleanTask(string $prompt, string $expected): void {
    $this->assertSame($expected, Crew::cleanTask($prompt));
  }

  /**
   * Prompts and the task line they give.
   */
  public static function taskProvider(): array {
    return [
      'first line' => ["Fix the punch list\nmore detail here", 'Fix the punch list'],
      'leading blank lines and spaces' => ["\n\n   Check   the   board  \n", 'Check the board'],
      'empty' => ["  \n ", 'Working'],
      'pasted block' => ["<pasted_content id=\"x\">\nDo things\n</pasted_content>", 'Working on pasted instructions'],
      'secret' => ['password: hunter2hunter2', 'Working (task hidden)'],
      'email' => ['Write to someone@example.com today', 'Working (task hidden)'],
      'long' => [str_repeat('a', 100), str_repeat('a', 79) . '…'],
      'exactly max' => [str_repeat('b', 80), str_repeat('b', 80)],
    ];
  }

  /**
   * Active until the heartbeat is STALE_AFTER seconds old.
   */
  public function testView(): void {
    $state = ['active' => TRUE, 'task' => 'T', 'since' => 1000, 'heartbeat' => 2000];
    $this->assertTrue(Crew::view($state, 2000)['active']);
    $this->assertTrue(Crew::view($state, 2000 + Crew::STALE_AFTER - 1)['active']);
    $this->assertFalse(Crew::view($state, 2000 + Crew::STALE_AFTER)['active']);
    $this->assertFalse(Crew::view(['active' => FALSE] + $state, 2000)['active']);
    $this->assertSame(['active' => FALSE, 'task' => '', 'since' => NULL, 'heartbeat' => NULL], Crew::view(NULL, 5));
  }

  /**
   * Cached only until the beacon would go stale; permanent when quiet.
   */
  public function testMaxAge(): void {
    $state = ['active' => TRUE, 'task' => 'T', 'since' => 1000, 'heartbeat' => 2000];
    $this->assertSame(Crew::STALE_AFTER, Crew::maxAge($state, 2000));
    $this->assertSame(Crew::STALE_AFTER - 600, Crew::maxAge($state, 2600));
    $this->assertSame(-1, Crew::maxAge($state, 2000 + Crew::STALE_AFTER));
    $this->assertSame(-1, Crew::maxAge(NULL, 2000));
  }

}
