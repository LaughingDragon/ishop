<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_matrix\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_matrix\Birthday;
use Drupal\ishop_matrix\BirthdayMail;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;

/**
 * Tests when the birthday email goes out, and to whom.
 *
 * TEMPORARY (birthday mode): see TEMPORARY.md.
 */
#[CoversClass(BirthdayMail::class)]
final class BirthdayMailTest extends UnitTestCase {

  /**
   * In-memory State.
   */
  private array $store = [];

  /**
   * Recipients of mail sent in a test.
   */
  private array $sentTo = [];

  /**
   * The birthday email at a given moment.
   */
  private function mailer(string $now, array $settings): BirthdayMail {
    $this->store = [Birthday::STATE => $settings];
    $this->sentTo = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn ($key) => $this->store[$key] ?? NULL);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->store[$key] = $value;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn((new \DateTimeImmutable($now, new \DateTimeZone('America/Los_Angeles')))->getTimestamp());
    $birthday = new Birthday($state, $time, $this->createMock(CacheTagsInvalidatorInterface::class));

    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturnCallback(function ($uid) {
      $user = $this->createMock(UserInterface::class);
      $user->method('getEmail')->willReturn("user$uid@example.com");
      $user->method('getPreferredLangcode')->willReturn('en');
      return $user;
    });
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($users);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params) {
      $this->sentTo[] = [$key, $to, $params['subject']];
      return ['result' => TRUE];
    });
    return new BirthdayMail($birthday, $mail, $etm, $this->createMock(LoggerInterface::class));
  }

  /**
   * Approved text, on the day.
   */
  private const READY = [
    'date' => '10-19',
    'mail_subject' => 'Happy birthday',
    'mail_body' => 'Text',
    'mail_approved' => TRUE,
  ];

  /**
   * On the day, approved: sent once, to the owner only.
   */
  public function testSendsOnceOnTheDay(): void {
    $mailer = $this->mailer('2026-10-19 07:00', self::READY);
    $this->assertSame('Sent the birthday email to the owner.', $mailer->sendIfDue());
    $this->assertSame([['birthday', 'user3@example.com', 'Happy birthday']], $this->sentTo);
    $this->assertSame('Not sent: already sent today.', $mailer->sendIfDue());
    $this->assertCount(1, $this->sentTo);
  }

  /**
   * Nothing goes out off the day, unapproved, without text, or switched off.
   */
  public function testBlockers(): void {
    $this->assertSame('today is not the birthday', $this->mailer('2026-10-18 07:00', self::READY)->blocker());
    $this->assertSame('the text is not approved yet', $this->mailer('2026-10-19 07:00', ['mail_approved' => FALSE] + self::READY)->blocker());
    $this->assertSame('no email text yet', $this->mailer('2026-10-19 07:00', ['mail_body' => ' '] + self::READY)->blocker());
    $this->assertSame('birthday mode is switched off', $this->mailer('2026-10-19 07:00', ['mode' => 'off'] + self::READY)->blocker());
    $mailer = $this->mailer('2026-10-18 07:00', self::READY);
    $mailer->sendIfDue();
    $this->assertSame([], $this->sentTo);
  }

  /**
   * Tests go to webmaster only, and only webmaster can send them.
   */
  public function testTestGoesToWebmasterOnly(): void {
    $mailer = $this->mailer('2026-10-15 12:00', ['mail_approved' => FALSE] + self::READY);
    $foreman = $this->createMock(AccountInterface::class);
    $foreman->method('id')->willReturn(2);
    $this->assertFalse($mailer->sendTest($foreman));
    $webmaster = $this->createMock(AccountInterface::class);
    $webmaster->method('id')->willReturn(1);
    $this->assertTrue($mailer->sendTest($webmaster));
    $this->assertSame([['birthday_test', 'user1@example.com', '[TEST] Happy birthday']], $this->sentTo);
  }

}
