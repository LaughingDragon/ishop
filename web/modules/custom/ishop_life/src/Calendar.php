<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * The Calendar wall's dates (Phase 5 step 3): events and bills.
 *
 * Built in (Liam, decision 197): repeats are worked out here, with the same
 * date rules as bills. Game days come from the Season (ishop_matrix), which
 * merges them in on the page.
 */
final class Calendar {

  /**
   * Safety limit on repeats worked out for one event in one range.
   */
  private const MAX_OCCURRENCES = 400;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly Bills $bills,
  ) {}

  /**
   * The dates an event (or bill) falls on between $from and $to, inclusive.
   *
   * @return string[]
   *   Y-m-d dates, in order.
   */
  public static function expand(string $start, string $repeat, ?int $day, string $from, string $to): array {
    $dates = [];
    $date = $start;
    for ($i = 0; $i < self::MAX_OCCURRENCES && $date !== NULL && $date <= $to; $i++) {
      if ($date >= $from) {
        $dates[] = $date;
      }
      $date = Bills::nextDue($date, $repeat, $day);
    }
    return $dates;
  }

  /**
   * Adds an event.
   */
  public function addEvent(string $title, string $date, string $time, string $repeat, string $note): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'event',
      'title' => mb_substr($title, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
      'field_event_date' => $date,
      'field_event_time' => $time,
      'field_repeat' => $repeat,
      'field_due_day' => (int) substr($date, 8, 2),
      'field_log_note' => $note,
    ]);
    $node->setRevisionLogMessage('Added on the Calendar wall.');
    $node->save();
    return $node;
  }

  /**
   * Events and bills between two dates, by date then time.
   *
   * @return array<int, array{date: string, time: string, title: string, kind: string, url: ?string, edit: ?string}>
   *   One row per occurrence.
   */
  public function entries(string $from, string $to): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $rows = [];
    $events = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_date', $to, '<=')
      ->execute());
    foreach ($events as $event) {
      $day = $event->get('field_due_day')->value;
      foreach (self::expand((string) $event->get('field_event_date')->value, (string) $event->get('field_repeat')->value, $day ? (int) $day : NULL, $from, $to) as $date) {
        $rows[] = [
          'date' => $date,
          'time' => (string) $event->get('field_event_time')->value,
          'title' => $event->label(),
          'kind' => 'event',
          'url' => NULL,
          'edit' => $event->toUrl('edit-form')->toString(),
          'delete' => $event->toUrl('delete-form')->toString(),
        ];
      }
    }
    $bills_url = Url::fromRoute('ishop_life.bills')->toString();
    foreach ($this->bills->active() as $bill) {
      $day = $bill->get('field_due_day')->value;
      $amount = $bill->get('field_amount')->value;
      foreach (self::expand((string) $bill->get('field_due_date')->value, (string) $bill->get('field_repeat')->value, $day ? (int) $day : NULL, $from, $to) as $date) {
        $rows[] = [
          'date' => $date,
          'time' => '',
          'title' => $amount !== NULL && $amount !== '' ? sprintf('%s due ($%s)', $bill->label(), number_format((float) $amount, 2)) : sprintf('%s due', $bill->label()),
          'kind' => 'bill',
          'url' => $bills_url,
          'edit' => NULL,
          'delete' => NULL,
        ];
      }
    }
    return self::sort($rows);
  }

  /**
   * Sorts rows by date, then time (all-day first), then title.
   */
  public static function sort(array $rows): array {
    usort($rows, fn ($a, $b) => [$a['date'], $a['time'], $a['title']] <=> [$b['date'], $b['time'], $b['title']]);
    return $rows;
  }

}
