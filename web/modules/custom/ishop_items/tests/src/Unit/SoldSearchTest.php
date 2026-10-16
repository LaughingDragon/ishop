<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\SoldSearch;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Check sold prices links.
 */
#[CoversClass(SoldSearch::class)]
final class SoldSearchTest extends UnitTestCase {

  /**
   * Every link searches the title, filtered to sold; Terapeak only if used.
   */
  public function testLinks(): void {
    $links = SoldSearch::links('Brass  table lamp & shade', FALSE);
    $this->assertSame(['ebay', 'mercari', 'poshmark'], array_keys($links));
    $this->assertSame('https://www.ebay.com/sch/i.html?_nkw=Brass+table+lamp+shade&LH_Sold=1&LH_Complete=1', $links['ebay']['url']);
    $this->assertSame('https://www.mercari.com/search/?keyword=Brass+table+lamp+shade&itemStatuses=2', $links['mercari']['url']);
    $this->assertSame('https://poshmark.com/search?query=Brass+table+lamp+shade&availability=sold_out', $links['poshmark']['url']);

    $links = SoldSearch::links('Brass table lamp', TRUE);
    $this->assertSame('terapeak', array_key_first($links));
    $this->assertSame('https://www.ebay.com/sh/research?marketplace=EBAY-US&keywords=Brass+table+lamp&dayRange=90&tabName=SOLD', $links['terapeak']['url']);
  }

  /**
   * Placeholder and empty titles give no links; long ones keep key words.
   */
  public function testQuery(): void {
    $this->assertSame([], SoldSearch::links('New item 2026-10-16 07:01', TRUE));
    $this->assertSame([], SoldSearch::links('  ', FALSE));
    // Cody's real titles (2026-10-16): whole titles found nothing on eBay.
    $this->assertSame('Heye Cartoon Classics Birthday 1000', SoldSearch::query('Heye Cartoon Classics Birthday 1000 Piece Jigsaw Puzzle Jean-Jacques Loup Sealed'));
    $this->assertSame('Wooden Christmas Money Holder Ornaments', SoldSearch::query('Set of 3 Wooden Christmas Money Holder Ornaments Reindeer Highland Cow Gingerbre'));
    $this->assertSame('ULiXWH SlimMag 5000mAh Magnetic Wireless', SoldSearch::query('ULiXWH SlimMag 5000mAh Magnetic Wireless Power Bank MagS-1 Gray for iPhone'));
    $this->assertSame('Pyrex bowls', SoldSearch::query('NEW Pyrex bowls, set of 4'));
  }

}
