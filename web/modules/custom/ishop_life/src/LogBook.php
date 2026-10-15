<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * The mileage and expense log (Phase 5 step 1).
 *
 * Entries are nodes (types mileage and expense) so they get revisions and the
 * site's access rules; Cody adds them from My office, not the admin forms.
 * Miles are recorded as driven: the site never works out a deduction.
 */
final class LogBook {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Today in the site's (Pacific) time zone, Y-m-d.
   */
  public static function today(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
  }

  /**
   * A short title for a log entry, e.g. "2026-10-15 Post office, 6.2 mi".
   */
  public static function title(string $type, array $values): string {
    if ($type === 'mileage') {
      return sprintf('%s %s, %s mi', $values['date'], $values['trip'], rtrim(rtrim(number_format((float) $values['miles'], 1, '.', ''), '0'), '.'));
    }
    return sprintf('%s %s, $%s', $values['date'], $values['what'], number_format((float) $values['amount'], 2));
  }

  /**
   * Logs a trip.
   */
  public function addMileage(string $date, string $trip, float $miles, string $note): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'mileage',
      'title' => self::title('mileage', ['date' => $date, 'trip' => $trip, 'miles' => $miles]),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
      'field_log_date' => $date,
      'field_trip' => $trip,
      'field_miles' => $miles,
      'field_log_note' => $note,
    ]);
    $node->setRevisionLogMessage('Logged in My office.');
    $node->save();
    return $node;
  }

  /**
   * Logs an expense. The title is what it was for.
   */
  public function addExpense(string $date, string $what, float $amount, string $category, ?int $receipt_fid, string $note): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'expense',
      'title' => mb_substr($what, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
      'field_log_date' => $date,
      'field_amount' => $amount,
      'field_expense_category' => $category,
      'field_receipt' => $receipt_fid ? ['target_id' => $receipt_fid] : NULL,
      'field_log_note' => $note,
    ]);
    $node->setRevisionLogMessage('Logged in My office.');
    $node->save();
    return $node;
  }

  /**
   * The latest trips, newest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Mileage nodes.
   */
  public function recent(string $type, int $limit = 10): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', $type)
      ->sort('field_log_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Distinct trip names used before, most recent first (for suggestions).
   *
   * @return string[]
   *   Up to $limit names.
   */
  public function tripNames(int $limit = 12): array {
    $names = [];
    foreach ($this->recent('mileage', 50) as $node) {
      $names[(string) $node->get('field_trip')->value] = TRUE;
      if (count($names) >= $limit) {
        break;
      }
    }
    return array_keys($names);
  }

  /**
   * Entries of one type dated within a calendar year, oldest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Nodes.
   */
  public function forYear(string $type, int $year): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', $type)
      ->condition('field_log_date', "$year-01-01", '>=')
      ->condition('field_log_date', "$year-12-31", '<=')
      ->sort('field_log_date')
      ->sort('nid')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Mileage rows for the year-end export, with a total.
   */
  public function mileageRows(int $year): array {
    $rows = [];
    $total = 0.0;
    foreach ($this->forYear('mileage', $year) as $node) {
      $miles = (float) $node->get('field_miles')->value;
      $total += $miles;
      $rows[] = [
        'Date' => (string) $node->get('field_log_date')->value,
        'Trip' => (string) $node->get('field_trip')->value,
        'Miles' => number_format($miles, 1, '.', ''),
        'Note' => (string) $node->get('field_log_note')->value,
      ];
    }
    $rows[] = [
      'Date' => 'TOTAL',
      'Trip' => sprintf('%d trips', count($rows)),
      'Miles' => number_format($total, 1, '.', ''),
      'Note' => '',
    ];
    return $rows;
  }

  /**
   * Expense rows for the year-end export, with totals per category.
   */
  public function expenseRows(int $year): array {
    $rows = [];
    $by_category = [];
    $total = 0.0;
    foreach ($this->forYear('expense', $year) as $node) {
      $amount = (float) $node->get('field_amount')->value;
      $category = (string) $node->get('field_expense_category')->value;
      $total += $amount;
      $by_category[$category] = ($by_category[$category] ?? 0.0) + $amount;
      $rows[] = [
        'Date' => (string) $node->get('field_log_date')->value,
        'What' => $node->label(),
        'Category' => LifeStructure::CATEGORIES[$category] ?? $category,
        'Amount' => number_format($amount, 2, '.', ''),
        'Receipt' => $node->get('field_receipt')->isEmpty() ? 'no' : 'yes',
        'Note' => (string) $node->get('field_log_note')->value,
      ];
    }
    foreach (LifeStructure::CATEGORIES as $key => $label) {
      if (isset($by_category[$key])) {
        $rows[] = [
          'Date' => 'TOTAL',
          'What' => $label,
          'Category' => $label,
          'Amount' => number_format($by_category[$key], 2, '.', ''),
          'Receipt' => '',
          'Note' => '',
        ];
      }
    }
    $rows[] = [
      'Date' => 'TOTAL',
      'What' => 'All expenses',
      'Category' => '',
      'Amount' => number_format($total, 2, '.', ''),
      'Receipt' => '',
      'Note' => '',
    ];
    return $rows;
  }

}
