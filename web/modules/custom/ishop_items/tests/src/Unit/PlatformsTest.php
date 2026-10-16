<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Platforms;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests recognising listing links.
 */
#[CoversClass(Platforms::class)]
final class PlatformsTest extends UnitTestCase {

  /**
   * Links and the platform they belong to.
   */
  #[DataProvider('urlProvider')]
  public function testFromUrl(string $url, ?string $expected): void {
    $this->assertSame($expected, Platforms::fromUrl($url));
  }

  /**
   * Real-looking listing and share links.
   */
  public static function urlProvider(): array {
    return [
      'poshmark' => ['https://poshmark.com/listing/Carhartt-Jacket-64f0c1', 'poshmark'],
      'poshmark short' => ['https://posh.mk/AbC123', 'poshmark'],
      'mercari' => ['https://www.mercari.com/us/item/m12345678901/', 'mercari'],
      'mercari short' => ['https://merc.li/xyz', 'mercari'],
      'depop' => ['https://www.depop.com/products/cody-carhartt-jacket/', 'depop'],
      'depop app link' => ['https://depop.app.link/aBcD', 'depop'],
      'grailed' => ['https://www.grailed.com/listings/12345-carhartt', 'grailed'],
      'vinted' => ['https://www.vinted.com/items/123-jacket', 'vinted'],
      'vinted uk' => ['https://www.vinted.co.uk/items/123', 'vinted'],
      'facebook' => ['https://www.facebook.com/marketplace/item/123456/', 'facebook'],
      'facebook mobile' => ['https://m.facebook.com/marketplace/item/1/', 'facebook'],
      'facebook share' => ['https://fb.me/abc', 'facebook'],
      'ebay' => ['https://www.ebay.com/itm/123', 'ebay'],
      'ebay share' => ['https://ebay.us/m/AbCd', 'ebay'],
      'not a platform' => ['https://www.etsy.com/listing/123', NULL],
      'lookalike' => ['https://poshmark.com.evil.example/listing/1', NULL],
      'no scheme' => ['poshmark.com/listing/1', NULL],
      'javascript' => ['javascript:alert(1)', NULL],
    ];
  }

  /**
   * Pasted text: one link per platform, unknown lines, duplicates.
   */
  public function testParseLinks(): void {
    $text = "https://posh.mk/a\n https://www.mercari.com/us/item/m1/ , https://posh.mk/b\nhello https://etsy.com/x";
    $this->assertSame([
      'links' => ['poshmark' => 'https://posh.mk/a', 'mercari' => 'https://www.mercari.com/us/item/m1/'],
      'unknown' => ['hello', 'https://etsy.com/x'],
      'duplicates' => ['poshmark'],
    ], Platforms::parseLinks($text));
  }

}
