<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Bills and subscriptions (Phase 5 step 2).
 *
 * A bill shows in "Needs you now" from DAYS_AHEAD days before it's due (and
 * while overdue). "Paid" rolls a repeating bill to its next due date and
 * archives a one-off; a business bill is also logged as an expense.
 */
final class Bills {

  /**
   * Days before its due date that a bill shows in "Needs you now".
   */
  public const DAYS_AHEAD = 3;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LogBook $logBook,
  ) {}

  /**
   * The next due date after a payment.
   *
   * Monthly and yearly bills keep their day of the month ($day): a bill due
   * on the 31st is due on the 30th in April and the 31st again in May.
   *
   * @return string|null
   *   Y-m-d, or NULL for a one-off bill.
   */
  public static function nextDue(string $due, string $repeat, ?int $day = NULL): ?string {
    $date = new \DateTimeImmutable($due);
    $day ??= (int) $date->format('j');
    $on = function (int $year, int $month) use ($day): string {
      $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
      return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    };
    $year = (int) $date->format('Y');
    $month = (int) $date->format('n');
    return match ($repeat) {
      'weekly' => $date->modify('+7 days')->format('Y-m-d'),
      'monthly' => $month === 12 ? $on($year + 1, 1) : $on($year, $month + 1),
      'yearly' => $on($year + 1, $month),
      default => NULL,
    };
  }

  /**
   * Days from today until a date (negative when overdue).
   */
  public static function daysUntil(string $date, string $today): int {
    return (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($date))->format('%r%a');
  }

  /**
   * Adds a bill.
   */
  public function add(string $name, ?float $amount, string $due, string $repeat, bool $business, string $note): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'bill',
      'title' => mb_substr($name, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
      'field_amount' => $amount,
      'field_due_date' => $due,
      'field_due_day' => (int) substr($due, 8, 2),
      'field_repeat' => $repeat,
      'field_bill_business' => $business,
      'field_log_note' => $note,
    ]);
    $node->setRevisionLogMessage('Added in My office.');
    $node->save();
    return $node;
  }

  /**
   * Active bills (not archived), soonest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Bill nodes.
   */
  public function active(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'bill')
      ->condition('status', 1)
      ->sort('field_due_date')
      ->sort('nid')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Bills due within DAYS_AHEAD days, or overdue: for "Needs you now".
   *
   * @return \Drupal\node\NodeInterface[]
   *   Bill nodes, soonest first.
   */
  public function due(): array {
    $today = LogBook::today();
    return array_filter($this->active(), fn ($bill) => self::daysUntil((string) $bill->get('field_due_date')->value, $today) <= self::DAYS_AHEAD);
  }

  /**
   * One line for people: "Phone, $45.00, due tomorrow".
   */
  public static function line(string $name, ?float $amount, string $due, string $today): string {
    $days = self::daysUntil($due, $today);
    $when = match (TRUE) {
      $days < -1 => sprintf('%d days overdue', -$days),
      $days === -1 => 'due yesterday',
      $days === 0 => 'due today',
      $days === 1 => 'due tomorrow',
      default => 'due ' . (new \DateTimeImmutable($due))->format('D M j'),
    };
    return $amount !== NULL ? sprintf('%s, $%s, %s', $name, number_format($amount, 2), $when) : sprintf('%s, %s', $name, $when);
  }

  /**
   * The line for a bill node.
   */
  public function lineFor(NodeInterface $bill): string {
    $amount = $bill->get('field_amount')->value;
    return self::line($bill->label(), $amount === NULL || $amount === '' ? NULL : (float) $amount, (string) $bill->get('field_due_date')->value, LogBook::today());
  }

  /**
   * Marks a bill paid today: next due date, or archived if it was a one-off.
   *
   * @return string
   *   What happened, for the message.
   */
  public function pay(NodeInterface $bill): string {
    $today = LogBook::today();
    $due = (string) $bill->get('field_due_date')->value;
    $day = $bill->get('field_due_day')->value;
    $next = self::nextDue($due, (string) $bill->get('field_repeat')->value, $day ? (int) $day : NULL);
    $bill->set('field_last_paid', $today);
    if ($next === NULL) {
      $bill->setUnpublished();
      $what = sprintf('%s paid and archived.', $bill->label());
    }
    else {
      $bill->set('field_due_date', $next);
      $what = sprintf('%s paid. Next due %s.', $bill->label(), (new \DateTimeImmutable($next))->format('D M j'));
    }
    $bill->setNewRevision(TRUE);
    $bill->setRevisionUserId((int) $this->currentUser->id());
    $bill->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $bill->setRevisionLogMessage(sprintf('Paid on %s (due %s).', $today, $due));
    $bill->save();
    $amount = $bill->get('field_amount')->value;
    if ($bill->get('field_bill_business')->value && $amount !== NULL && $amount !== '') {
      $this->logBook->addExpense($today, $bill->label(), (float) $amount, 'fees', NULL, 'Bill paid (logged automatically).');
      $what .= ' Logged in Expenses.';
    }
    return $what;
  }

}
