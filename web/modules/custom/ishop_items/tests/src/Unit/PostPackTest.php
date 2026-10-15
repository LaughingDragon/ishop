<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\PostPack;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the per-platform paste-ready fields.
 */
#[CoversClass(PostPack::class)]
final class PostPackTest extends UnitTestCase {

  /**
   * Same output as the old crosslister's Python formatters.
   *
   * The fixture was made by running app/formatters on random listings
   * (2026-10-15; 9000 platform packs matched before 11 were kept here).
   */
  public function testMatchesTheOldFormatters(): void {
    $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/postpack-golden.json'), TRUE);
    $this->assertNotEmpty($cases);
    foreach ($cases as $i => $case) {
      $listing = $case['in'];
      $listing['price'] = $listing['price'] === NULL ? NULL : (float) $listing['price'];
      foreach ($case['out'] as $platform => $want) {
        foreach ($want['fields'] as $k => $field) {
          // Python keeps an empty note as ""; PHP uses NULL.
          $want['fields'][$k]['note'] = ($field['note'] ?? '') === '' ? NULL : $field['note'];
        }
        $this->assertEquals($want, PostPack::build($platform, $listing), "Case $i, $platform");
      }
    }
  }

  /**
   * Every platform on the site has a pack, a photo spec and an image style.
   */
  public function testEveryPlatformCovered(): void {
    $this->assertSame(array_keys(Platforms::PLATFORMS), array_keys(PostPack::PHOTO_SPECS));
    foreach (array_keys(Platforms::PLATFORMS) as $platform) {
      $pack = PostPack::build($platform, ['title' => 'Lamp']);
      $this->assertSame('Lamp', $pack['fields'][0]['value'], $platform);
    }
  }

  /**
   * Titles are cut at a word, never mid-word, with no trailing punctuation.
   */
  public function testSmartTruncate(): void {
    $this->assertSame('Vintage Levi', PostPack::smartTruncate('Vintage Levi, 501 jeans', 14));
    $this->assertSame('short', PostPack::smartTruncate('  short ', 80));
    $this->assertSame('abcde', PostPack::smartTruncate('abcdefgh', 5));
  }

  /**
   * Flaws survive: the free-text description is cut first.
   */
  public function testFlawsSurviveTruncation(): void {
    $text = PostPack::standardDescription([
      'description' => str_repeat('word ', 300),
      'condition' => 'Good',
      'condition_notes' => 'small stain on cuff',
    ], 100);
    $this->assertLessThanOrEqual(100, mb_strlen($text));
    $this->assertStringEndsWith("Condition: Good\nFlaws: small stain on cuff", $text);
  }

  /**
   * Depop's single text box: title first, hashtags last, within 1000.
   */
  public function testDepopTextBox(): void {
    $pack = PostPack::build('depop', [
      'title' => 'Gap navy tee',
      'description' => str_repeat('soft cotton ', 200),
      'brand' => 'Gap',
      'color' => 'navy blue',
      'category' => "Men's Clothing > T-Shirts",
    ]);
    $text = $pack['fields'][0]['value'];
    $this->assertStringStartsWith("Gap navy tee\n\n", $text);
    $this->assertStringEndsWith('#gap #navy #tshirts', $text);
    $this->assertLessThanOrEqual(1000, mb_strlen($text));
    $this->assertFalse($pack['fields'][0]['over']);
  }

  /**
   * Mercari's payout note uses the 10% fee and the $2.00 payout fee.
   */
  public function testMercariPayout(): void {
    $this->assertStringStartsWith('$25.00 after Mercari', PostPack::mercariPayout(30.0));
    $this->assertSame('', PostPack::mercariPayout(NULL));
  }

}
