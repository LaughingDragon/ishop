<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Reads Amazon's order history export (Phase 6).
 *
 * The file comes from Amazon's "Request your data" > "Your Orders": a zip
 * holding Retail.OrderHistory.N.csv (one line per product per shipment).
 * Columns are found by name, so a reordered or extended file still reads.
 * Only what the import needs is kept; digital orders (in their own files,
 * or marked digital) and cancelled lines are left out (decision 241).
 */
final class AmazonOrders {

  /**
   * Column names, with the other names Amazon has used for them.
   */
  public const COLUMNS = [
    'order_id' => ['order id'],
    'date' => ['order date'],
    'price' => ['unit price'],
    'tax' => ['unit price tax'],
    'quantity' => ['quantity', 'original quantity'],
    'asin' => ['asin'],
    'name' => ['product name'],
    'status' => ['order status'],
    'website' => ['website'],
    'shipping' => ['shipping option'],
    'condition' => ['product condition'],
  ];

  /**
   * Columns a file must have to be read as an order history.
   */
  public const REQUIRED = ['order_id', 'date', 'price', 'name'];

  /**
   * The purchases in an uploaded zip or CSV.
   *
   * @param string $path
   *   The uploaded file.
   * @param string $zone
   *   Time zone for order dates (Amazon gives UTC times).
   *
   * @return array{rows: array<string, array>, skipped: array{cancelled: int, digital: int}}
   *   Purchases keyed "order ID|ASIN" (or name when there's no ASIN), with
   *   order_id, date (Y-m-d), name, asin, price, tax, quantity.
   *
   * @throws \InvalidArgumentException
   *   When the file isn't an order history.
   */
  public static function read(string $path, string $zone = 'America/Los_Angeles'): array {
    $csvs = [];
    $head = (string) file_get_contents($path, FALSE, NULL, 0, 4);
    if (str_starts_with($head, "PK\x03\x04")) {
      self::csvsInZip($path, $csvs, 0);
    }
    else {
      $csvs[] = (string) file_get_contents($path);
    }
    if (!$csvs) {
      throw new \InvalidArgumentException('No order history was found in that file. Upload the "Your Orders" zip from Amazon, or the Retail.OrderHistory CSV inside it.');
    }
    $result = ['rows' => [], 'skipped' => ['cancelled' => 0, 'digital' => 0]];
    foreach ($csvs as $csv) {
      self::parse($csv, $zone, $result);
    }
    return $result;
  }

  /**
   * Collects order history CSVs from a zip (and zips inside it, once).
   */
  private static function csvsInZip(string $path, array &$csvs, int $depth): void {
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::RDONLY) !== TRUE) {
      throw new \InvalidArgumentException('That zip file could not be opened.');
    }
    try {
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $base = basename($name);
        if (preg_match('/OrderHistory.*\.csv$/i', $base) && !preg_match('/digital/i', $name)) {
          $csvs[] = (string) $zip->getFromIndex($i);
        }
        elseif ($depth === 0 && preg_match('/\.zip$/i', $base) && !preg_match('/digital/i', $name)) {
          $inner = tempnam(sys_get_temp_dir(), 'ishop-amazon-');
          try {
            file_put_contents($inner, (string) $zip->getFromIndex($i));
            self::csvsInZip($inner, $csvs, 1);
          }
          finally {
            @unlink($inner);
          }
        }
      }
    }
    finally {
      $zip->close();
    }
  }

  /**
   * Adds one CSV's purchases to the result.
   */
  public static function parse(string $csv, string $zone, array &$result): void {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $header = fgetcsv($handle, NULL, ',', '"', '');
    $index = [];
    foreach ((array) $header as $i => $title) {
      $title = strtolower(trim((string) $title));
      foreach (self::COLUMNS as $key => $names) {
        if (in_array($title, $names, TRUE) && !isset($index[$key])) {
          $index[$key] = $i;
        }
      }
    }
    if (array_diff(self::REQUIRED, array_keys($index))) {
      fclose($handle);
      throw new \InvalidArgumentException('That file doesn\'t look like an Amazon order history (no Order ID, Order Date, Unit Price and Product Name columns).');
    }
    while (($line = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      if ($line === [NULL]) {
        continue;
      }
      $get = fn (string $key) => isset($index[$key]) ? self::clean($line[$index[$key]] ?? '') : '';
      $order_id = $get('order_id');
      $name = $get('name');
      if ($order_id === '' || $name === '') {
        continue;
      }
      if (self::isDigital($order_id, $get('website'), $get('shipping'), $get('condition'))) {
        $result['skipped']['digital']++;
        continue;
      }
      if (preg_match('/cancel/i', $get('status'))) {
        $result['skipped']['cancelled']++;
        continue;
      }
      $date = self::date($get('date'), $zone);
      if ($date === NULL) {
        continue;
      }
      $asin = strtoupper($get('asin'));
      $key = $order_id . '|' . ($asin !== '' ? $asin : mb_strtolower($name));
      $quantity = max(1, (int) self::number($get('quantity')) ?: 1);
      if (isset($result['rows'][$key])) {
        // The same product in two shipments of one order.
        $result['rows'][$key]['quantity'] += $quantity;
        continue;
      }
      $result['rows'][$key] = [
        'order_id' => $order_id,
        'date' => $date,
        'name' => $name,
        'asin' => $asin,
        'price' => self::number($get('price')),
        'tax' => self::number($get('tax')),
        'quantity' => $quantity,
      ];
    }
    fclose($handle);
  }

  /**
   * Whether a line is a digital order (Kindle, video, apps, music).
   */
  public static function isDigital(string $order_id, string ...$fields): bool {
    if (preg_match('/^D\d{2}-/i', $order_id)) {
      return TRUE;
    }
    foreach ($fields as $field) {
      if (preg_match('/digital|kindle|download/i', $field)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * An order date as Y-m-d in the given zone, or NULL.
   *
   * Amazon gives "2026-10-05T21:13:44Z" (UTC); older files "10/05/2026".
   */
  public static function date(string $value, string $zone): ?string {
    if ($value === '') {
      return NULL;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
      return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    }
    try {
      $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }
    return $date->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
  }

  /**
   * A money or count value as a number ("$1,234.50", "Not Available").
   */
  public static function number(string $value): float {
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    return is_numeric($value) ? (float) $value : 0.0;
  }

  /**
   * Trims a cell; Amazon writes "Not Available" for blanks.
   */
  private static function clean(string $value): string {
    $value = trim($value);
    return in_array(strtolower($value), ['not available', 'not applicable', 'n/a'], TRUE) ? '' : $value;
  }

  /**
   * A product name cut to fit a title, at a word if it can.
   */
  public static function title(string $name, int $max = 80): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if (mb_strlen($name) <= $max) {
      return $name;
    }
    $cut = mb_substr($name, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== FALSE && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,-–|');
  }

}
