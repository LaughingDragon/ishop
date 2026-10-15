<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Parser;

/**
 * Reads ROADMAP.md: its title, its body and each phase's checklist counts.
 *
 * A phase is a "## Phase <number>: <name>" heading, or the one
 * "## Foundation: <name>" heading (key "foundation") for the server work done
 * before Phase 0. Its checklist items are the "- [ ]" and "- [x]" lines (any
 * depth) up to the next "## " heading.
 */
final class RoadmapParser {

  private const PHASE_HEADING = '/^## (?:Phase (\d+(?:\.\d+)?)|(Foundation)):\s*(.+?)\s*$/';
  private const CHECKBOX = '/^\s*[-*+] \[( |x|X)\] /';

  /**
   * Parses the roadmap.
   *
   * @return array{title: string, body: string, phases: list<array{key: string, name: string, heading: string, weight: int, done: int, total: int, markdown: string}>}
   *   The title (from the first "# " line), the body without that line, and
   *   the phases in file order, each with its own section's Markdown.
   *   Checklist items in body and sections are annotated, see annotate().
   */
  public static function parse(string $markdown): array {
    $lines = preg_split('/\R/', $markdown);
    $title = '';
    $body_lines = [];
    $phases = [];
    $current = NULL;
    $seen = [];

    foreach ($lines as $line) {
      if ($title === '' && str_starts_with($line, '# ')) {
        $title = trim(substr($line, 2));
        continue;
      }
      $body_lines[] = $line;

      if (str_starts_with($line, '## ')) {
        $current = NULL;
        if (preg_match(self::PHASE_HEADING, $line, $m)) {
          $key = $m[1] !== '' ? $m[1] : 'foundation';
          if (isset($seen[$key])) {
            throw new ParseException(sprintf('Phase %s appears twice in the roadmap.', $key));
          }
          $seen[$key] = TRUE;
          $phases[] = [
            'key' => $key,
            'name' => $m[3],
            'heading' => $m[1] !== '' ? sprintf('Phase %s: %s', $m[1], $m[3]) : sprintf('Foundation: %s', $m[3]),
            'weight' => count($phases),
            'done' => 0,
            'total' => 0,
            'markdown' => '',
          ];
          $current = array_key_last($phases);
        }
        continue;
      }

      if ($current !== NULL) {
        $phases[$current]['markdown'] .= $line . "\n";
      }
      if ($current !== NULL && preg_match(self::CHECKBOX, $line, $m)) {
        $phases[$current]['total']++;
        if ($m[1] !== ' ') {
          $phases[$current]['done']++;
        }
      }
    }

    if ($title === '') {
      throw new ParseException('The roadmap has no "# " title line.');
    }
    if (!$phases) {
      throw new ParseException('The roadmap has no "## Phase <n>: <name>" headings.');
    }

    foreach ($phases as &$phase) {
      $phase['markdown'] = self::annotate(trim($phase['markdown'])) . "\n";
    }

    return [
      'title' => $title,
      'body' => self::annotate(trim(implode("\n", $body_lines))) . "\n",
      'phases' => $phases,
    ];
  }

  /**
   * Adds "(done)" or "(to do)" after each checkbox.
   *
   * Rendered checkboxes are inputs, and their state is lost when a page is
   * read as plain text. The words keep it.
   */
  public static function annotate(string $markdown): string {
    return preg_replace_callback(
      '/^(\s*[-*+] \[( |x|X)\] )/m',
      fn (array $m) => $m[1] . ($m[2] === ' ' ? '(to do) ' : '(done) '),
      $markdown,
    );
  }

  /**
   * Percent of checklist items done, or NULL when a phase has none.
   */
  public static function percent(int $done, int $total): ?int {
    return $total > 0 ? (int) floor(100 * $done / $total) : NULL;
  }

}
