<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ishop_office\Drush\OptionValues;
use Drupal\ishop_office\OfficeManager;
use Drupal\ishop_office\Parser\ReportParser;
use Drupal\ishop_office\Parser\SecretScanner;
use Drupal\ishop_office\Vocabulary;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands the agent uses to keep the site office up to date.
 *
 * Everything is saved as site-foreman, whoever runs the command.
 */
final class OfficeCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly OfficeManager $office,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Re-syncs the plans page and status board from ROADMAP.md.
   */
  #[CLI\Command(name: 'ishop:plans')]
  #[CLI\Option(name: 'file', description: 'Roadmap file. Defaults to ROADMAP.md at the project root.')]
  #[CLI\Usage(name: 'drush ishop:plans', description: 'Sync after editing or ticking ROADMAP.md.')]
  public function plans(array $options = ['file' => NULL]): int {
    $file = $options['file'] ?? dirname(DRUPAL_ROOT) . '/ROADMAP.md';
    $markdown = $this->read($file);
    $result = $this->office->syncPlans($markdown, basename($file));
    $this->logger()->success(sprintf('Plans page %s.', $result['plan']));
    foreach (['created', 'updated', 'unchanged'] as $bucket) {
      if ($result[$bucket]) {
        $this->logger()->notice(sprintf('Phases %s: %s', $bucket, implode(', ', $result[$bucket])));
      }
    }
    if ($result['orphaned']) {
      $this->logger()->warning(sprintf('On the status board but no longer in the roadmap (left as is): phase %s', implode(', ', $result['orphaned'])));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Files a build report from a Markdown file and updates the status board.
   */
  #[CLI\Command(name: 'ishop:report')]
  #[CLI\Argument(name: 'file', description: 'Markdown report with YAML front matter (see ReportParser for the format).')]
  #[CLI\Option(name: 'dry-run', description: 'Check the file and show what would be filed, without saving.')]
  #[CLI\Option(name: 'crew-only', description: 'Hide it from the owner (only webmaster and site-foreman see it).')]
  #[CLI\Usage(name: 'drush ishop:report /tmp/report.md --dry-run', description: 'Validate a report.')]
  public function report(string $file, array $options = ['dry-run' => FALSE, 'crew-only' => FALSE]): int {
    $markdown = $this->read($file);
    $this->refuseSecrets($markdown);
    $report = ReportParser::parse($markdown);
    if (!$this->office->phase($report['phase'])) {
      throw new \RuntimeException(sprintf('There is no phase "%s" on the status board.', $report['phase']));
    }
    if ($options['dry-run']) {
      $this->io()->definitionList(
        ['Date' => $report['date']],
        ['Phase' => $report['phase']],
        ['Phase status' => $report['phase_status'] ? Vocabulary::PHASE_STATUS[$report['phase_status']] : '(unchanged)'],
        ['Title' => $report['title'] ?? '(default)'],
        ['Commits' => implode(', ', $report['commits']) ?: '(none)'],
        ['Sections' => implode(', ', array_keys($report['sections']))],
      );
      $this->logger()->success('Dry run: the report is valid. Nothing was saved.');
      return self::EXIT_SUCCESS;
    }
    $this->office->setCrewOnly((bool) $options['crew-only']);
    $node = $this->office->fileReport($report, basename($file));
    $this->logger()->success(sprintf('Filed "%s": %s', $node->label(), $node->toUrl('canonical', ['absolute' => TRUE])->toString()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Sets a phase's status on the status board.
   */
  #[CLI\Command(name: 'ishop:phase')]
  #[CLI\Argument(name: 'phase', description: 'Phase number, e.g. 0.5.')]
  #[CLI\Argument(name: 'status', description: 'not_started, in_progress, blocked or done.')]
  #[CLI\Option(name: 'why', description: 'Reason, kept in the revision log.')]
  public function phase(string $phase, string $status, array $options = ['why' => '']): int {
    $status = Vocabulary::normalize(Vocabulary::PHASE_STATUS, $status);
    $node = $this->office->setPhaseStatus($phase, $status, (string) $options['why']);
    $this->logger()->success(sprintf('%s is now %s.', $node->label(), Vocabulary::PHASE_STATUS[$status]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Adds an open item to the punch list.
   */
  #[CLI\Command(name: 'ishop:punch')]
  #[CLI\Argument(name: 'question', description: 'The question or decision, up to 255 characters.')]
  #[CLI\Option(name: 'waiting-on', description: 'Comma-separated: liam, cody, site-foreman (server work), claude (browser review or judgment).')]
  #[CLI\Option(name: 'phase', description: 'Phase number it belongs to.')]
  #[CLI\Option(name: 'details', description: 'Background (Markdown).')]
  #[CLI\Option(name: 'instructions-file', description: 'Markdown file with step-by-step instructions. Required when waiting on Liam, Cody or Claude (review).')]
  #[CLI\Option(name: 'date', description: 'Opened date, YYYY-MM-DD. Defaults to today.')]
  #[CLI\Option(name: 'crew-only', description: 'Hide it from the owner (only webmaster and site-foreman see it).')]
  public function punch(
    string $question,
    array $options = [
      'waiting-on' => '',
      'phase' => NULL,
      'details' => '',
      'instructions-file' => NULL,
      'date' => NULL,
      'crew-only' => FALSE,
    ],
  ): int {
    $people = $this->people((string) $options['waiting-on']);
    $instructions = $options['instructions-file'] ? trim($this->read((string) $options['instructions-file'])) : '';
    if ($instructions === '' && array_intersect($people, Vocabulary::NEEDS_INSTRUCTIONS)) {
      throw new \InvalidArgumentException('--instructions-file is required: anything waiting on Liam, Cody or Claude (review) needs full step-by-step instructions.');
    }
    $this->refuseSecrets($question . "\n" . $options['details'] . "\n" . $instructions);
    $this->office->setCrewOnly((bool) $options['crew-only']);
    $node = $this->office->addPunch($question, $people, OptionValues::phase($options['phase']), (string) $options['details'], $this->date($options['date']), $instructions);
    $this->logger()->success(sprintf('Punch list item %d opened: %s', $node->id(), $node->label()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Sets or replaces the step-by-step instructions on a punch list item.
   */
  #[CLI\Command(name: 'ishop:punch-instructions')]
  #[CLI\Argument(name: 'nid', description: 'Node ID of the punch list item.')]
  #[CLI\Argument(name: 'file', description: 'Markdown file with the instructions.')]
  public function punchInstructions(int $nid, string $file): int {
    $instructions = trim($this->read($file));
    if ($instructions === '') {
      throw new \InvalidArgumentException(sprintf('%s is empty.', $file));
    }
    $this->refuseSecrets($instructions);
    $node = $this->office->setPunchInstructions($nid, $instructions);
    $this->logger()->success(sprintf('Instructions set on punch list item %d: %s', $node->id(), $node->label()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Closes a punch list item and records the answer.
   */
  #[CLI\Command(name: 'ishop:punch-close')]
  #[CLI\Argument(name: 'nid', description: 'Node ID of the punch list item (see drush ishop:office).')]
  #[CLI\Option(name: 'answer', description: 'The answer or outcome (Markdown). Required.')]
  #[CLI\Option(name: 'by', description: 'Who gave the answer: liam, cody, site-foreman (default) or claude (review).')]
  #[CLI\Option(name: 'date', description: 'Closed date, YYYY-MM-DD. Defaults to today.')]
  public function punchClose(int $nid, array $options = ['answer' => '', 'by' => 'site-foreman', 'date' => NULL]): int {
    $answer = trim((string) $options['answer']);
    if ($answer === '') {
      throw new \InvalidArgumentException('--answer is required: closing an item records its answer.');
    }
    $this->refuseSecrets($answer);
    $by = Vocabulary::normalize(Vocabulary::PEOPLE, (string) $options['by']);
    $node = $this->office->closePunch($nid, $answer, $by, $this->date($options['date']));
    $this->logger()->success(sprintf('Closed punch list item %d: %s', $node->id(), $node->label()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Reopens a punch list item that was closed by mistake.
   */
  #[CLI\Command(name: 'ishop:punch-reopen')]
  #[CLI\Argument(name: 'nid', description: 'Node ID of the punch list item.')]
  #[CLI\Option(name: 'why', description: 'Why it is reopened (kept in the revision log). Required.')]
  public function punchReopen(int $nid, array $options = ['why' => '']): int {
    $why = trim((string) $options['why']);
    if ($why === '') {
      throw new \InvalidArgumentException('--why is required.');
    }
    $this->refuseSecrets($why);
    $node = $this->office->reopenPunch($nid, $why);
    $this->logger()->success(sprintf('Reopened punch list item %d: %s', $node->id(), $node->label()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Adds a decision to the decision log.
   */
  #[CLI\Command(name: 'ishop:decision')]
  #[CLI\Argument(name: 'decision', description: 'What was decided, up to 255 characters.')]
  #[CLI\Option(name: 'by', description: 'Comma-separated: liam, cody, site-foreman, claude (review).')]
  #[CLI\Option(name: 'why', description: 'Why (Markdown).')]
  #[CLI\Option(name: 'phase', description: 'Phase number it belongs to.')]
  #[CLI\Option(name: 'date', description: 'Decision date, YYYY-MM-DD. Defaults to today.')]
  #[CLI\Option(name: 'crew-only', description: 'Hide it from the owner (only webmaster and site-foreman see it).')]
  public function decision(
    string $decision,
    array $options = ['by' => '', 'why' => '', 'phase' => NULL, 'date' => NULL, 'crew-only' => FALSE],
  ): int {
    $this->office->setCrewOnly((bool) $options['crew-only']);
    $this->refuseSecrets($decision . "\n" . $options['why']);
    $node = $this->office->addDecision($decision, $this->people((string) $options['by']), (string) $options['why'], OptionValues::phase($options['phase']), $this->date($options['date']));
    $this->logger()->success(sprintf('Decision %d logged: %s', $node->id(), $node->label()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Hides a report, punch item or decision from the owner, or shows it again.
   */
  #[CLI\Command(name: 'ishop:crew-only')]
  #[CLI\Argument(name: 'nid', description: 'A report, punch item or decision.')]
  #[CLI\Option(name: 'off', description: 'Make it visible to the owner again.')]
  public function crewOnly(int $nid, array $options = ['off' => FALSE]): int {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException(sprintf('There is no node %d.', $nid));
    }
    $this->office->markCrewOnly($node, !$options['off']);
    $this->logger()->success(sprintf('%d "%s" is now %s.', $nid, $node->label(), $options['off'] ? 'visible to the owner' : 'crew only (hidden from the owner)'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints the status board and open punch list.
   */
  #[CLI\Command(name: 'ishop:office')]
  public function show(): int {
    $rows = [];
    foreach ($this->office->statusBoard(FALSE) as $phase) {
      $rows[] = [
        $phase['key'],
        $phase['status_label'],
        $phase['percent_done'] === NULL ? '-' : $phase['percent_done'] . '%',
        $phase['checklist_done'] . '/' . $phase['checklist_total'],
        $phase['latest_report']['date'] ?? '-',
      ];
    }
    $this->io()->table(['Phase', 'Status', 'Done', 'Checklist', 'Latest report'], $rows);
    $rows = [];
    foreach ($this->office->punchItems('open', FALSE) as $item) {
      $rows[] = [
        $item->id(),
        implode(', ', array_column($item->get('field_waiting_on')->getValue(), 'value')),
        $item->get('field_office_date')->value,
        $item->label(),
      ];
    }
    $this->io()->table(['ID', 'Waiting on', 'Opened', 'Open punch list item'], $rows);
    return self::EXIT_SUCCESS;
  }

  /**
   * Reads a file or stops with a clear message.
   */
  private function read(string $file): string {
    if (!is_file($file) || !is_readable($file)) {
      throw new \RuntimeException(sprintf('Cannot read %s.', $file));
    }
    return (string) file_get_contents($file);
  }

  /**
   * Stops if the text looks like it holds a secret or personal data.
   */
  private function refuseSecrets(string $text): void {
    $findings = SecretScanner::scan($text);
    if ($findings) {
      throw new \RuntimeException("Refusing to save: this looks like it contains a secret or personal data.\n  " . implode("\n  ", $findings) . "\nRemove it (or reword it) and try again.");
    }
  }

  /**
   * Turns "liam, Cody" into Vocabulary::PEOPLE keys.
   *
   * @return list<string>
   *   Vocabulary::PEOPLE keys.
   */
  private function people(string $list): array {
    $people = array_filter(array_map('trim', explode(',', $list)));
    if (!$people) {
      throw new \InvalidArgumentException('Name at least one person: liam, cody, site-foreman or claude.');
    }
    return array_values(array_unique(array_map(fn ($p) => Vocabulary::normalize(Vocabulary::PEOPLE, $p), $people)));
  }

  /**
   * Validates an optional YYYY-MM-DD option.
   */
  private function date(?string $date): ?string {
    if ($date !== NULL && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      throw new \InvalidArgumentException('Dates are YYYY-MM-DD.');
    }
    return $date;
  }

}
