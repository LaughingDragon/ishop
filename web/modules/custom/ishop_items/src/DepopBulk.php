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
 * The columns are the old app's "documented bulk columns as of 2026-08".
 * Depop has changed them before: check them against the header row of
 * Depop's own template and edit COLUMNS if they differ (the rest keys off it).
 */
final class DepopBulk {

  /**
   * Header row, in order.
   */
  public const COLUMNS = [
    'description',
    'price',
    'category',
    'subcategory',
    'brand',
    'size',
    'condition',
    'colour',
    'quantity',
    'photo_folder',
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
   * @param string $folder
   *   The item's folder name in the photos ZIP.
   *
   * @return array{row: ?array, reason: ?string}
   *   The row keyed by COLUMNS, or NULL and the reason it was skipped.
   */
  public static function row(array $listing, string $folder): array {
    $pack = PostPack::build('depop', $listing);
    $text = $pack['fields'][0]['value'];
    if ($text === '' || ($listing['price'] ?? NULL) === NULL) {
      return ['row' => NULL, 'reason' => 'missing text or price'];
    }
    $parts = array_values(array_filter(array_map('trim', explode('>', (string) ($listing['category'] ?? ''))), fn ($p) => $p !== ''));
    return [
      'row' => [
        'description' => $text,
        'price' => sprintf('%.2f', $listing['price']),
        'category' => $parts[0] ?? '',
        'subcategory' => count($parts) > 1 ? end($parts) : '',
        'brand' => (string) ($listing['brand'] ?? ''),
        'size' => (string) ($listing['size'] ?? ''),
        'condition' => self::CONDITIONS[$listing['condition'] ?? ''] ?? 'Used - Good',
        'colour' => (string) ($listing['color'] ?? ''),
        'quantity' => '1',
        'photo_folder' => $folder,
      ],
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
   * An item's folder name in the photos ZIP: row number and item ID.
   */
  public static function folder(int $row, int $nid): string {
    return sprintf('%02d-item-%d', $row, $nid);
  }

}
