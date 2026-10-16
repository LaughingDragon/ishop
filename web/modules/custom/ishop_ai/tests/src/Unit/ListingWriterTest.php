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
    $run = ['input' => 20000, 'output' => 2000, 'searches' => 4];
    $this->assertSame(0.16, ListingWriter::cost($run + ['model' => 'claude-opus-5-5']));
    // Sonnet: 20k in ($0.04) + 2k out ($0.02) + 4 searches ($0.04).
    $this->assertSame(0.10, ListingWriter::cost($run + ['model' => 'claude-sonnet-5-5']));
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
      'new_category_idea',
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
    $this->assertArrayNotHasKey('enum', $schema['properties']['category']);
  }

  /**
   * With the site's categories, the AI must pick one of them.
   */
  public function testSchemaCategories(): void {
    $schema = ListingWriter::schema(['Electronics', 'Home & Kitchen > Holiday & Seasonal', 'Other']);
    $this->assertSame(['Electronics', 'Home & Kitchen > Holiday & Seasonal', 'Other'], $schema['properties']['category']['enum']);
  }

  /**
   * Two-step cost: each step at its own model's price, searches included.
   */
  public function testTwoStepCost(): void {
    $haiku = ['model' => 'claude-haiku-5-5', 'input' => 2000, 'output' => 800] + ListingWriter::emptyUsage('claude-haiku-5-5');
    $sonnet = ['input' => 10000, 'output' => 500, 'searches' => 2] + ListingWriter::emptyUsage('claude-sonnet-5-5');
    $usage = ListingWriter::combine([$haiku, $sonnet]);
    $this->assertSame(12000, $usage['input']);
    $this->assertSame(2, $usage['searches']);
    // Haiku 0.0002 + 0.0004; Sonnet 0.02 + 0.005 + 0.02 searches.
    $this->assertSame(0.0456, ListingWriter::cost($usage));
    // Combining again (the one-step fallback) keeps the steps flat.
    $this->assertCount(3, ListingWriter::combine([$usage, $haiku])['steps']);
  }

  /**
   * Near-identical items share research; different ones don't.
   */
  public function testResearchKey(): void {
    $this->assertSame(
      ListingWriter::researchKey('Heye Cartoon Classics  Birthday puzzle!', 'Toys & Hobbies'),
      ListingWriter::researchKey('heye cartoon classics birthday PUZZLE', 'toys & hobbies'),
    );
    $this->assertNotSame(
      ListingWriter::researchKey('Heye Birthday puzzle', 'Toys & Hobbies'),
      ListingWriter::researchKey('Heye Birthday puzzle', 'Collectibles'),
    );
  }

  /**
   * Step 2 asks for the research fields only.
   */
  public function testResearchSchema(): void {
    $schema = ListingWriter::researchSchema();
    $this->assertSame(ListingWriter::RESEARCH_FIELDS, $schema['required']);
    $this->assertSame(ListingWriter::RESEARCH_FIELDS, array_keys($schema['properties']));
  }

}
