<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\ishop_office\Parser\ParseException;
use Drupal\ishop_office\Parser\RoadmapParser;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\user\UserInterface;

/**
 * Reads and writes site office content.
 *
 * Everything written here is attributed to the site-foreman account, both as
 * author and as revision author, by switching to that account while saving.
 */
final class OfficeManager {

  public const FOREMAN = 'site-foreman';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AliasRepositoryInterface $aliasRepository,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Syncs the plans page and the status board rows from ROADMAP.md.
   *
   * Phase status is never changed here, only names, order and checklist
   * counts. Phases no longer in the file are reported, not deleted.
   *
   * @return array{plan: string, created: list<string>, updated: list<string>, unchanged: list<string>, orphaned: list<string>}
   *   What happened, by phase key ("plan" is created/updated/unchanged).
   */
  public function syncPlans(string $markdown, string $source): array {
    $roadmap = RoadmapParser::parse($markdown);
    $result = ['plan' => 'unchanged', 'created' => [], 'updated' => [], 'unchanged' => [], 'orphaned' => []];

    $this->asForeman(function (UserInterface $foreman) use ($roadmap, $source, &$result): void {
      $plan = $this->plan();
      $message = sprintf('Synced from %s.', $source);
      if (!$plan) {
        $plan = $this->newNode('office_plan', $roadmap['title'], $foreman);
        $plan->set('path', ['alias' => '/site-office/plans']);
        $result['plan'] = 'created';
      }
      if ($result['plan'] === 'created' || $plan->label() !== $roadmap['title'] || $plan->get('field_office_markdown')->value !== $roadmap['body']) {
        $plan->setTitle($roadmap['title']);
        $plan->set('field_office_markdown', ['value' => $roadmap['body'], 'format' => OfficeStructure::FORMAT]);
        $this->saveRevision($plan, $foreman, $message);
        $result['plan'] = $result['plan'] === 'created' ? 'created' : 'updated';
      }

      $existing = [];
      foreach ($this->phases(FALSE) as $phase) {
        $existing[$phase->get('field_phase_key')->value] = $phase;
      }
      foreach ($roadmap['phases'] as $row) {
        $values = [
          'title' => $row['heading'],
          'field_phase_weight' => $row['weight'],
          'field_checklist_done' => $row['done'],
          'field_checklist_total' => $row['total'],
          'field_office_markdown' => $row['markdown'],
        ];
        $phase = $existing[$row['key']] ?? NULL;
        unset($existing[$row['key']]);
        if (!$phase) {
          $phase = $this->newNode('office_phase', $row['heading'], $foreman);
          $phase->set('field_phase_key', $row['key']);
          $phase->set('field_phase_status', 'not_started');
          $phase->set('path', ['alias' => '/site-office/phases/' . str_replace('.', '-', $row['key'])]);
          $bucket = 'created';
        }
        else {
          $bucket = 'unchanged';
          foreach ($values as $field => $value) {
            if ((string) ($field === 'title' ? $phase->label() : $phase->get($field)->value) !== (string) $value) {
              $bucket = 'updated';
            }
          }
          if ($bucket === 'unchanged') {
            $result['unchanged'][] = $row['key'];
            continue;
          }
        }
        foreach ($values as $field => $value) {
          $phase->set($field, $field === 'field_office_markdown' ? ['value' => $value, 'format' => OfficeStructure::FORMAT] : $value);
        }
        $this->saveRevision($phase, $foreman, sprintf('Checklist %d/%d. %s', $row['done'], $row['total'], $message));
        $result[$bucket][] = $row['key'];
      }
      $result['orphaned'] = array_keys($existing);
    });

    return $result;
  }

  /**
   * Files a parsed build report and updates its phase on the status board.
   *
   * @param array $report
   *   The output of ReportParser::parse().
   */
  public function fileReport(array $report, string $source): NodeInterface {
    $phase = $this->phase($report['phase']);
    if (!$phase) {
      throw new ParseException(sprintf('There is no phase "%s" on the status board. Run drush ishop:plans first, or fix the phase number.', $report['phase']));
    }

    return $this->asForeman(function (UserInterface $foreman) use ($report, $phase, $source): NodeInterface {
      $title = sprintf('%s: %s', $report['date'], $report['title'] ?? sprintf('Phase %s build report', $report['phase']));
      $node = $this->newNode('office_report', mb_substr($title, 0, 255), $foreman);
      $node->set('field_office_date', $report['date']);
      $node->set('field_office_phase', $phase);
      foreach ($report['sections'] as $key => $markdown) {
        $node->set('field_' . $key, ['value' => $markdown, 'format' => OfficeStructure::FORMAT]);
      }
      $node->set('field_commits', $report['commits']);
      $node->set('path', ['alias' => $this->uniqueAlias('/site-office/reports/' . $report['date'])]);
      $this->saveRevision($node, $foreman, sprintf('Filed with drush ishop:report from %s.', $source));

      // The newest report by date becomes the phase's latest report.
      $latest = $phase->get('field_latest_report')->entity;
      $changes = [];
      if (!$latest || $latest->get('field_office_date')->value <= $report['date']) {
        $phase->set('field_latest_report', $node);
        $changes[] = 'latest report';
      }
      if ($report['phase_status'] && $report['phase_status'] !== $phase->get('field_phase_status')->value) {
        $phase->set('field_phase_status', $report['phase_status']);
        $changes[] = 'status ' . Vocabulary::PHASE_STATUS[$report['phase_status']];
      }
      if ($changes) {
        $this->saveRevision($phase, $foreman, sprintf('Updated %s from report "%s".', implode(' and ', $changes), $node->label()));
      }
      return $node;
    });
  }

  /**
   * Sets a phase's status on the status board.
   */
  public function setPhaseStatus(string $key, string $status, string $why): NodeInterface {
    $phase = $this->phase($key) ?? throw new \InvalidArgumentException(sprintf('No phase "%s". Run drush ishop:plans first.', $key));
    return $this->asForeman(function (UserInterface $foreman) use ($phase, $status, $why): NodeInterface {
      $phase->set('field_phase_status', $status);
      $this->saveRevision($phase, $foreman, trim('Status set to ' . Vocabulary::PHASE_STATUS[$status] . '. ' . $why));
      return $phase;
    });
  }

  /**
   * Adds an open punch list item.
   *
   * @param list<string> $waiting_on
   *   Vocabulary::PEOPLE keys.
   */
  public function addPunch(string $question, array $waiting_on, ?string $phase_key, string $details, ?string $date): NodeInterface {
    $phase = $phase_key === NULL ? NULL : ($this->phase($phase_key) ?? throw new \InvalidArgumentException(sprintf('No phase "%s".', $phase_key)));
    return $this->asForeman(function (UserInterface $foreman) use ($question, $waiting_on, $phase, $details, $date): NodeInterface {
      $node = $this->newNode('office_punch', $this->title($question), $foreman);
      $node->set('field_punch_status', 'open');
      $node->set('field_waiting_on', $waiting_on);
      $node->set('field_office_date', $date ?? $this->today());
      $node->set('field_office_phase', $phase);
      if ($details !== '') {
        $node->set('field_details', ['value' => $details, 'format' => OfficeStructure::FORMAT]);
      }
      $this->saveRevision($node, $foreman, 'Opened with drush ishop:punch.');
      return $node;
    });
  }

  /**
   * Closes a punch list item, recording the answer.
   */
  public function closePunch(int $nid, string $answer, ?string $date): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'office_punch') {
      throw new \InvalidArgumentException(sprintf('Node %d is not a punch list item.', $nid));
    }
    if ($node->get('field_punch_status')->value === 'closed') {
      throw new \InvalidArgumentException(sprintf('Punch list item %d is already closed.', $nid));
    }
    return $this->asForeman(function (UserInterface $foreman) use ($node, $answer, $date): NodeInterface {
      $node->set('field_punch_status', 'closed');
      $node->set('field_answer', ['value' => $answer, 'format' => OfficeStructure::FORMAT]);
      $node->set('field_closed_date', $date ?? $this->today());
      $this->saveRevision($node, $foreman, 'Closed with drush ishop:punch-close.');
      return $node;
    });
  }

  /**
   * Adds a decision to the decision log.
   *
   * @param list<string> $decided_by
   *   Vocabulary::PEOPLE keys.
   */
  public function addDecision(string $decision, array $decided_by, string $why, ?string $phase_key, ?string $date): NodeInterface {
    $phase = $phase_key === NULL ? NULL : ($this->phase($phase_key) ?? throw new \InvalidArgumentException(sprintf('No phase "%s".', $phase_key)));
    return $this->asForeman(function (UserInterface $foreman) use ($decision, $decided_by, $why, $phase, $date): NodeInterface {
      $node = $this->newNode('office_decision', $this->title($decision), $foreman);
      $node->set('field_office_date', $date ?? $this->today());
      $node->set('field_decided_by', $decided_by);
      $node->set('field_office_phase', $phase);
      if ($why !== '') {
        $node->set('field_why', ['value' => $why, 'format' => OfficeStructure::FORMAT]);
      }
      $this->saveRevision($node, $foreman, 'Logged with drush ishop:decision.');
      return $node;
    });
  }

  /**
   * The plans page node, if synced.
   */
  public function plan(): ?NodeInterface {
    $nodes = $this->load('office_plan', [], ['nid' => 'ASC'], FALSE, 1);
    return $nodes ? reset($nodes) : NULL;
  }

  /**
   * One phase by its key ("0.5").
   */
  public function phase(string $key): ?NodeInterface {
    $nodes = $this->load('office_phase', ['field_phase_key' => $key], [], FALSE, 1);
    return $nodes ? reset($nodes) : NULL;
  }

  /**
   * Phases in roadmap order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  public function phases(bool $access_check = TRUE): array {
    return $this->load('office_phase', [], ['field_phase_weight' => 'ASC', 'nid' => 'ASC'], $access_check);
  }

  /**
   * Build reports, newest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  public function reports(?int $limit = NULL, bool $access_check = TRUE): array {
    return $this->load('office_report', [], ['field_office_date' => 'DESC', 'nid' => 'DESC'], $access_check, $limit);
  }

  /**
   * Punch list items with a status, oldest open first or newest closed first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  public function punchItems(string $status, bool $access_check = TRUE): array {
    $sort = $status === 'open' ? ['field_office_date' => 'ASC', 'nid' => 'ASC'] : ['field_closed_date' => 'DESC', 'nid' => 'DESC'];
    return $this->load('office_punch', ['field_punch_status' => $status], $sort, $access_check);
  }

  /**
   * Reports, punch list items or decisions linked to one phase, newest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  public function forPhase(string $bundle, NodeInterface $phase): array {
    return $this->load($bundle, ['field_office_phase' => $phase->id()], ['field_office_date' => 'DESC', 'nid' => 'DESC'], TRUE);
  }

  /**
   * Decisions, newest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  public function decisions(?int $limit = NULL, bool $access_check = TRUE): array {
    return $this->load('office_decision', [], ['field_office_date' => 'DESC', 'nid' => 'DESC'], $access_check, $limit);
  }

  /**
   * The status board as plain data, shared by the page, JSON and Drush.
   *
   * @return list<array{key: string, name: string, status: string, status_label: string, percent_done: ?int, checklist_done: int, checklist_total: int, last_updated: string, url: string, latest_report: ?array{title: string, date: string, url: string}}>
   *   One entry per phase, in roadmap order. Dates are ISO 8601.
   */
  public function statusBoard(bool $access_check = TRUE): array {
    $rows = [];
    foreach ($this->phases($access_check) as $phase) {
      $done = (int) $phase->get('field_checklist_done')->value;
      $total = (int) $phase->get('field_checklist_total')->value;
      $status = (string) $phase->get('field_phase_status')->value;
      $report = $phase->get('field_latest_report')->entity;
      if ($report && $access_check && !$report->access('view')) {
        $report = NULL;
      }
      $rows[] = [
        'key' => (string) $phase->get('field_phase_key')->value,
        'name' => $phase->label(),
        'status' => $status,
        'status_label' => Vocabulary::PHASE_STATUS[$status] ?? $status,
        'percent_done' => RoadmapParser::percent($done, $total),
        'checklist_done' => $done,
        'checklist_total' => $total,
        'last_updated' => date('c', (int) $phase->getChangedTime()),
        'url' => $phase->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'latest_report' => $report ? [
          'title' => $report->label(),
          'date' => (string) $report->get('field_office_date')->value,
          'url' => $report->toUrl('canonical', ['absolute' => TRUE])->toString(),
        ] : NULL,
      ];
    }
    return $rows;
  }

  /**
   * Runs a callback as site-foreman, passing that account.
   */
  private function asForeman(callable $callback): mixed {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => self::FOREMAN]);
    $foreman = reset($users);
    if (!$foreman instanceof UserInterface) {
      throw new \RuntimeException(sprintf('The %s account does not exist.', self::FOREMAN));
    }
    $this->accountSwitcher->switchTo($foreman);
    try {
      return $callback($foreman);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  private function newNode(string $bundle, string $title, UserInterface $owner): NodeInterface {
    return $this->entityTypeManager->getStorage('node')->create([
      'type' => $bundle,
      'title' => $title,
      'uid' => $owner->id(),
      'status' => 1,
      'promote' => 0,
    ]);
  }

  private function saveRevision(NodeInterface $node, UserInterface $foreman, string $message): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $foreman->id());
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionLogMessage($message);
    $node->save();
  }

  /**
   * Loads nodes of one bundle.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by node ID.
   */
  private function load(string $bundle, array $conditions, array $sort, bool $access_check, ?int $limit = NULL): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck($access_check)->condition('type', $bundle);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    foreach ($sort as $field => $direction) {
      $query->sort($field, $direction);
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    return $storage->loadMultiple($query->execute());
  }

  private function uniqueAlias(string $base): string {
    for ($n = 1; ; $n++) {
      $alias = $base . '-' . $n;
      if (!$this->aliasRepository->lookupByAlias($alias, 'und') && !$this->aliasRepository->lookupByAlias($alias, 'en')) {
        return $alias;
      }
    }
  }

  private function title(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '' || mb_strlen($text) > 255) {
      throw new \InvalidArgumentException('The text must be 1 to 255 characters. Put longer explanations in the details or why field.');
    }
    return $text;
  }

  private function today(): string {
    return (new DrupalDateTime('now'))->format('Y-m-d');
  }

}
