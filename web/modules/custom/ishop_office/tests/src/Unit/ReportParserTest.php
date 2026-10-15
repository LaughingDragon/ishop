<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Parser\ParseException;
use Drupal\ishop_office\Parser\ReportParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading a build report file.
 */
#[CoversClass(ReportParser::class)]
#[Group('ishop_office')]
final class ReportParserTest extends UnitTestCase {

  private const REPORT = <<<'MD'
  ---
  date: 2026-10-15
  phase: "0.5"
  phase_status: In progress
  commits: [69F5E1D, 3b2c1a0]
  ---
  # Site office built

  ## Summary
  Built it.

  ## What was done
  - One
  - Two

  ### A sub-heading stays inside the section

  ## What's next
  Phase 1.

  ## Tests
  12 unit tests pass.
  MD;

  /**
   * Tests a complete report.
   */
  public function testParsesCompleteReport(): void {
    $r = ReportParser::parse(self::REPORT);
    $this->assertSame('2026-10-15', $r['date']);
    $this->assertSame('0.5', $r['phase']);
    $this->assertSame('in_progress', $r['phase_status']);
    $this->assertSame(['69f5e1d', '3b2c1a0'], $r['commits']);
    $this->assertSame('Site office built', $r['title']);
    $this->assertSame('Built it.', $r['sections']['summary']);
    $this->assertStringContainsString('### A sub-heading', $r['sections']['work_done']);
    $this->assertSame('Phase 1.', $r['sections']['next_steps']);
    $this->assertSame('None.', $r['sections']['blockers']);
    $this->assertSame('None.', $r['sections']['decisions_needed']);
    $this->assertSame('12 unit tests pass.', $r['sections']['tests']);
  }

  /**
   * Tests YAML values that arrive as numbers.
   */
  public function testUnquotedDateAndNumericPhaseAreAccepted(): void {
    $r = ReportParser::parse("---\ndate: 2026-01-31\nphase: 1\ncommits: abc1234 def5678\n---\n## Summary\nS\n## Done\nD\n## Tests run and results\nT\n");
    $this->assertSame('2026-01-31', $r['date']);
    $this->assertSame('1', $r['phase']);
    $this->assertSame(['abc1234', 'def5678'], $r['commits']);
    $this->assertNull($r['title']);
    $this->assertNull($r['phase_status']);
  }

  /**
   * Tests that "Foundation" is accepted as a phase.
   */
  public function testFoundationPhase(): void {
    $r = ReportParser::parse("---\ndate: 2026-01-31\nphase: Foundation\n---\n## Summary\nS\n## Done\nD\n## Tests\nT\n");
    $this->assertSame('foundation', $r['phase']);
  }

  /**
   * Tests that a front matter title beats a "# " heading.
   */
  public function testFrontMatterTitleWinsOverHeading(): void {
    $r = ReportParser::parse("---\ndate: 2026-01-31\nphase: '1'\ntitle: From YAML\n---\n# From heading\n## Summary\nS\n## Done\nD\n## Tests\nT\n");
    $this->assertSame('From YAML', $r['title']);
  }

  /**
   * Broken reports and the error they must raise.
   */
  public static function brokenReports(): array {
    $ok = "## Summary\nS\n## Done\nD\n## Tests\nT\n";
    return [
      'no front matter' => ["## Summary\nS\n", 'front matter'],
      'unknown key' => ["---\ndate: 2026-01-01\nphase: '1'\nauthor: me\n---\n$ok", 'Unknown front matter'],
      'missing date' => ["---\nphase: '1'\n---\n$ok", 'date'],
      'impossible date' => ["---\ndate: '2026-02-30'\nphase: '1'\n---\n$ok", 'date'],
      'bad phase' => ["---\ndate: 2026-01-01\nphase: one\n---\n$ok", 'phase'],
      'bad status' => ["---\ndate: 2026-01-01\nphase: '1'\nphase_status: finished\n---\n$ok", 'phase_status'],
      'bad commit' => ["---\ndate: 2026-01-01\nphase: '1'\ncommits: [main]\n---\n$ok", 'commit'],
      'unknown section' => ["---\ndate: 2026-01-01\nphase: '1'\n---\n$ok## Random\nx\n", 'Unknown section'],
      'duplicate section' => ["---\ndate: 2026-01-01\nphase: '1'\n---\n$ok## Summary\nagain\n", 'twice'],
      'missing tests' => ["---\ndate: 2026-01-01\nphase: '1'\n---\n## Summary\nS\n## Done\nD\n", 'required'],
      'empty summary' => ["---\ndate: 2026-01-01\nphase: '1'\n---\n## Summary\n\n## Done\nD\n## Tests\nT\n", 'required'],
      'stray text' => ["---\ndate: 2026-01-01\nphase: '1'\n---\nhello\n$ok", 'before the first'],
    ];
  }

  /**
   * Tests that each broken report is rejected.
   */
  #[DataProvider('brokenReports')]
  public function testBrokenReportsAreRejected(string $markdown, string $message): void {
    $this->expectException(ParseException::class);
    $this->expectExceptionMessage($message);
    ReportParser::parse($markdown);
  }

}
