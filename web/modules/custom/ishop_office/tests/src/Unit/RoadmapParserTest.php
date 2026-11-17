<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Parser\ParseException;
use Drupal\ishop_office\Parser\RoadmapParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading phases and checklist counts from ROADMAP.md.
 */
#[CoversClass(RoadmapParser::class)]
#[Group('ishop_office')]
final class RoadmapParserTest extends UnitTestCase {

  private const ROADMAP = <<<'MD'
  # Build roadmap

  Intro text.

  ## Standing rules

  - [x] Not a phase, so not counted.

  ## Foundation: server and stack

  - [x] Server.

  ## Phase 0: safety net

  - [x] Backups.
  - [ ] Snapshots.
  - Accounts:
    - [X] webmaster
    - [ ] theowner
  * [x] Star bullets count too.
  - [] Malformed, not counted.

  ## Phase 0.5: the site office

  A paragraph, no checklist.

  ## Phase 1: items

  - [ ] Item type.

  ## Handover countdown: before the office closes

  - [ ] Boot disk.

  ## Later

  - [ ] Not a phase either.
  MD;

  /**
   * Tests phase keys, order and headings.
   */
  public function testParsesPhasesInOrder(): void {
    $result = RoadmapParser::parse(self::ROADMAP);
    $this->assertSame('Build roadmap', $result['title']);
    $this->assertSame(['foundation', '0', '0.5', '1', 'handover'], array_column($result['phases'], 'key'));
    $this->assertSame([0, 1, 2, 3, 4], array_column($result['phases'], 'weight'));
    $this->assertSame('Handover countdown: before the office closes', $result['phases'][4]['heading']);
    $this->assertSame('Foundation: server and stack', $result['phases'][0]['heading']);
    $this->assertSame('Phase 0.5: the site office', $result['phases'][2]['heading']);
    $this->assertSame('the site office', $result['phases'][2]['name']);
  }

  /**
   * Tests that only checkboxes inside phases count.
   */
  public function testCountsCheckboxesPerPhaseOnly(): void {
    $phases = RoadmapParser::parse(self::ROADMAP)['phases'];
    $this->assertSame(['done' => 1, 'total' => 1], array_intersect_key($phases[0], ['done' => 0, 'total' => 0]));
    $this->assertSame(['done' => 3, 'total' => 5], array_intersect_key($phases[1], ['done' => 0, 'total' => 0]));
    $this->assertSame(0, $phases[2]['total']);
    $this->assertSame(['done' => 0, 'total' => 1], array_intersect_key($phases[3], ['done' => 0, 'total' => 0]));
  }

  /**
   * Tests that only the title line is removed from the body.
   */
  public function testBodyDropsTitleLineOnly(): void {
    $body = RoadmapParser::parse(self::ROADMAP)['body'];
    $this->assertStringNotContainsString('# Build roadmap', $body);
    $this->assertStringStartsWith('Intro text.', $body);
    $this->assertStringContainsString('## Later', $body);
  }

  /**
   * Tests per-phase sections and the done/to do words.
   */
  public function testSectionsAndAnnotation(): void {
    $result = RoadmapParser::parse(self::ROADMAP);
    $section = $result['phases'][1]['markdown'];
    $this->assertStringStartsWith('- [x] (done) Backups.', $section);
    $this->assertStringContainsString('- [ ] (to do) Snapshots.', $section);
    $this->assertStringContainsString('  - [X] (done) webmaster', $section);
    $this->assertStringContainsString('- [] Malformed', $section);
    $this->assertStringNotContainsString('Phase 0.5', $section);
    $this->assertSame("A paragraph, no checklist.\n", $result['phases'][2]['markdown']);
    $this->assertStringContainsString('- [x] (done) Not a phase', $result['body']);
  }

  /**
   * Tests rounding down and phases without a checklist.
   */
  public function testPercent(): void {
    $this->assertSame(60, RoadmapParser::percent(3, 5));
    $this->assertSame(66, RoadmapParser::percent(2, 3));
    $this->assertSame(100, RoadmapParser::percent(4, 4));
    $this->assertNull(RoadmapParser::percent(0, 0));
  }

  /**
   * Tests that a repeated phase is rejected.
   */
  public function testDuplicatePhaseIsAnError(): void {
    $this->expectException(ParseException::class);
    RoadmapParser::parse("# T\n## Phase 1: a\n## Phase 1: b\n");
  }

  /**
   * Tests that a roadmap needs a title.
   */
  public function testMissingTitleIsAnError(): void {
    $this->expectException(ParseException::class);
    RoadmapParser::parse("## Phase 1: a\n");
  }

  /**
   * Tests that a roadmap needs phases.
   */
  public function testNoPhasesIsAnError(): void {
    $this->expectException(ParseException::class);
    RoadmapParser::parse("# T\n## Notes\n");
  }

  /**
   * Tests the real ROADMAP.md.
   */
  public function testRealRoadmapParses(): void {
    $file = dirname(__DIR__, 7) . '/ROADMAP.md';
    if (!is_file($file)) {
      $this->markTestSkipped('ROADMAP.md not found at the project root.');
    }
    $phases = RoadmapParser::parse(file_get_contents($file))['phases'];
    $this->assertContains('0.5', array_column($phases, 'key'));
    foreach ($phases as $phase) {
      $this->assertLessThanOrEqual($phase['total'], $phase['done']);
    }
  }

}
