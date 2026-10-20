<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Parser;

use Drupal\Component\Serialization\Yaml;
use Drupal\ishop_office\Vocabulary;

/**
 * Reads a build report written as Markdown with YAML front matter.
 *
 * @code
 * ---
 * date: 2026-10-15
 * phase: "0.5"
 * phase_status: in progress   # optional; updates the status board
 * commits: [69f5e1d, 3b2c1a0]
 * title: Site office built    # optional; or use a "# " line
 * ---
 * ## Summary
 * ## What was done
 * ## What's next
 * ## Blockers
 * ## Decisions needed
 * ## Tests
 * @endcode
 *
 * Summary, What was done and Tests are required; the others default to
 * "None.". Any other "## " heading is an error, so nothing is silently lost.
 */
final class ReportParser {

  /**
   * Section field => accepted headings, normalized.
   */
  public const SECTIONS = [
    'summary' => ['summary'],
    'work_done' => ['what was done', 'done', 'work done'],
    'next_steps' => ['whats next', 'what is next', 'next', 'next steps'],
    'blockers' => ['blockers'],
    'decisions_needed' => ['decisions needed', 'decisions needed from liam or cody'],
    'tests' => ['tests', 'tests run', 'tests run and results', 'tests and results'],
  ];

  public const REQUIRED = ['summary', 'work_done', 'tests'];

  private const FRONT_MATTER_KEYS = ['date', 'phase', 'phase_status', 'commits', 'title'];

  /**
   * Parses a report.
   *
   * @return array{date: string, phase: string, phase_status: ?string, commits: list<string>, title: ?string, sections: array<string, string>}
   *   Sections are keyed by the SECTIONS keys and hold Markdown.
   */
  public static function parse(string $markdown): array {
    $markdown = str_replace("\r\n", "\n", $markdown);
    if (!preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $markdown, $m)) {
      throw new ParseException('The report must start with YAML front matter between two "---" lines.');
    }
    $meta = Yaml::decode($m[1]);
    if (!is_array($meta)) {
      throw new ParseException('The front matter must be a set of "key: value" lines.');
    }
    $unknown = array_diff(array_keys($meta), self::FRONT_MATTER_KEYS);
    if ($unknown) {
      throw new ParseException(sprintf('Unknown front matter key(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::FRONT_MATTER_KEYS)));
    }

    $report = [
      'date' => self::date($meta['date'] ?? NULL),
      'phase' => self::phase($meta['phase'] ?? NULL),
      'phase_status' => NULL,
      'commits' => self::commits($meta['commits'] ?? []),
      'title' => isset($meta['title']) ? trim((string) $meta['title']) : NULL,
      'sections' => [],
    ];
    if (isset($meta['phase_status'])) {
      try {
        $report['phase_status'] = Vocabulary::normalize(Vocabulary::PHASE_STATUS, (string) $meta['phase_status']);
      }
      catch (\InvalidArgumentException $e) {
        throw new ParseException('phase_status: ' . $e->getMessage());
      }
    }

    $current = NULL;
    $buffers = [];
    foreach (explode("\n", $m[2]) as $line) {
      if (preg_match('/^# (.+)$/', $line, $h) && $current === NULL && !$buffers) {
        $report['title'] ??= trim($h[1]);
        continue;
      }
      if (preg_match('/^## (.+?)\s*#*\s*$/', $line, $h)) {
        $current = self::section($h[1]);
        if (isset($buffers[$current])) {
          throw new ParseException(sprintf('The "%s" section appears twice.', $h[1]));
        }
        $buffers[$current] = [];
        continue;
      }
      if ($current === NULL) {
        if (trim($line) !== '') {
          throw new ParseException('Text before the first "## " section heading: ' . mb_substr(trim($line), 0, 60));
        }
        continue;
      }
      $buffers[$current][] = $line;
    }

    foreach (array_keys(self::SECTIONS) as $key) {
      $text = trim(implode("\n", $buffers[$key] ?? []));
      if ($text === '') {
        if (in_array($key, self::REQUIRED, TRUE)) {
          throw new ParseException(sprintf('The "%s" section is required and must not be empty.', self::SECTIONS[$key][0]));
        }
        $text = 'None.';
      }
      $report['sections'][$key] = $text;
    }

    return $report;
  }

  /**
   * Maps a "## " heading to a section key.
   */
  private static function section(string $heading): string {
    $normalized = trim(preg_replace('/[^a-z ]+/', '', strtolower(str_replace(['’', "'"], '', $heading))));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    foreach (self::SECTIONS as $key => $aliases) {
      if (in_array($normalized, $aliases, TRUE)) {
        return $key;
      }
    }
    throw new ParseException(sprintf('Unknown section "## %s". Use: Summary, What was done, What\'s next, Blockers, Decisions needed, Tests.', $heading));
  }

  /**
   * Validates the date. YAML may hand back an unquoted date as a timestamp.
   */
  private static function date(mixed $value): string {
    if (is_int($value)) {
      $value = gmdate('Y-m-d', $value);
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
      throw new ParseException('date: required, as YYYY-MM-DD.');
    }
    return $value;
  }

  /**
   * Validates the phase key ("0", "0.5", "1"...). YAML may give a number.
   */
  private static function phase(mixed $value): string {
    if (is_int($value) || is_float($value)) {
      $value = (string) $value;
    }
    if (is_string($value) && in_array(strtolower($value), RoadmapParser::NAMED, TRUE)) {
      return strtolower($value);
    }
    if (!is_string($value) || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
      throw new ParseException('phase: required, the phase number from ROADMAP.md (e.g. "0.5"), "foundation" or "handover". Quote it.');
    }
    return $value;
  }

  /**
   * Validates commit hashes.
   *
   * @return list<string>
   *   Lowercase short or full hashes.
   */
  private static function commits(mixed $value): array {
    if (is_string($value)) {
      $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($value)) {
      throw new ParseException('commits: a list of git commit hashes.');
    }
    $commits = [];
    foreach ($value as $hash) {
      $hash = strtolower(trim((string) $hash));
      if (!preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
        throw new ParseException(sprintf('commits: "%s" is not a git commit hash.', $hash));
      }
      $commits[] = $hash;
    }
    return $commits;
  }

}
