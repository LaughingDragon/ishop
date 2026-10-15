<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The year-end tax export (Phase 4): sales and the donation log, as CSV.
 *
 * Real data only. Missing sale prices, costs and donation values are left
 * empty and flagged in a "Check" column, never estimated (decision 178).
 * Mileage and expenses join in Phase 5.
 */
final class TaxExport {

  public const SALES_COLUMNS = [
    'Date sold',
    'Item',
    'Platform',
    'Sale price',
    'Item cost',
    'Platform fees',
    'Shipping paid',
    'Profit',
    'Check',
  ];

  public const DONATION_COLUMNS = [
    'Date donated',
    'Item',
    'Category',
    'Condition',
    'Estimated value',
    'Item cost',
    'Check',
  ];

  public function __construct(
    private readonly Money $money,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Sales rows for a calendar year, plus a totals row.
   *
   * @return array<int, array<string, string>>
   *   Rows keyed by SALES_COLUMNS.
   */
  public function salesRows(int $year): array {
    $rows = [];
    $sales = $this->money->sales("$year-01-01", "$year-12-31");
    foreach ($sales as $sale) {
      $rows[] = self::saleRow($sale['date_sold'], $sale['item']->label(), Platforms::PLATFORMS[$sale['platform']], $sale);
    }
    $sum = Money::summarize($sales);
    $rows[] = [
      'Date sold' => 'TOTAL',
      'Item' => sprintf('%d sales', $sum['count']),
      'Platform' => '',
      'Sale price' => self::amount($sum['revenue']),
      'Item cost' => self::amount($sum['cost']),
      'Platform fees' => self::amount($sum['fees']),
      'Shipping paid' => self::amount($sum['shipping']),
      'Profit' => self::amount($sum['profit']),
      'Check' => $sum['missing_price'] || $sum['missing_cost'] ? 'Some sales are missing a price or cost: see above' : '',
    ];
    return $rows;
  }

  /**
   * One sale as a CSV row.
   */
  public static function saleRow(string $date, string $title, string $platform, array $sale): array {
    $check = [];
    if ($sale['price'] === NULL) {
      $check[] = 'no sale price entered';
    }
    if ($sale['cost'] === NULL) {
      $check[] = 'no item cost entered';
    }
    return [
      'Date sold' => $date,
      'Item' => $title,
      'Platform' => $platform,
      'Sale price' => self::amount($sale['price']),
      'Item cost' => self::amount($sale['cost']),
      'Platform fees' => self::amount($sale['fees']),
      'Shipping paid' => self::amount($sale['shipping']),
      'Profit' => self::amount(Money::profit($sale)),
      'Check' => implode('; ', $check),
    ];
  }

  /**
   * Donated items for a calendar year, by the date they became Donated.
   *
   * @return array<int, array<string, string>>
   *   Rows keyed by DONATION_COLUMNS, plus a totals row.
   */
  public function donationRows(int $year): array {
    $nodes = $this->entityTypeManager->getStorage('node');
    $ids = $nodes->getQuery()->accessCheck(FALSE)
      ->condition('type', 'item')
      ->condition('field_item_status', 'donated')
      ->execute();
    $rows = [];
    $total = 0.0;
    $missing = 0;
    foreach ($nodes->loadMultiple($ids) as $item) {
      $date = $this->donatedOn($item);
      if (!$date || (int) substr($date, 0, 4) !== $year) {
        continue;
      }
      $raw = $item->get('field_donation_value')->value;
      $value = $raw === NULL || $raw === '' ? NULL : (float) $raw;
      $cost = $item->get('field_cost_paid')->value;
      $total += $value ?? 0.0;
      $missing += $value === NULL ? 1 : 0;
      $rows[$date . '-' . $item->id()] = [
        'Date donated' => $date,
        'Item' => $item->label(),
        'Category' => (string) $item->get('field_category')->entity?->label(),
        'Condition' => (string) $item->get('field_condition')->entity?->label(),
        'Estimated value' => self::amount($value),
        'Item cost' => self::amount($cost === NULL || $cost === '' ? NULL : (float) $cost),
        'Check' => $value === NULL ? 'no estimated value entered' : '',
      ];
    }
    ksort($rows);
    $rows = array_values($rows);
    $rows[] = [
      'Date donated' => 'TOTAL',
      'Item' => sprintf('%d donated items', count($rows)),
      'Category' => '',
      'Condition' => '',
      'Estimated value' => self::amount($total),
      'Item cost' => '',
      'Check' => $missing ? sprintf('%d without an estimated value', $missing) : '',
    ];
    return $rows;
  }

  /**
   * When the item became Donated (Pacific date), from its history.
   *
   * The first revision with that status after one without it.
   */
  public function donatedOn(NodeInterface $item): ?string {
    $rows = $this->database->query(
      'SELECT r.revision_timestamp, s.field_item_status_value AS status
       FROM {node_revision} r
       LEFT JOIN {node_revision__field_item_status} s ON s.revision_id = r.vid
       WHERE r.nid = :nid ORDER BY r.vid',
      [':nid' => $item->id()],
    )->fetchAll();
    $when = NULL;
    foreach ($rows as $row) {
      if ($row->status === 'donated') {
        $when ??= (int) $row->revision_timestamp;
      }
      else {
        $when = NULL;
      }
    }
    return $when === NULL ? NULL : (new \DateTimeImmutable('@' . $when))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
  }

  /**
   * Years that have any sales or donations, newest first (always this year).
   *
   * @return int[]
   *   Years.
   */
  public function years(): array {
    $years = [(int) date('Y')];
    $sold = $this->database->query("SELECT DISTINCT SUBSTRING(date_sold, 1, 4) FROM {platform_listing} WHERE date_sold IS NOT NULL")->fetchCol();
    $donated = $this->database->query("SELECT DISTINCT FROM_UNIXTIME(changed, '%Y') FROM {node_field_data} n JOIN {node__field_item_status} s ON s.entity_id = n.nid WHERE s.field_item_status_value = 'donated'")->fetchCol();
    $years = array_unique(array_merge($years, array_map('intval', $sold), array_map('intval', $donated)));
    rsort($years);
    return $years;
  }

  /**
   * CSV text with the given header.
   */
  public static function csv(array $columns, array $rows): string {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, $columns, ',', '"', '');
    foreach ($rows as $row) {
      fputcsv($out, array_map(fn ($column) => $row[$column] ?? '', $columns), ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
  }

  /**
   * Dollars as a plain number for spreadsheets ("12.50"), or "" if unknown.
   */
  public static function amount(?float $value): string {
    return $value === NULL ? '' : number_format($value, 2, '.', '');
  }

}
