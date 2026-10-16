<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Drupal\Core\State\StateInterface;

/**
 * What the site knows about Cody's Anthropic key: never the key itself.
 *
 * Saved on, expires on (Anthropic keys last 30 days), the last 4 characters,
 * the last successful call, the last error, whether AI is paused and why, and
 * the dates of past renewals. Kept in State (it changes; it isn't config).
 */
final class AiStatus {

  public const STATE = 'ishop_ai.status';

  /**
   * Days before expiry when reminder emails go out (0 = on the day).
   */
  public const MILESTONES = [7, 2, 0];

  /**
   * Days before expiry from which "Needs you now" shows it.
   */
  public const SHOW_FROM_DAYS = 7;

  /**
   * Default lifetime of a new key, when Cody doesn't enter the date.
   */
  public const DEFAULT_DAYS = 30;

  /**
   * Why AI can be paused, in plain words.
   */
  public const PAUSE_REASONS = [
    'auth' => 'Anthropic stopped accepting the key',
    'credit' => 'the Anthropic account is out of credit',
    'expired' => 'the key has expired',
  ];

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Today in the site's (Pacific) time zone, Y-m-d.
   */
  public static function today(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
  }

  /**
   * The default expiry date for a key saved today.
   */
  public static function defaultExpiry(string $today): string {
    return (new \DateTimeImmutable($today))->modify('+' . self::DEFAULT_DAYS . ' days')->format('Y-m-d');
  }

  /**
   * Days from today until the expiry date (0 on the day, negative after).
   */
  public static function daysLeft(string $expires, string $today): int {
    return (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($expires))->format('%r%a');
  }

  /**
   * The record, with defaults.
   */
  public function get(): array {
    return ($this->state->get(self::STATE) ?? []) + [
      'configured' => FALSE,
      'saved_on' => NULL,
      'expires_on' => NULL,
      'last4' => NULL,
      'last_ok' => NULL,
      'last_error' => NULL,
      'paused' => NULL,
      'history' => [],
      'reminders_sent' => [],
    ];
  }

  /**
   * Saves the record.
   */
  public function set(array $status): void {
    $this->state->set(self::STATE, $status);
  }

  /**
   * Why AI is paused now, or NULL: a recorded failure, or past the expiry.
   */
  public static function pausedReason(array $status, string $today): ?string {
    if (empty($status['configured'])) {
      return NULL;
    }
    if (!empty($status['paused'])) {
      return $status['paused'];
    }
    if ($status['expires_on'] && self::daysLeft($status['expires_on'], $today) < 0) {
      return 'expired';
    }
    return NULL;
  }

  /**
   * The reminder email due today (7, 2 or 0 days), or NULL.
   *
   * If a reminder was missed (the server was off), only the most urgent one
   * still due is sent; the earlier ones are then counted as sent.
   */
  public static function reminderDue(array $status, string $today): ?int {
    if (empty($status['configured']) || !$status['expires_on']) {
      return NULL;
    }
    $days = self::daysLeft($status['expires_on'], $today);
    if ($days < 0) {
      return NULL;
    }
    $sent = $status['reminders_sent'] ?? [];
    $due = NULL;
    foreach (self::MILESTONES as $milestone) {
      if ($days <= $milestone && !in_array($milestone, $sent, TRUE)) {
        $due = $milestone;
      }
    }
    return $due;
  }

  /**
   * Records a reminder (and every earlier milestone) as sent.
   */
  public function markReminderSent(int $milestone): void {
    $status = $this->get();
    foreach (self::MILESTONES as $m) {
      if ($m >= $milestone && !in_array($m, $status['reminders_sent'], TRUE)) {
        $status['reminders_sent'][] = $m;
      }
    }
    $this->set($status);
  }

  /**
   * A new key is in use: fresh dates, nothing paused, reminders reset.
   */
  public function recordSaved(string $last4, string $expires): void {
    $status = $this->get();
    $today = self::today();
    $status = [
      'configured' => TRUE,
      'saved_on' => $today,
      'expires_on' => $expires,
      'last4' => $last4,
      'last_ok' => date('c'),
      'last_error' => NULL,
      'paused' => NULL,
      'history' => array_merge($status['history'], [$today]),
      'reminders_sent' => [],
    ];
    $this->set($status);
  }

  /**
   * The key was removed: not configured (history kept).
   */
  public function recordRemoved(): void {
    $status = $this->get();
    $this->set(['configured' => FALSE, 'history' => $status['history']]);
  }

  /**
   * A call with the saved key worked: AI is running.
   */
  public function recordOk(): void {
    $status = $this->get();
    $status['last_ok'] = date('c');
    $status['paused'] = NULL;
    $this->set($status);
  }

  /**
   * A call with the saved key failed.
   *
   * A refused key or an empty account pauses AI; anything else (busy,
   * no connection) is only noted.
   */
  public function recordFailure(string $kind, string $message): void {
    $status = $this->get();
    $status['last_error'] = ['time' => date('c'), 'message' => $message];
    if (in_array($kind, ['auth', 'credit'], TRUE)) {
      $status['paused'] = $kind;
    }
    $this->set($status);
  }

  /**
   * The "Needs you now" line about the key, or NULL.
   */
  public static function needsLine(array $status, string $today): ?string {
    if (empty($status['configured'])) {
      return NULL;
    }
    if ($reason = self::pausedReason($status, $today)) {
      return sprintf('AI is paused: %s. Items and photos still save. Renew the key to turn AI back on.', self::PAUSE_REASONS[$reason] ?? $reason);
    }
    $days = self::daysLeft((string) $status['expires_on'], $today);
    if ($days === 0) {
      return 'Your AI key expires today.';
    }
    if ($days <= self::SHOW_FROM_DAYS) {
      return sprintf('Your AI key expires in %d %s.', $days, $days === 1 ? 'day' : 'days');
    }
    return NULL;
  }

  /**
   * The "ai" section of status.json: no key material.
   */
  public static function summary(array $status, string $today): array {
    $expires = $status['configured'] ? $status['expires_on'] : NULL;
    return [
      'configured' => (bool) $status['configured'],
      'expires_on' => $expires,
      'days_left' => $expires ? self::daysLeft($expires, $today) : NULL,
      'last_ok' => $status['configured'] ? $status['last_ok'] : NULL,
      'paused' => self::pausedReason($status, $today),
      'renewals' => $status['history'],
    ];
  }

}
