<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The birthday email to Cody (TEMPORARY: see TEMPORARY.md).
 *
 * Sent once, at 7:00 a.m. Pacific on the birthday (ishop-birthday-mail.timer
 * runs drush ishop:birthday-mail), to the owner's account email, through the
 * site's Gmail mail (the ishop_mail plugin records it like any other send).
 * Only with Liam-approved text. Webmaster can send a test to webmaster only.
 */
final class BirthdayMail {

  /**
   * The owner's account (Cody).
   */
  public const OWNER_UID = 3;

  /**
   * Webmaster's account (Liam): tests go here only.
   */
  public const WEBMASTER_UID = 1;

  public function __construct(
    private readonly Birthday $birthday,
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.ishop_matrix')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Why the email would not go out now, or NULL if it should.
   */
  public function blocker(): ?string {
    $settings = $this->birthday->settings();
    return match (TRUE) {
      !$this->birthday->isTheDay() => 'today is not the birthday',
      $settings['mode'] === 'off' => 'birthday mode is switched off',
      !$this->hasText() => 'no email text yet',
      empty($settings['mail_approved']) => 'the text is not approved yet',
      ($settings['mail_sent'] ?? '') === $this->birthday->todayDate() => 'already sent today',
      default => NULL,
    };
  }

  /**
   * Whether a subject and body have been saved.
   */
  public function hasText(): bool {
    $settings = $this->birthday->settings();
    return trim((string) ($settings['mail_subject'] ?? '')) !== '' && trim((string) ($settings['mail_body'] ?? '')) !== '';
  }

  /**
   * Sends the birthday email to Cody if everything is in place.
   *
   * @return string
   *   What happened, for the log and the command line.
   */
  public function sendIfDue(): string {
    if ($why = $this->blocker()) {
      return 'Not sent: ' . $why . '.';
    }
    $ok = $this->send(self::OWNER_UID, FALSE);
    if ($ok) {
      $settings = $this->birthday->settings();
      $settings['mail_sent'] = $this->birthday->todayDate();
      $this->birthday->save($settings);
    }
    return $ok ? 'Sent the birthday email to the owner.' : 'Sending failed: see the mail status on the site office board.';
  }

  /**
   * Sends a test to webmaster only.
   */
  public function sendTest(AccountInterface $by): bool {
    if ((int) $by->id() !== self::WEBMASTER_UID || !$this->hasText()) {
      return FALSE;
    }
    return $this->send(self::WEBMASTER_UID, TRUE);
  }

  /**
   * Sends to one account's email. The address is never logged.
   */
  private function send(int $uid, bool $test): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    $to = $account?->getEmail();
    if (!$to) {
      $this->logger->error('Birthday email: account @uid has no email address.', ['@uid' => $uid]);
      return FALSE;
    }
    $settings = $this->birthday->settings();
    $result = $this->mailManager->mail('ishop_matrix', $test ? 'birthday_test' : 'birthday', $to, $account->getPreferredLangcode(), [
      'subject' => ($test ? '[TEST] ' : '') . trim((string) $settings['mail_subject']),
      'body' => (string) $settings['mail_body'],
    ]);
    $ok = !empty($result['result']);
    $this->logger->notice('Birthday email @kind to account @uid: @result.', [
      '@kind' => $test ? 'test' : '',
      '@uid' => $uid,
      '@result' => $ok ? 'sent' : 'failed',
    ]);
    return $ok;
  }

}
