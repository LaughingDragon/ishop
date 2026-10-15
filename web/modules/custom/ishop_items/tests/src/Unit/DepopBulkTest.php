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
   * A row uses the Depop pack's text and Depop's own condition words.
   */
  public function testRow(): void {
    $listing = [
      'title' => 'Gap navy tee',
      'description' => 'Soft cotton, "barely" worn, size M.',
      'brand' => 'Gap',
      'size' => 'M',
      'color' => 'Navy',
      'condition' => 'Good',
      'category' => "Men's Clothing > T-Shirts",
      'price' => 12.5,
    ];
    $row = DepopBulk::row($listing, '01-item-7')['row'];
    $this->assertSame(PostPack::build('depop', $listing)['fields'][0]['value'], $row['description']);
    $this->assertSame('12.50', $row['price']);
    $this->assertSame("Men's Clothing", $row['category']);
    $this->assertSame('T-Shirts', $row['subcategory']);
    $this->assertSame('Used - Good', $row['condition']);
    $this->assertSame('Navy', $row['colour']);
    $this->assertSame('1', $row['quantity']);
    $this->assertSame('01-item-7', $row['photo_folder']);
    $this->assertSame(DepopBulk::COLUMNS, array_keys($row));
  }

  /**
   * Flat categories have no subcategory; unknown conditions say Used - Good.
   */
  public function testFlatCategoryAndDefaults(): void {
    $row = DepopBulk::row(['title' => 'Lamp', 'category' => 'Home & Kitchen', 'price' => 5.0], 'f')['row'];
    $this->assertSame('Home & Kitchen', $row['category']);
    $this->assertSame('', $row['subcategory']);
    $this->assertSame('Used - Good', $row['condition']);
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
    $row = DepopBulk::row($listing, '01-item-1')['row'];
    $csv = DepopBulk::csv([$row]);
    $lines = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    while (($line = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      $lines[] = $line;
    }
    $this->assertSame(DepopBulk::COLUMNS, $lines[0]);
    $this->assertSame($row['description'], $lines[1][0]);
    $this->assertCount(2, $lines);
  }

}
