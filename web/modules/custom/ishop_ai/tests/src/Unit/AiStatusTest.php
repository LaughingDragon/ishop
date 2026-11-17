<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_ai\Unit;

use Drupal\ishop_ai\AiStatus;
use Drupal\ishop_office\Plugin\Mail\IshopMail;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the AI key's expiry, reminders and pausing.
 */
#[CoversClass(AiStatus::class)]
final class AiStatusTest extends UnitTestCase {

  /**
   * A configured key expiring on $expires.
   */
  private function record(string $expires, array $more = []): array {
    return $more + [
      'configured' => TRUE,
      'expires_on' => $expires,
      'last4' => 'abcd',
      'last_ok' => NULL,
      'paused' => NULL,
      'history' => ['2026-10-15'],
      'reminders_sent' => [],
    ];
  }

  /**
   * Reminders at 7, 2 and 0 days, once each.
   */
  public function testReminderDue(): void {
    $s = $this->record('2026-11-14');
    $this->assertNull(AiStatus::reminderDue($s, '2026-11-06'));
    $this->assertSame(7, AiStatus::reminderDue($s, '2026-11-07'));
    $this->assertSame(2, AiStatus::reminderDue(['reminders_sent' => [7]] + $s, '2026-11-12'));
    $this->assertNull(AiStatus::reminderDue(['reminders_sent' => [7]] + $s, '2026-11-11'));
    $this->assertSame(0, AiStatus::reminderDue(['reminders_sent' => [7, 2]] + $s, '2026-11-14'));
    $this->assertNull(AiStatus::reminderDue(['reminders_sent' => [7, 2, 0]] + $s, '2026-11-14'));
    // Missed days: only the most urgent one still due.
    $this->assertSame(2, AiStatus::reminderDue($s, '2026-11-13'));
    // After expiry, no reminder email (AI is paused and shows on the dashboard).
    $this->assertNull(AiStatus::reminderDue($s, '2026-11-15'));
  }

  /**
   * Paused by a refused key, an empty account, or the date passing.
   */
  public function testPaused(): void {
    $this->assertNull(AiStatus::pausedReason($this->record('2026-11-14'), '2026-11-14'));
    $this->assertSame('expired', AiStatus::pausedReason($this->record('2026-11-14'), '2026-11-15'));
    $this->assertSame('auth', AiStatus::pausedReason($this->record('2026-11-14', ['paused' => 'auth']), '2026-10-20'));
    $this->assertNull(AiStatus::pausedReason(['configured' => FALSE, 'expires_on' => '2020-01-01', 'paused' => 'auth'], '2026-10-20'));
  }

  /**
   * The "Needs you now" wording.
   */
  public function testNeedsLine(): void {
    $s = $this->record('2026-11-14');
    $this->assertNull(AiStatus::needsLine($s, '2026-11-06'));
    $this->assertSame('Your AI key expires in 7 days.', AiStatus::needsLine($s, '2026-11-07'));
    $this->assertSame('Your AI key expires in 1 day.', AiStatus::needsLine($s, '2026-11-13'));
    $this->assertSame('Your AI key expires today.', AiStatus::needsLine($s, '2026-11-14'));
    $this->assertStringStartsWith('AI is paused: the key has expired.', AiStatus::needsLine($s, '2026-11-15'));
    $this->assertStringStartsWith('AI is paused: the Anthropic account is out of credit.', AiStatus::needsLine(['paused' => 'credit'] + $s, '2026-10-20'));
  }

  /**
   * The status.json section holds no key material.
   */
  public function testSummary(): void {
    $summary = AiStatus::summary($this->record('2026-11-14'), '2026-11-04');
    $this->assertSame(['configured', 'expires_on', 'days_left', 'last_ok', 'paused', 'renewals'], array_keys($summary));
    $this->assertSame(10, $summary['days_left']);
    $this->assertStringNotContainsString('abcd', json_encode($summary));
  }

  /**
   * Gmail's refusal is recognised; other failures are not.
   */
  public function testMailAuthFailure(): void {
    $this->assertTrue(IshopMail::isAuthFailure('Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted.'));
    $this->assertFalse(IshopMail::isAuthFailure('Connection could not be established with host "smtp.gmail.com:587": stream_socket_client(): Unable to connect'));
  }

}
