<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\DepopBulk;
use Drupal\ishop_items\PostPack;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Depop bulk-upload rows and CSV.
 */
#[CoversClass(DepopBulk::class)]
final class DepopBulkTest extends UnitTestCase {

  /**
   * A row follows Depop's template: its columns, its condition words, a SKU.
   */
  public function testRow(): void {
    $listing = [
      'title' => 'Gap navy tee',
      'description' => 'Soft cotton, "barely" worn, size M.',
      'brand' => 'Gap',
      'size' => 'M',
      'color' => 'Navy',
      'condition' => 'Good',
      'category' => "Men's Clothing",
      'price' => 12.5,
    ];
    $row = DepopBulk::row($listing, 'item-7')['row'];
    $this->assertSame(DepopBulk::COLUMNS, array_keys($row));
    $this->assertSame(PostPack::build('depop', $listing)['fields'][0]['value'], $row['Description']);
    $this->assertSame('12.50', $row['Price']);
    $this->assertSame("Men's Clothing", $row['Category']);
    $this->assertSame('Used - Good', $row['Condition']);
    $this->assertSame('Navy', $row['Color 1']);
    $this->assertSame('item-7', $row['SKU']);
    // Photos are private, so Depop's picture URL columns stay empty.
    $this->assertSame('', $row['Picture Hero url']);
    $this->assertSame('', $row['Domestic Shipping price']);
  }

  /**
   * Category paths use their last part; unknown conditions say Used - Good.
   */
  public function testCategoryAndDefaults(): void {
    $row = DepopBulk::row(['title' => 'Lamp', 'category' => 'Home & Kitchen > Lamps', 'price' => 5.0], 'item-1')['row'];
    $this->assertSame('Lamps', $row['Category']);
    $this->assertSame('Used - Good', $row['Condition']);
    $this->assertSame('item-9', DepopBulk::sku(9));
  }

  /**
   * No price (or no text) means it's left out, with the old app's reason.
   */
  public function testSkipped(): void {
    $skipped = ['row' => NULL, 'reason' => 'missing text or price'];
    $this->assertSame($skipped, DepopBulk::row(['title' => 'Lamp'], 'f'));
    $this->assertSame($skipped, DepopBulk::row(['title' => '', 'price' => 3.0], 'f'));
  }

  /**
   * The CSV round-trips quotes, commas and line breaks.
   */
  public function testCsvRoundTrip(): void {
    $listing = ['title' => 'Lamp, brass', 'description' => "Says \"hello\".\nWorks.", 'price' => 5.0];
    $row = DepopBulk::row($listing, 'item-1')['row'];
    $csv = DepopBulk::csv([$row]);
    $lines = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    while (($line = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      $lines[] = $line;
    }
    $this->assertSame(DepopBulk::COLUMNS, $lines[0]);
    $this->assertSame($row['Description'], $lines[1][0]);
    $this->assertCount(2, $lines);
  }

}
