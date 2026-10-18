<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\ListingImport\Columns;
use Drupal\ishop_items\ListingImport\ImportPlan;
use Drupal\ishop_items\ListingImport\SheetReader;
use Drupal\ishop_items\ListingImport\ValueGuess;
use Drupal\ishop_items\ListingImport\Values;
use Drupal\ishop_items\Platforms;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the listings import (Phase 10): values, columns, plan.
 */
#[CoversClass(Values::class)]
#[CoversClass(Columns::class)]
#[CoversClass(ImportPlan::class)]
#[CoversClass(ValueGuess::class)]
#[CoversClass(SheetReader::class)]
final class ListingImportTest extends UnitTestCase {

  /**
   * Cody's sheet's headings (Items tab).
   */
  private const CODY = [
    'Crosslister #',
    'Title',
    'Brand',
    'Size',
    'Condition',
    'Type',
    'Photos',
    'List price',
    'Poshmark',
    'Mercari',
    'Depop',
    'Grailed',
    'Vinted',
    'Facebook',
    'SOLD?',
    'Sold for',
    'Sold on',
    'Date sold',
    'Notes',
  ];

  /**
   * Prices, dates, quantities as people type them.
   */
  public function testValues(): void {
    $this->assertSame(1234.5, Values::money('$1,234.50'));
    $this->assertSame(12.0, Values::money(12));
    $this->assertNull(Values::money('twelve'));
    $this->assertNull(Values::money(''));
    $this->assertSame('2026-10-05', Values::date('10/5/26'));
    $this->assertSame('2026-10-05', Values::date('2026-10-05'));
    $this->assertSame('2026-10-05', Values::date('Oct 5, 2026'));
    $this->assertSame('2026-09-07', Values::date(new \DateTimeImmutable('2026-09-07')));
    $this->assertSame('2026-10-05', Values::date(46300));
    $this->assertNull(Values::date('2/30/26'));
    $this->assertNull(Values::date('Mercari'));
    $this->assertSame(3, Values::quantity('3'));
    $this->assertNull(Values::quantity('0'));
    $this->assertSame('Hat', Values::text("  Hat \n "));
    $this->assertSame('8', Values::text(8.0));
  }

  /**
   * Platform names and the site's own listing status words.
   */
  public function testPlatformsAndStatuses(): void {
    $this->assertSame(['platforms' => ['poshmark', 'mercari'], 'unknown' => []], Values::platforms('Posh, Merc'));
    $this->assertSame(['platforms' => ['ebay', 'facebook'], 'unknown' => ['etsy']], Values::platforms('eBay / FB Marketplace / Etsy'));
    $this->assertSame(Platforms::POSTED, Values::listingStatus('Posted'));
    $this->assertSame(Platforms::TO_POST, Values::listingStatus('TO POST'));
    $this->assertSame(Platforms::SOLD_HERE, Values::listingStatus('SOLD HERE'));
    $this->assertSame(Platforms::NOT_NEEDED_SOLD, Values::listingStatus('Not needed - sold'));
    $this->assertSame(Platforms::NOT_NEEDED_SOLD, Values::listingStatus('Not needed – sold'));
    $this->assertSame(Platforms::NOT_ALLOWED, Values::listingStatus('not allowed'));
    $this->assertSame(Platforms::POSTED, Values::listingStatus('x'));
    $this->assertSame(Platforms::POSTED, Values::listingStatus('https://poshmark.com/listing/a'));
    $this->assertNull(Values::listingStatus(''));
    $this->assertNull(Values::listingStatus('no'));
    $this->assertSame('?', Values::listingStatus('Maybe?'));
    $this->assertSame(Values::STATUS_SOLD, Values::status('Sold 10/5'));
    $this->assertSame(Values::STATUS_ENDED, Values::status('Inactive'));
    $this->assertSame('', Values::status(''));
    $this->assertNull(Values::status('hmm'));
    $this->assertTrue(Values::isYes('yes'));
    $this->assertFalse(Values::isYes(''));
  }

  /**
   * Cody's headings are all recognised.
   */
  public function testGuessCodysColumns(): void {
    $samples = [
      [
        8,
        'Yoda backpack',
        '',
        '',
        'Good',
        'Toy',
        4,
        38,
        'Not needed - sold',
        'SOLD HERE',
        'Posted',
        'Not allowed',
        'TO POST',
        'Not allowed',
        'Yes',
        33.46,
        'Mercari',
        '',
        '',
      ],
    ];
    $guess = Columns::guess(self::CODY, $samples);
    $this->assertSame([
      'sku', 'title', 'brand', 'size', 'condition', 'category', '', 'price',
      'on:poshmark', 'on:mercari', 'on:depop', 'on:grailed', 'on:vinted', 'on:facebook',
      'sold_flag', 'sold_price', 'sold_on', 'date_sold', 'notes',
    ], array_values($guess));
  }

  /**
   * Generic headings, buyer columns, per-platform links, remembered matches.
   */
  public function testGuessGenericColumns(): void {
    $headers = [
      'Item',
      'Platform',
      'Link',
      'Price',
      'Status',
      'Qty',
      'Bin',
      'Buyer name',
      'Ship to address',
      'Shipping cost',
      'Posh link',
      'Mercari price',
    ];
    $guess = Columns::guess($headers, []);
    $this->assertSame([
      'title',
      'platform',
      'link',
      'price',
      'status',
      'quantity',
      'location',
      '',
      '',
      '',
      'link:poshmark',
      'price:mercari',
    ], array_values($guess));
    $this->assertTrue(Columns::isPrivate('Buyer name'));
    $this->assertTrue(Columns::isPrivate('Ship to address'));
    $this->assertFalse(Columns::isPrivate('Title'));
    // A remembered match wins; buyer columns stay ignored even if remembered.
    $guess = Columns::guess(['Thing', 'Buyer'], [], ['thing' => 'title', 'buyer' => 'notes']);
    $this->assertSame(['title', ''], array_values($guess));
    // An unlabelled column of links is the listing link.
    $guess = Columns::guess(['Name', 'Column B'], [['Hat', 'https://poshmark.com/listing/x']]);
    $this->assertSame(['title', 'link'], array_values($guess));
  }

  /**
   * Category guesses for Cody's "Type" values.
   */
  public function testValueGuess(): void {
    $terms = [
      7 => "Men's Clothing",
      8 => "Women's Clothing",
      9 => 'Shoes',
      10 => 'Jewelry & Accessories',
      11 => 'Toys & Hobbies',
      12 => 'Collectibles',
      13 => 'Home & Kitchen',
      14 => 'Electronics',
      15 => 'Sporting Goods',
      16 => 'Health & Beauty',
      17 => 'Office & Craft',
      18 => 'Automotive',
      19 => 'Other',
    ];
    $this->assertSame(7, ValueGuess::guess("Men's clothing / shoes / accessories", $terms));
    $this->assertSame(8, ValueGuess::guess("Women's clothing / shoes / accessories", $terms));
    $this->assertSame(10, ValueGuess::guess('Unisex clothing / accessory / jewellery', $terms));
    $this->assertSame(13, ValueGuess::guess('Home goods', $terms));
    $this->assertSame(14, ValueGuess::guess('Electronic device', $terms));
    $this->assertSame(15, ValueGuess::guess('Sporting / outdoor gear', $terms));
    $this->assertSame(16, ValueGuess::guess('Beauty / personal care', $terms));
    $this->assertSame(17, ValueGuess::guess('Office / desk / craft', $terms));
    $this->assertSame(18, ValueGuess::guess('Automotive accessory', $terms));
    $this->assertNull(ValueGuess::guess('Bulk / industrial supplies', $terms));
    $this->assertSame(12, ValueGuess::guess('collectibles', $terms));
  }

  /**
   * The header row is found under a title line; row numbers are kept.
   */
  public function testTable(): void {
    $table = SheetReader::table([
      1 => ['My inventory'],
      2 => [],
      3 => ['Title', 'Price', ''],
      4 => ['Hat', 20, 'x'],
      5 => [],
    ]);
    $this->assertSame(3, $table['header_row']);
    $this->assertSame(['Title', 'Price', 'Column C'], $table['headers']);
    $this->assertSame([4, 5], array_column($table['rows'], 'row'));
    $this->assertNull(SheetReader::table([1 => ['Just one heading']]));
    $this->assertSame('AA', SheetReader::letter(26));
  }

  /**
   * A row in Cody's format: posted on some, sold on one, cascade shown.
   */
  public function testPlanCodyRows(): void {
    $mapping = Columns::guess(self::CODY, []);
    $rows = [
      [
        'row' => 2,
        'cells' => [
          10,
          'Hurley Hat  ',
          'Hurley',
          'L-XL',
          'New without tags',
          'Men',
          2,
          22,
          'Posted',
          'Posted',
          'TO POST',
          'Not allowed',
          'Posted',
          'Not allowed',
          '',
          '',
          '',
          '',
          'ACTION FOR CODY: measure it',
        ],
      ],
      [
        'row' => 3,
        'cells' => [
          8,
          'Yoda Backpack',
          '',
          '',
          'Good',
          'Toy',
          4,
          38,
          'Not needed - sold',
          'SOLD HERE',
          'Not needed - sold',
          'Not needed - sold',
          'Not needed - sold',
          'Not allowed',
          'Yes',
          33.46,
          'Mercari',
          '',
          '',
        ],
      ],
      [
        'row' => 4,
        'cells' => [
          11,
          'Lamp',
          '',
          '',
          'Good',
          'Home',
          2,
          30,
          'Posted',
          'Not needed - sold',
          'TO POST',
          'Not allowed',
          'SOLD HERE',
          'Not allowed',
          'Yes',
          27,
          new \DateTimeImmutable('2026-09-07'),
          '',
          '',
        ],
      ],
      [
        'row' => 5,
        'cells' => [
          12,
          'Joggers',
          '',
          '',
          'Good',
          'Men',
          1,
          27,
          'Not needed - sold',
          'Not needed - sold',
          'TO POST',
          'TO POST',
          'Not needed - sold',
          'Not allowed',
          'Yes',
          16,
          '',
          '',
          '',
        ],
      ],
      [
        'row' => 6,
        'cells' => [
          13,
          'Piano',
          '',
          '',
          'Good',
          'Electronic',
          1,
          170,
          'Not allowed',
          'Not allowed',
          'Not allowed',
          'Not allowed',
          'Not allowed',
          'TO POST',
          '',
          '',
          '',
          '',
          '',
        ],
      ],
      ['row' => 7, 'cells' => ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']],
    ];
    $context = [
      'conditions' => ['good' => 4, 'new without tags' => 2],
      'records' => [
        '10' => [
          'title' => 'Hurley H2O-Dri Hat Heather Gray',
          'description' => 'A cap.',
          'condition_notes' => 'No flaws.',
          'size' => 'XL',
          'color' => 'Gray',
          'price_min' => 12,
          'price_max' => 22,
          'photos' => ['10/a.jpg'],
        ],
      ],
      'fill' => TRUE,
      'default_location' => 21,
    ];
    $plan = ImportPlan::build($rows, $mapping, $context);
    $this->assertCount(5, $plan, 'Blank row 7 skipped.');

    [$hat, $yoda, $lamp, $joggers, $piano] = $plan;
    $this->assertSame('Hurley Hat', $hat['title']);
    $this->assertSame(ImportPlan::KIND_LISTED, $hat['kind']);
    $this->assertSame(['poshmark', 'mercari', 'depop', 'grailed', 'vinted', 'facebook'], array_keys($hat['listings']));
    $this->assertArrayNotHasKey('ebay', $hat['listings']);
    $this->assertSame(Platforms::TO_POST, $hat['listings']['depop']['status']);
    $this->assertSame(22.0, $hat['listings']['poshmark']['price']);
    $this->assertSame(21, $hat['fields']['location']);
    $this->assertSame(2, $hat['fields']['condition']);
    $this->assertSame("ACTION FOR CODY: measure it\nItem number in the sheet: 10", $hat['fields']['notes']);
    // Crosslister fills only empty fields: the sheet's size stays.
    $this->assertSame('L-XL', $hat['fields']['size']);
    $this->assertSame('A cap.', $hat['fields']['description']);
    $this->assertSame('No flaws.', $hat['fields']['flaws']);
    $this->assertSame(12.0, $hat['fields']['ai_price_min']);
    $this->assertSame(['10/a.jpg'], $hat['photos']);
    $this->assertTrue($hat['tick']);

    $this->assertSame(ImportPlan::KIND_SOLD, $yoda['kind']);
    $this->assertSame('mercari', $yoda['sold_on']);
    $this->assertSame(33.46, $yoda['sold_price']);
    $this->assertNull($yoda['date_sold']);

    // Sold on Vinted; Poshmark still Posted becomes Take down; To post
    // becomes Not needed; the date comes from "Sold on".
    $this->assertSame('vinted', $lamp['sold_on']);
    $this->assertSame('2026-09-07', $lamp['date_sold']);
    $this->assertSame(Platforms::TAKE_DOWN, $lamp['listings']['poshmark']['status']);
    $this->assertSame(Platforms::NOT_NEEDED_SOLD, $lamp['listings']['depop']['status']);

    // Sold with no "Sold here" and no "Sold on": can't be imported.
    $this->assertFalse($joggers['importable']);
    $this->assertFalse($joggers['tick']);
    $this->assertContains(ImportPlan::WARN, array_column($joggers['problems'], 0));

    $this->assertSame(ImportPlan::KIND_READY, $piano['kind']);
    $this->assertTrue($piano['tick']);

    $counts = ImportPlan::counts($plan);
    $this->assertSame(5, $counts['items']);
    $this->assertSame(3, $counts['kinds']['sold']);
    $this->assertSame(76.46, $counts['sold_total']);
    $this->assertSame(1, $counts['platforms']['poshmark']['posted']);
    $this->assertSame(1, $counts['platforms']['poshmark']['take_down']);
    $this->assertSame(5, $counts['platforms']['ebay']['blank']);
    $this->assertSame(1, $counts['records']);
    $this->assertSame(1, $counts['photos']);
  }

  /**
   * Generic sheet: rows per platform grouped, links checked, duplicates.
   */
  public function testPlanGenericRows(): void {
    $mapping = ['title', 'platform', 'link', 'price', 'status'];
    $rows = [
      ['row' => 2, 'cells' => ['Blue Hoodie', 'Posh', 'https://poshmark.com/listing/a1', '$24', 'Active']],
      ['row' => 3, 'cells' => ['Blue Hoodie', 'Mercari', 'https://www.mercari.com/us/item/m1/', 22, 'Active']],
      ['row' => 4, 'cells' => ['Blue Hoodie', 'Posh', 'https://poshmark.com/listing/a2', 24, 'Active']],
      ['row' => 5, 'cells' => ['Jacket', 'Grailed', 'https://www.mercari.com/us/item/m9/', 40, 'Active']],
      ['row' => 6, 'cells' => ['Bowl', 'Etsy', '', 18, '']],
      ['row' => 7, 'cells' => ['Old Hat', 'Posh', 'https://poshmark.com/listing/zz', 9, 'Ended']],
      ['row' => 8, 'cells' => ['Lamp', 'Posh', 'https://poshmark.com/listing/known', 9, 'Active']],
    ];
    $context = ['urls' => ['poshmark.com/listing/known' => 55], 'titles' => ['lamp' => 55]];
    $plan = ImportPlan::build($rows, $mapping, $context);
    $this->assertSame([[2, 3], [4], [5], [6], [7], [8]], array_column($plan, 'rows'));
    $this->assertSame(['poshmark', 'mercari'], array_keys($plan[0]['listings']));
    $this->assertSame(24.0, $plan[0]['listings']['poshmark']['price']);
    $this->assertSame('https://www.mercari.com/us/item/m1/', $plan[0]['listings']['mercari']['url']);
    // A Mercari link on a Grailed row is left out and flagged.
    $this->assertNull($plan[2]['listings']['grailed']['url']);
    $this->assertFalse($plan[2]['tick']);
    // Unknown platform: Ready, not ticked.
    $this->assertSame(ImportPlan::KIND_READY, $plan[3]['kind']);
    $this->assertFalse($plan[3]['tick']);
    // Ended: not live anywhere, not ticked.
    $this->assertSame(ImportPlan::KIND_ENDED, $plan[4]['kind']);
    $this->assertSame([], $plan[4]['listings']);
    // Already on the site (same link, same title): not ticked.
    $this->assertFalse($plan[5]['tick']);
    $this->assertCount(2, array_filter($plan[5]['problems'], fn ($p) => $p[0] === ImportPlan::WARN));
  }

  /**
   * Size and color fit the 60-character fields (row 26 failed on 67).
   */
  public function testShort(): void {
    $long = 'Yellow, red and black; one black/yellow and one purple novelty sign';
    $short = ImportPlan::short($long);
    $this->assertLessThanOrEqual(ImportPlan::SHORT_MAX, mb_strlen($short));
    $this->assertSame('Yellow, red and black; one black/yellow and one purple…', $short);
    $this->assertSame('L-XL', ImportPlan::short('L-XL'));
    $this->assertSame(60, mb_strlen(ImportPlan::short(str_repeat('x', 80))));
  }

  /**
   * Same-title check for the Crosslister records.
   */
  public function testSameTitle(): void {
    $this->assertTrue(ImportPlan::sameTitle('Hurley Hat Gray L-XL', 'Hurley H2O-Dri Flexfit Hat Gray Cap L-XL'));
    $this->assertFalse(ImportPlan::sameTitle('Lego Star Wars set', 'Hurley Hat Gray'));
    $this->assertFalse(ImportPlan::sameTitle('', 'Hat'));
  }

}
