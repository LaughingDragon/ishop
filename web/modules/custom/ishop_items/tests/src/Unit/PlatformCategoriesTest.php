<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Eligibility as E;
use Drupal\ishop_items\PlatformCategories as P;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the platform category match and the closest site category.
 */
#[CoversClass(P::class)]
final class PlatformCategoriesTest extends UnitTestCase {

  /**
   * The site's categories as of 2026-10-16.
   */
  private const PATHS = [
    "Men's Clothing", "Women's Clothing", 'Shoes', 'Jewelry & Accessories', 'Toys & Hobbies',
    'Collectibles', 'Home & Kitchen', 'Home & Kitchen > Holiday & Seasonal', 'Electronics',
    'Sporting Goods', 'Health & Beauty', 'Office & Craft', 'Automotive', 'Other',
  ];

  /**
   * Every site category has an entry for every shipping platform.
   */
  public function testMapIsComplete(): void {
    foreach (self::PATHS as $path) {
      if ($path === 'Other') {
        continue;
      }
      $key = P::key($path);
      $this->assertNotNull($key, $path);
      foreach (['poshmark', 'mercari', 'depop', 'grailed', 'vinted'] as $platform) {
        $this->assertArrayHasKey($platform, P::MAP[$key], "$path on $platform");
      }
    }
  }

  /**
   * Liam's first item: no category, then Holiday & Seasonal.
   */
  public function testHolidayItem(): void {
    $none = P::evaluate(['category' => '', 'title' => 'Wooden money holder ornaments'], 'poshmark');
    $this->assertSame(E::CHECK, $none['verdict']);
    $this->assertSame(P::NEEDS_CATEGORY, $none['label']);
    $this->assertSame("Set the item's category first; then this platform will say Post it or Not allowed.", $none['reason']);

    $facts = ['category' => 'Home & Kitchen > Holiday & Seasonal', 'title' => 'Wooden money holder ornaments'];
    $posh = P::evaluate($facts, 'poshmark');
    $this->assertSame(E::YES, $posh['verdict']);
    $this->assertSame('Post it', $posh['label']);
    $this->assertSame('Poshmark: Home > Holiday.', $posh['reason']);

    $grailed = P::evaluate($facts, 'grailed');
    $this->assertSame(E::NO, $grailed['verdict']);
    $this->assertSame(P::SKIP, $grailed['label']);
    $this->assertSame('No category for this on Grailed.', $grailed['reason']);
  }

  /**
   * Bans and cautions from the ported rules still apply.
   */
  public function testRulesStillApply(): void {
    $bank = ['category' => 'Electronics', 'title' => 'Magnetic power bank 5000mAh'];
    $this->assertSame(E::NO, P::evaluate($bank, 'depop')['verdict']);
    $posh = P::evaluate($bank, 'poshmark');
    $this->assertSame(E::YES, $posh['verdict']);
    $this->assertStringStartsWith('Poshmark: Electronics. Before posting: ', $posh['reason']);

    $scarf = ['category' => "Women's Clothing", 'title' => 'Heated scarf, rechargeable battery'];
    $this->assertSame(E::NO, P::evaluate($scarf, 'depop')['verdict']);

    $by_hand = P::evaluate(['category' => 'Automotive', 'title' => 'Car magnet', 'override' => 'yes'], 'poshmark');
    $this->assertSame(E::YES, $by_hand['verdict']);
    $this->assertTrue($by_hand['overridden']);

    $other = P::evaluate(['category' => 'Other', 'title' => 'Thing'], 'mercari');
    $this->assertSame(P::TOO_GENERAL, $other['label']);
  }

  /**
   * The closest site category for the AI's free text.
   */
  #[DataProvider('closestProvider')]
  public function testClosest(string $text, string $expected): void {
    $this->assertSame($expected, P::closest($text, self::PATHS));
  }

  /**
   * AI category text and the site category it should become.
   */
  public static function closestProvider(): array {
    return [
      'same path' => ['Home & Kitchen > Holiday & Seasonal', 'Home & Kitchen > Holiday & Seasonal'],
      'item 121' => ['Toys & Hobbies > Puzzles > Jigsaw Puzzles', 'Toys & Hobbies'],
      'item 255' => [
        'Home & Garden > Holiday & Seasonal Decor > Christmas Ornaments',
        'Home & Kitchen > Holiday & Seasonal',
      ],
      'item 256' => ['Electronics > Cell Phone Accessories > Chargers & Power Banks', 'Electronics'],
      'and for &' => ['health and beauty', 'Health & Beauty'],
      'by kind' => ['Home & Garden > Kitchen & Dining', 'Home & Kitchen'],
      'nothing close' => ['Pallets of mystery', 'Other'],
    ];
  }

  /**
   * Size is for clothing and shoes only.
   */
  public function testHasSize(): void {
    $this->assertTrue(P::hasSize("Women's Clothing"));
    $this->assertTrue(P::hasSize('Shoes'));
    $this->assertFalse(P::hasSize('Electronics'));
    $this->assertFalse(P::hasSize('Home & Kitchen > Holiday & Seasonal'));
  }

}
