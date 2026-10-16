<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Drupal\ishop_items\Controller\StaleController;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\StaleNudges;
use Drupal\ishop_life\Bills;
use Drupal\ishop_life\LogBook;
use Drupal\ishop_life\Todos;
use Drupal\ishop_matrix\Controller\CalendarController;
use Drupal\ishop_matrix\Controller\MatrixController;
use Drupal\ishop_office\DiskStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The daily digest email (Phase 5 step 6).
 *
 * About 7 a.m. Pacific: what needs Cody, bills due, today's calendar,
 * to-dos, items waiting to be posted, and a storage check. Short plain text
 * for the phone. Sent to the owner (Cody) only after Liam's OK (STATE['cody']),
 * never on the owner's birthday (the birthday email replaces it), and the
 * owner can turn it off on the account page. Tests go to webmaster only.
 */
final class Digest {

  /**
   * State: ['cody' => bool (Liam's OK), 'sent' => Y-m-d of the last send].
   */
  public const STATE = 'ishop_matrix.digest';

  /**
   * User data key (module ishop_matrix) Cody sets to 1 to stop the digest.
   */
  public const USER_OFF = 'digest_off';

  public const OWNER_UID = 3;

  public const WEBMASTER_UID = 1;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ClassResolverInterface $classResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly UserDataInterface $userData,
    private readonly Birthday $birthday,
    private readonly StaleNudges $nudges,
    private readonly Bills $bills,
    private readonly Todos $todos,
    private readonly ListingManager $listings,
    private readonly AccountSwitcherInterface $accountSwitcher,
    #[Autowire(service: 'logger.channel.ishop_matrix')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The digest's settings.
   */
  public function settings(): array {
    return ($this->state->get(self::STATE) ?? []) + ['cody' => FALSE, 'sent' => ''];
  }

  /**
   * Turns Cody's daily digest on (after Liam's OK) or off.
   */
  public function setCody(bool $on): void {
    $settings = $this->settings();
    $settings['cody'] = $on;
    $this->state->set(self::STATE, $settings);
  }

  /**
   * Whether the owner turned the digest off on the account page.
   */
  public function codyOptedOut(): bool {
    return (bool) $this->userData->get('ishop_matrix', self::OWNER_UID, self::USER_OFF);
  }

  /**
   * Why Cody's digest would not go out now, or NULL if it should.
   */
  public function blocker(): ?string {
    $settings = $this->settings();
    return match (TRUE) {
      !$settings['cody'] => 'Cody\'s digest is not switched on yet (waiting for Liam\'s OK)',
      $this->codyOptedOut() => 'Cody turned the digest off on his account page',
      $this->birthday->isTheDay() => 'it is Cody\'s birthday (the birthday email replaces it)',
      $settings['sent'] === LogBook::today() => 'already sent today',
      default => NULL,
    };
  }

  /**
   * The email for one account, built as that account would see the site.
   *
   * The timer runs as the web server with no one signed in, so access checks
   * would hide everything: switch to the recipient while building.
   *
   * @return array{subject: string, body: string}
   *   Ready to send.
   */
  public function buildFor(int $uid): array {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    $this->accountSwitcher->switchTo($account);
    try {
      return $this->build();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * The email: subject and plain-text body, for the current account.
   *
   * @return array{subject: string, body: string}
   *   Ready to send.
   */
  private function build(): array {
    $today = LogBook::today();
    $now = $this->time->getCurrentTime();
    $lines = [];

    // What needs Cody: take-downs and return windows (as on the dashboard),
    // the most overdue stale nudges, bills due, to-dos due.
    $needs = [];
    foreach ($this->classResolver->getInstanceFromDefinition(MatrixController::class)->needs($now) as $row) {
      $needs[] = (string) $row['text'];
    }
    $stale = $this->nudges->due();
    foreach (array_slice($stale, 0, StaleNudges::DASHBOARD_LIMIT) as $row) {
      $needs[] = StaleController::text($row);
    }
    if (count($stale) > StaleNudges::DASHBOARD_LIMIT) {
      $needs[] = sprintf('%d more listings have been up 30+ days.', count($stale) - StaleNudges::DASHBOARD_LIMIT);
    }
    foreach ($this->bills->due() as $bill) {
      $needs[] = 'Bill: ' . $this->bills->lineFor($bill);
    }
    foreach ($this->todos->dueNow() as $todo) {
      $needs[] = 'To-do: ' . $todo->label();
    }
    $lines[] = 'NEEDS YOU';
    $lines = array_merge($lines, $needs ? array_map(fn ($line) => '- ' . $line, $needs) : ['Nothing needs you today.']);

    // Today's calendar: events and game days (bills and to-dos are above).
    $calendar = array_filter(
      $this->classResolver->getInstanceFromDefinition(CalendarController::class)->entries($today, $today),
      fn ($row) => in_array($row['kind'], ['event', 'game', 'doc'], TRUE),
    );
    $lines[] = '';
    $lines[] = 'TODAY';
    $lines = array_merge($lines, $calendar
      ? array_map(fn ($row) => '- ' . ($row['time'] !== '' ? $row['time'] . ' ' : '') . $row['title'], $calendar)
      : ['Nothing on the calendar.']);

    // Items waiting to be posted, by platform.
    $counts = [];
    $items = [];
    foreach (array_keys(Platforms::PLATFORMS) as $platform) {
      $queue = $this->listings->toPostQueue($platform);
      if ($queue) {
        $counts[] = sprintf('%s %d', Platforms::PLATFORMS[$platform], count($queue));
        $items += $queue;
      }
    }
    $lines[] = '';
    $lines[] = 'TO POST';
    $lines[] = $items
      ? sprintf('%d %s waiting to be posted (%s).', count($items), count($items) === 1 ? 'item is' : 'items are', implode(', ', $counts))
      : 'Nothing waiting to be posted.';

    // Storage check.
    $problems = DiskStatus::problems(DiskStatus::report());
    $lines[] = '';
    $lines[] = 'STORAGE';
    $lines = array_merge($lines, $problems ? array_map(fn ($p) => '- ' . $p, $problems) : ['All good: photos and backups are online, with room to spare.']);

    $site = Url::fromRoute('ishop_matrix.dashboard', [], ['absolute' => TRUE])->toString();
    $account = Url::fromRoute('user.page', [], ['absolute' => TRUE])->toString();
    $lines[] = '';
    $lines[] = 'Open the Reseller Matrix: ' . $site;
    $lines[] = 'To stop this email, untick "Send me the morning digest" on your account page: ' . $account;

    $date = (new \DateTimeImmutable($today))->format('l, M j');
    $count = count($needs);
    return [
      'subject' => $count ? sprintf('Morning digest, %s: %d %s you', $date, $count, $count === 1 ? 'thing needs' : 'things need') : sprintf('Morning digest, %s: all clear', $date),
      'body' => implode("\n", $lines),
    ];
  }

  /**
   * Sends today's digest to Cody if everything is in place.
   */
  public function sendIfDue(): string {
    if ($why = $this->blocker()) {
      return 'Not sent: ' . $why . '.';
    }
    if (!$this->send(self::OWNER_UID, FALSE)) {
      return 'Sending failed: see the mail status on the site office board.';
    }
    $settings = $this->settings();
    $settings['sent'] = LogBook::today();
    $this->state->set(self::STATE, $settings);
    return 'Sent the morning digest to the owner.';
  }

  /**
   * Sends a test to webmaster only.
   */
  public function sendTest(): bool {
    return $this->send(self::WEBMASTER_UID, TRUE);
  }

  /**
   * Sends to one account. The address is never logged.
   */
  private function send(int $uid, bool $test): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    $to = $account?->getEmail();
    if (!$to) {
      $this->logger->error('Digest: account @uid has no email address.', ['@uid' => $uid]);
      return FALSE;
    }
    $mail = $this->buildFor($uid);
    $result = $this->mailManager->mail('ishop_matrix', $test ? 'digest_test' : 'digest', $to, $account->getPreferredLangcode(), [
      'subject' => ($test ? '[TEST] ' : '') . $mail['subject'],
      'body' => $mail['body'],
    ]);
    $ok = !empty($result['result']);
    $this->logger->notice('Digest @kind to account @uid: @result.', [
      '@kind' => $test ? 'test' : '',
      '@uid' => $uid,
      '@result' => $ok ? 'sent' : 'failed',
    ]);
    return $ok;
  }

}
