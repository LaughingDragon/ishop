<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_matrix\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_matrix\Birthday;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests when birthday mode is on, and the once-a-day launch.
 *
 * TEMPORARY (birthday mode): see TEMPORARY.md.
 */
#[CoversClass(Birthday::class)]
final class BirthdayTest extends UnitTestCase {

  /**
   * In-memory State.
   */
  private array $store = [];

  /**
   * Birthday mode at a given moment.
   */
  private function birthday(string $now, array $settings): Birthday {
    $this->store = [Birthday::STATE => $settings];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn ($key) => $this->store[$key] ?? NULL);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->store[$key] = $value;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn((new \DateTimeImmutable($now, new \DateTimeZone('America/Los_Angeles')))->getTimestamp());
    return new Birthday($state, $time);
  }

  /**
   * A signed-in account with a user ID.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    return $account;
  }

  /**
   * Automatic: all day on the date, Pacific time, for everyone signed in.
   */
  public function testAutomaticOnTheDay(): void {
    $owner = $this->account(3);
    $this->assertTrue($this->birthday('2026-11-02 00:01', ['date' => '11-02'])->isActive($owner));
    $this->assertTrue($this->birthday('2026-11-02 23:59', ['date' => '11-02'])->isActive($owner));
    $this->assertFalse($this->birthday('2026-11-01 23:59', ['date' => '11-02'])->isActive($owner));
    $this->assertFalse($this->birthday('2026-11-03 00:00', ['date' => '11-02'])->isActive($owner));
    $this->assertFalse($this->birthday('2026-11-02 12:00', ['date' => ''])->isActive($owner));
    $this->assertFalse($this->birthday('2026-11-02 12:00', ['date' => '11-02'])->isActive($this->account(0)));
  }

  /**
   * Preview is for the crew only, never the owner; "off" wins on the day.
   */
  public function testPreviewAndModes(): void {
    $preview = ['date' => '11-02', 'preview' => [1, 3]];
    $this->assertTrue($this->birthday('2026-10-15 12:00', $preview)->isActive($this->account(1)));
    $this->assertFalse($this->birthday('2026-10-15 12:00', $preview)->isActive($this->account(3)));
    $this->assertFalse($this->birthday('2026-10-15 12:00', $preview)->isActive($this->account(2)));
    $this->assertTrue($this->birthday('2026-10-15 12:00', ['mode' => 'on'])->isActive($this->account(3)));
    $this->assertFalse($this->birthday('2026-11-02 12:00', ['date' => '11-02', 'mode' => 'off'])->isActive($this->account(3)));
  }

  /**
   * The first page of the day launches the party once per account.
   */
  public function testLaunchOncePerDay(): void {
    $birthday = $this->birthday('2026-11-02 09:00', ['date' => '11-02']);
    $owner = $this->account(3);
    $this->assertTrue($birthday->claimLaunch($owner));
    $this->assertFalse($birthday->claimLaunch($owner));
    $this->assertTrue($birthday->claimLaunch($this->account(1)));
    $birthday->resetLaunch($owner);
    $this->assertTrue($birthday->claimLaunch($owner));
    $this->assertFalse($this->birthday('2026-11-03 09:00', ['date' => '11-02'])->claimLaunch($owner));
  }

}
