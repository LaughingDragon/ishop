<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_ai\Unit;

use Drupal\ishop_ai\ListingWriter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the listing writer's research cleanup, cost and schema.
 */
#[CoversClass(ListingWriter::class)]
final class ListingWriterTest extends UnitTestCase {

  /**
   * No search results: research fields cleared, never shown as market data.
   */
  public function testNoSearchClearsResearch(): void {
    $draft = [
      'research_notes' => 'Sold for $20 on eBay',
      'sources' => 'ebay.com',
      'suggested_price' => 0,
      'price_min' => 10,
      'price_max' => 20,
    ];
    $searched = ListingWriter::cleanResearch($draft, [['type' => 'text', 'text' => '{}']]);
    $this->assertFalse($searched);
    $this->assertSame('', $draft['research_notes']);
    $this->assertSame('', $draft['sources']);
    // A missing price becomes the middle of the range.
    $this->assertSame(15.0, $draft['suggested_price']);
  }

  /**
   * An errored search (an object, not a list) doesn't count as research.
   */
  public function testErroredSearch(): void {
    $draft = ['research_notes' => 'x', 'sources' => 'y', 'suggested_price' => 12];
    $error = ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded'];
    $blocks = [['type' => 'web_search_tool_result', 'content' => $error]];
    $this->assertFalse(ListingWriter::cleanResearch($draft, $blocks));
    $this->assertSame('', $draft['sources']);
  }

  /**
   * Real results: missing sources are filled from the result URLs, once each.
   */
  public function testSourcesFromResults(): void {
    $draft = ['research_notes' => 'Similar sold $12-18', 'sources' => '', 'suggested_price' => 15];
    $blocks = [[
      'type' => 'web_search_tool_result',
      'content' => [
        ['type' => 'web_search_result', 'url' => 'https://www.ebay.com/itm/1'],
        ['type' => 'web_search_result', 'url' => 'https://poshmark.com/listing/2'],
        ['type' => 'web_search_result', 'url' => 'https://www.ebay.com/itm/1'],
      ],
    ],
    ];
    $this->assertTrue(ListingWriter::cleanResearch($draft, $blocks));
    $this->assertSame("https://www.ebay.com/itm/1\nhttps://poshmark.com/listing/2", $draft['sources']);
    $this->assertSame('Similar sold $12-18', $draft['research_notes']);
  }

  /**
   * Cost: $4/$20 per million tokens, $0.01 per search (Opus 5.5).
   */
  public function testCost(): void {
    // 20k in ($0.08) + 2k out ($0.04) + 4 searches ($0.04).
    $this->assertSame(0.16, ListingWriter::cost(['input' => 20000, 'output' => 2000, 'searches' => 4]));
    $this->assertSame(0.0, ListingWriter::cost(['input' => 0, 'output' => 0]));
  }

  /**
   * The schema keeps every field of the old app's ListingDraft, all required.
   */
  public function testSchema(): void {
    $schema = ListingWriter::schema();
    $this->assertSame([
      'title',
      'description',
      'category',
      'condition',
      'condition_notes',
      'brand',
      'size',
      'color',
      'price_min',
      'price_max',
      'suggested_price',
      'research_notes',
      'sources',
    ], $schema['required']);
    $this->assertFalse($schema['additionalProperties']);
  }

}
