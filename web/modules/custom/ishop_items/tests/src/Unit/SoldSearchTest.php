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
    $this->assertSame('https://www.ebay.com/sch/i.html?_nkw=Brass+table+lamp+%26+shade&LH_Sold=1&LH_Complete=1', $links['ebay']['url']);
    $this->assertSame('https://www.mercari.com/search/?keyword=Brass+table+lamp+%26+shade&itemStatuses=2', $links['mercari']['url']);
    $this->assertSame('https://poshmark.com/search?query=Brass+table+lamp+%26+shade&availability=sold_out', $links['poshmark']['url']);

    $links = SoldSearch::links('Brass table lamp', TRUE);
    $this->assertSame('terapeak', array_key_first($links));
    $this->assertSame('https://www.ebay.com/sh/research?marketplace=EBAY-US&keywords=Brass+table+lamp&dayRange=90&tabName=SOLD', $links['terapeak']['url']);
  }

  /**
   * Placeholder and empty titles give no links; long ones are cut to 80.
   */
  public function testQuery(): void {
    $this->assertSame([], SoldSearch::links('New item 2026-10-16 07:01', TRUE));
    $this->assertSame([], SoldSearch::links('  ', FALSE));
    $this->assertSame(80, mb_strlen((string) SoldSearch::query(str_repeat('lamp ', 30))));
  }

}
