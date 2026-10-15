<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Depop's bulk-upload CSV (Phase 3).
 *
 * Depop is the one platform with a sanctioned bulk upload: Selling Hub >
 * Drafts takes a spreadsheet based on its Google Sheet template, and each row
 * becomes a draft. Ported from the old crosslister's make_depop_csv.py.
 *
 * The columns are copied from Depop's own template (Cody pasted its header
 * row, item 153, 2026-10-15). Depop has changed them before: if an upload is
 * rejected, compare again and edit COLUMNS (unknown columns are left empty).
 *
 * Pictures: the template wants photo URLs Depop can fetch, but item photos
 * are private (behind the login), so those columns stay empty and the photos
 * come as a ZIP, one folder per item named like its SKU (item-<ID>).
 */
final class DepopBulk {

  /**
   * Header row, exactly as Depop's template has it (Cody, item 153).
   */
  public const COLUMNS = [
    'Description',
    'Category',
    'Price',
    'Brand',
    'Condition',
    'Size',
    'Color 1',
    'Color 2',
    'Source 1',
    'Source 2',
    'Age',
    'Style 1',
    'Style 2',
    'Style 3',
    'Location',
    'Picture Hero url',
    'Picture 2 url',
    'Picture 3 url',
    'Picture 4 url',
    'Picture 5 url',
    'Picture 6 url',
    'Picture 7 url',
    'Picture 8 url',
    'Domestic Shipping price',
    'International Shipping price',
    'SKU',
  ];

  /**
   * Depop's condition wording (differs from ours and from its paste form).
   */
  public const CONDITIONS = [
    'New with tags' => 'Brand new',
    'New without tags' => 'Brand new',
    'Like new' => 'Like new',
    'Good' => 'Used - Good',
    'Fair' => 'Used - Fair',
    'Poor' => 'Used - Fair',
  ];

  /**
   * One row, or why the item can't go in.
   *
   * @param array $listing
   *   Plain values, as for PostPack::build().
   * @param string $sku
   *   The item's SKU, also its folder name in the photos ZIP.
   *
   * @return array{row: ?array, reason: ?string}
   *   The row keyed by COLUMNS, or NULL and the reason it was skipped.
   */
  public static function row(array $listing, string $sku): array {
    $pack = PostPack::build('depop', $listing);
    $text = $pack['fields'][0]['value'];
    if ($text === '' || ($listing['price'] ?? NULL) === NULL) {
      return ['row' => NULL, 'reason' => 'missing text or price'];
    }
    $parts = array_values(array_filter(array_map('trim', explode('>', (string) ($listing['category'] ?? ''))), fn ($p) => $p !== ''));
    $values = [
      'Description' => $text,
      'Category' => $parts ? end($parts) : '',
      'Price' => sprintf('%.2f', $listing['price']),
      'Brand' => (string) ($listing['brand'] ?? ''),
      'Condition' => self::CONDITIONS[$listing['condition'] ?? ''] ?? 'Used - Good',
      'Size' => (string) ($listing['size'] ?? ''),
      'Color 1' => (string) ($listing['color'] ?? ''),
      'SKU' => $sku,
    ];
    return [
      'row' => array_map(fn ($column) => $values[$column] ?? '', array_combine(self::COLUMNS, self::COLUMNS)),
      'reason' => NULL,
    ];
  }

  /**
   * The CSV text: header plus rows, UTF-8, RFC 4180 quoting.
   */
  public static function csv(array $rows): string {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, self::COLUMNS, ',', '"', '');
    foreach ($rows as $row) {
      fputcsv($out, array_map(fn ($column) => $row[$column] ?? '', self::COLUMNS), ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
  }

  /**
   * An item's SKU on Depop, also its folder name in the photos ZIP.
   */
  public static function sku(int $nid): string {
    return 'item-' . $nid;
  }

}
