<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Saving, renewing, testing and removing Cody's Anthropic key.
 *
 * A new key is always tested first and only switched over on success, so a
 * failed test leaves the old key in place. Also sends the expiry reminders
 * and runs the daily health check.
 */
final class AiKeyManager {

  /**
   * The owner's account (Cody): reminders go here.
   */
  public const OWNER_UID = 3;

  /**
   * The key file; another path only in tests (never Cody's real key).
   */
  private string $file = AnthropicKey::FILE;

  /**
   * The test call; replaced only in tests.
   *
   * @var callable
   */
  private $tester = [AnthropicKey::class, 'test'];

  public function __construct(
    private readonly AiStatus $status,
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.ishop_ai')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * For tests only: another key file and a stand-in for the API call.
   */
  public function useForTesting(string $file, callable $tester): void {
    $this->file = $file;
    $this->tester = $tester;
  }

  /**
   * Tests a new key and switches to it only if it works.
   *
   * @return array{ok: bool, message: string, old4: ?string}
   *   old4: the replaced key's last 4 (so Cody can delete it at Anthropic).
   */
  public function renew(string $typed, string $expires): array {
    $key = AnthropicKey::normalize($typed);
    if ($key === NULL) {
      return [
        'ok' => FALSE,
        'message' => 'That doesn\'t look like an Anthropic API key: it starts with "sk-ant-" and is one long line.',
        'old4' => NULL,
      ];
    }
    $result = ($this->tester)($key);
    if (!$result['ok']) {
      // The old key (if any) stays exactly as it was.
      return ['ok' => FALSE, 'message' => $result['message'], 'old4' => NULL];
    }
    $before = $this->status->get();
    $old4 = AnthropicKey::has($this->file) && $before['configured'] ? $before['last4'] : NULL;
    if (!AnthropicKey::save($key, $this->file)) {
      return [
        'ok' => FALSE,
        'message' => 'The key works, but the server could not save it. Nothing was changed; tell site-foreman.',
        'old4' => NULL,
      ];
    }
    $this->status->recordSaved(AnthropicKey::last4($key), $expires);
    $this->logger->notice('Anthropic key @what (ends in @last4, expires @expires).', [
      '@what' => $old4 ? 'renewed' : 'saved',
      '@last4' => AnthropicKey::last4($key),
      '@expires' => $expires === '' ? 'never' : $expires,
    ]);
    return ['ok' => TRUE, 'message' => 'Key works.', 'old4' => $old4];
  }

  /**
   * Tests the saved key and records the result (pausing AI if refused).
   *
   * @return array{ok: bool, kind: string, message: string}
   *   The test result.
   */
  public function testSaved(): array {
    $key = AnthropicKey::get($this->file);
    if ($key === NULL) {
      return ['ok' => FALSE, 'kind' => 'none', 'message' => 'No key is saved.'];
    }
    $result = ($this->tester)($key);
    unset($key);
    $result['ok'] ? $this->status->recordOk() : $this->status->recordFailure($result['kind'], $result['message']);
    return $result;
  }

  /**
   * Records the outcome of any real AI call made with the saved key.
   *
   * The Phase 7 helpers call this, so a refused key or an empty account
   * pauses AI at once (everything else keeps working).
   */
  public function recordCall(array $result): void {
    $result['ok'] ? $this->status->recordOk() : $this->status->recordFailure($result['kind'], $result['message']);
  }

  /**
   * Removes the key.
   */
  public function remove(): void {
    AnthropicKey::remove($this->file);
    $this->status->recordRemoved();
    $this->logger->notice('Anthropic key removed.');
  }

  /**
   * The daily health check: one tiny call with the saved key.
   *
   * A refused key, an empty account or a key without model access pauses AI
   * (queued drafts wait) and emails Cody once per pause, with the renew link.
   * Busy or unreachable is only noted. A later passing check resumes AI.
   */
  public function healthCheck(): string {
    $status = $this->status->get();
    if (empty($status['configured'])) {
      return 'Health check: no key saved.';
    }
    $result = $this->testSaved();
    if ($result['ok']) {
      return 'Health check: key works.';
    }
    if (!in_array($result['kind'], AiStatus::PAUSING_KINDS, TRUE)) {
      $this->logger->warning('AI health check could not finish: @message', ['@message' => $result['message']]);
      return 'Health check: could not finish (' . $result['kind'] . '): ' . $result['message'];
    }
    $this->logger->warning('AI health check failed, AI paused: @message', ['@message' => $result['message']]);
    if (!empty($this->status->get()['pause_notified'])) {
      return 'Health check: still failing, AI paused (Cody already told).';
    }
    return $this->sendPauseNotice($result['message']);
  }

  /**
   * Emails Cody that AI is paused and how to fix it.
   */
  private function sendPauseNotice(string $why): string {
    $account = $this->entityTypeManager->getStorage('user')->load(self::OWNER_UID);
    if (!$account?->getEmail()) {
      return 'Health check: AI paused; the owner account has no email address.';
    }
    $renew = Url::fromRoute('ishop_ai.setup', [], ['absolute' => TRUE, 'fragment' => 'renew'])->toString();
    $subject = 'AI is paused: your Anthropic key stopped working';
    $body = implode("\n", [
      'This morning\'s check of your AI key failed, so the AI listing helpers are paused.',
      '',
      'What Anthropic said: ' . $why,
      '',
      'Everything else keeps working: items and photos still save, and any "Write my listing" requests wait and run once AI is back.',
      '',
      'To fix it, open AI setup: ' . $renew,
      'If the account is out of credit, add credit in the Anthropic Console (Billing). Otherwise create a new key and paste it there. AI turns back on by itself once a check passes.',
    ]);
    $result = $this->mailManager->mail('ishop_ai', 'key_paused', $account->getEmail(), $account->getPreferredLangcode(), [
      'subject' => $subject,
      'body' => $body,
    ]);
    if (empty($result['result'])) {
      return 'Health check: AI paused; the email to Cody could not be sent (see the mail status).';
    }
    $this->status->markPauseNotified();
    $this->logger->notice('AI paused notice sent to the owner.');
    return 'Health check: AI paused; Cody emailed.';
  }

  /**
   * Sends today's expiry reminder (7, 2 or 0 days) to Cody, if one is due.
   */
  public function sendReminderIfDue(): string {
    $status = $this->status->get();
    $today = AiStatus::today();
    $milestone = AiStatus::reminderDue($status, $today);
    if ($milestone === NULL) {
      return 'No reminder due.';
    }
    $days = AiStatus::daysLeft((string) $status['expires_on'], $today);
    $account = $this->entityTypeManager->getStorage('user')->load(self::OWNER_UID);
    if (!$account?->getEmail()) {
      return 'The owner account has no email address.';
    }
    $renew = Url::fromRoute('ishop_ai.setup', [], ['absolute' => TRUE, 'fragment' => 'renew'])->toString();
    $subject = $days === 0 ? 'Your AI key expires today' : sprintf('Your AI key expires in %d %s', $days, $days === 1 ? 'day' : 'days');
    $body = implode("\n", [
      $subject . sprintf(' (%s, the key ending in %s).', (new \DateTimeImmutable((string) $status['expires_on']))->format('l, M j'), $status['last4']),
      '',
      'When it expires, the AI listing helpers pause. Everything else keeps working, and items and photos still save.',
      '',
      'Renew it (about 3 minutes): ' . $renew,
      'The page walks you through it: create a new key in your Anthropic account, paste it, and the site tests it and switches over straight away.',
    ]);
    $result = $this->mailManager->mail('ishop_ai', 'key_reminder', $account->getEmail(), $account->getPreferredLangcode(), [
      'subject' => $subject,
      'body' => $body,
    ]);
    if (empty($result['result'])) {
      return 'The reminder could not be sent (see the mail status).';
    }
    $this->status->markReminderSent($milestone);
    $this->logger->notice('AI key reminder sent: @subject.', ['@subject' => $subject]);
    return 'Sent: ' . $subject . '.';
  }

}
