<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ishop_office\OfficeManager;
use Drupal\ishop_office\Vocabulary;
use Drupal\node\NodeInterface;

/**
 * The site office pages: plain headings, plain tables, no tabs or scripts.
 */
final class OfficeController extends ControllerBase {

  public function __construct(
    private readonly OfficeManager $office,
  ) {}

  /**
   * Links to every office page, shown at the top of each.
   */
  public static function nav(): array {
    $links = [
      'Status board' => 'ishop_office.board',
      'Plans' => NULL,
      'Build reports' => 'ishop_office.reports',
      'Punch list' => 'ishop_office.punch_list',
      'Decision log' => 'ishop_office.decisions',
      'status.json' => 'ishop_office.status_json',
    ];
    $items = [];
    foreach ($links as $title => $route) {
      $url = $route ? Url::fromRoute($route) : Url::fromUserInput('/site-office/plans');
      $items[] = Link::fromTextAndUrl($title, $url)->toRenderable();
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'nav',
      '#attributes' => ['aria-label' => 'Site office', 'class' => ['ishop-office-nav']],
      'list' => ['#theme' => 'item_list', '#items' => $items],
    ];
  }

  /**
   * The landing page: status board, open punch list, recent activity.
   */
  public function board(): array {
    $build = $this->page();
    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Like the trailer on a construction site: the plans on the wall and the daily reports. Percent done counts the ticked checklist items under each phase in ROADMAP.md. All dates are YYYY-MM-DD.'),
    ];

    $rows = [];
    foreach ($this->office->statusBoard() as $phase) {
      $report = $phase['latest_report'];
      $rows[] = [
        Link::fromTextAndUrl($phase['name'], Url::fromUri($phase['url']))->toString(),
        $phase['status_label'],
        $phase['percent_done'] === NULL ? $this->t('No checklist') : $phase['percent_done'] . '%',
        $phase['checklist_done'] . ' of ' . $phase['checklist_total'],
        substr($phase['last_updated'], 0, 10),
        $report ? Link::fromTextAndUrl($report['title'], Url::fromUri($report['url']))->toString() : $this->t('None yet'),
      ];
    }
    $build['status'] = $this->section($this->t('Status board'), $this->table(
      $this->t('One row per roadmap phase.'),
      ['Phase', 'Status', 'Done', 'Checklist', 'Last update', 'Latest report'],
      $rows,
      $this->t('No phases yet. Run drush ishop:plans.'),
    ));

    $build['punch'] = $this->section($this->t('Waiting on someone'), $this->punchTable($this->office->punchItems('open'), FALSE));
    $build['reports'] = $this->section($this->t('Latest build reports'), $this->reportTable($this->office->reports(5)));
    $build['decisions'] = $this->section($this->t('Recent decisions'), $this->decisionTable($this->office->decisions(5), FALSE));
    $build['json'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      'link' => [
        '#markup' => $this->t('Machine-readable status: <a href=":url">/site-office/status.json</a>', [':url' => Url::fromRoute('ishop_office.status_json')->toString()]),
      ],
    ];
    return $build;
  }

  /**
   * All build reports, newest first.
   */
  public function reports(): array {
    $build = $this->page();
    $build['reports'] = $this->reportTable($this->office->reports());
    return $build;
  }

  /**
   * Open items, then closed items with their answers.
   */
  public function punchList(): array {
    $build = $this->page();
    $build['open'] = $this->section($this->t('Open'), $this->punchTable($this->office->punchItems('open'), FALSE));
    $build['closed'] = $this->section($this->t('Closed'), $this->punchTable($this->office->punchItems('closed'), TRUE));
    return $build;
  }

  /**
   * Every decision, newest first.
   */
  public function decisions(): array {
    $build = $this->page();
    $build['decisions'] = $this->decisionTable($this->office->decisions(), TRUE);
    return $build;
  }

  /**
   * Done percentage and related content, for a phase's own page.
   */
  public function phaseSections(NodeInterface $phase): array {
    $done = (int) $phase->get('field_checklist_done')->value;
    $total = (int) $phase->get('field_checklist_total')->value;
    $build['done'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $total
        ? $this->t('Done: @percent% (@done of @total checklist items ticked in ROADMAP.md).', [
          '@percent' => (int) floor(100 * $done / $total),
          '@done' => $done,
          '@total' => $total,
        ])
        : $this->t('Done: this phase has no checklist items in ROADMAP.md.'),
      '#weight' => 1,
    ];
    $build['reports'] = $this->section($this->t('Build reports for this phase'), $this->reportTable($this->office->forPhase('office_report', $phase))) + ['#weight' => 200];
    $build['punch'] = $this->section($this->t('Punch list items for this phase'), $this->punchTable($this->office->forPhase('office_punch', $phase), FALSE)) + ['#weight' => 201];
    $build['decisions'] = $this->section($this->t('Decisions for this phase'), $this->decisionTable($this->office->forPhase('office_decision', $phase), FALSE)) + ['#weight' => 202];
    $this->cacheability()->applyTo($build);
    return $build;
  }

  /**
   * Adds the office's cacheability to an existing render array.
   */
  public function addCacheability(array &$build): void {
    CacheableMetadata::createFromRenderArray($build)->merge($this->cacheability())->applyTo($build);
  }

  /**
   * The status board as JSON.
   */
  public function statusJson(): CacheableJsonResponse {
    $data = [
      'site_office' => Url::fromRoute('ishop_office.board', [], ['absolute' => TRUE])->toString(),
      'plans' => Url::fromUserInput('/site-office/plans', ['absolute' => TRUE])->toString(),
      'phase_statuses' => array_values(Vocabulary::PHASE_STATUS),
      'open_punch_items' => count($this->office->punchItems('open')),
      'phases' => $this->office->statusBoard(),
    ];
    $response = new CacheableJsonResponse($data);
    $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    $response->addCacheableDependency($this->cacheability());
    return $response;
  }

  /**
   * The common start of every office page.
   */
  private function page(): array {
    $build = ['#attached' => ['library' => ['ishop_office/office']]];
    $build['nav'] = self::nav();
    $this->cacheability()->applyTo($build);
    return $build;
  }

  /**
   * Office pages change whenever any node changes, and differ by permission.
   */
  private function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->setCacheTags(['node_list'])
      ->setCacheContexts(['user.permissions', 'user.node_grants:view']);
  }

  /**
   * An <h2> followed by its content.
   */
  private function section($title, array $content): array {
    return [
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $title],
      'content' => $content,
    ];
  }

  /**
   * A table that turns into labelled blocks on narrow screens (see CSS).
   */
  private function table($caption, array $header, array $rows, $empty): array {
    $labelled = [];
    foreach ($rows as $row) {
      $cells = [];
      foreach (array_values($row) as $i => $cell) {
        $cells[] = ['data' => $cell, 'data-label' => $header[$i]];
      }
      $labelled[] = $cells;
    }
    return [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $header,
      '#rows' => $labelled,
      '#empty' => $empty,
      // No responsive-table script: every column stays in the HTML.
      '#responsive' => FALSE,
      '#attributes' => ['class' => ['ishop-office-table']],
    ];
  }

  /**
   * Build reports as a table.
   */
  private function reportTable(array $reports): array {
    $rows = [];
    foreach ($reports as $report) {
      $rows[] = [
        (string) $report->get('field_office_date')->value,
        $report->toLink()->toString(),
        $this->phaseLink($report),
        $this->markdown($report, 'field_summary'),
        implode(', ', array_column($report->get('field_commits')->getValue(), 'value')) ?: '-',
      ];
    }
    return $this->table($this->t('Newest first.'), ['Date', 'Report', 'Phase', 'Summary', 'Commits'], $rows, $this->t('No build reports yet.'));
  }

  /**
   * Punch list items as a table; closed items add closed date and answer.
   */
  private function punchTable(array $items, bool $closed): array {
    $rows = [];
    foreach ($items as $item) {
      $row = [
        $item->toLink()->toString(),
        $this->people($item, 'field_waiting_on'),
        (string) $item->get('field_office_date')->value,
        $this->phaseLink($item),
      ];
      if ($closed) {
        $row[] = (string) $item->get('field_closed_date')->value;
        $row[] = $this->markdown($item, 'field_answer');
        $row[] = $this->people($item, 'field_answered_by');
      }
      else {
        $row[] = Link::fromTextAndUrl($this->t('Answer it'), $item->toUrl('canonical', ['fragment' => 'answer']))->toString();
      }
      $rows[] = $row;
    }
    $header = ['Question', 'Waiting on', 'Opened', 'Phase'];
    if ($closed) {
      array_push($header, 'Closed', 'Answer', 'Answered by');
    }
    else {
      $header[] = 'Answer';
    }
    return $this->table(
      $closed ? $this->t('Newest closed first.') : $this->t('Oldest first.'),
      $header,
      $rows,
      $closed ? $this->t('Nothing closed yet.') : $this->t('Nothing is waiting on anyone.'),
    );
  }

  /**
   * Decisions as a table, with or without the why and phase columns.
   */
  private function decisionTable(array $decisions, bool $why): array {
    $rows = [];
    foreach ($decisions as $decision) {
      $row = [
        (string) $decision->get('field_office_date')->value,
        $decision->toLink()->toString(),
        $this->people($decision, 'field_decided_by'),
      ];
      if ($why) {
        $row[] = $this->markdown($decision, 'field_why');
        $row[] = $this->phaseLink($decision);
      }
      $rows[] = $row;
    }
    $header = $why ? ['Date', 'Decision', 'Decided by', 'Why', 'Phase'] : ['Date', 'Decision', 'Decided by'];
    return $this->table($this->t('Newest first.'), $header, $rows, $this->t('No decisions logged yet.'));
  }

  /**
   * A link to the node's phase, or a dash.
   */
  private function phaseLink(NodeInterface $node) {
    $phase = $node->get('field_office_phase')->entity;
    return $phase && $phase->access('view') ? $phase->toLink()->toString() : '-';
  }

  /**
   * Labels of the people in a list field, comma separated.
   */
  private function people(NodeInterface $node, string $field): string {
    $keys = array_column($node->get($field)->getValue(), 'value');
    return implode(', ', array_map(fn ($key) => Vocabulary::PEOPLE[$key] ?? $key, $keys)) ?: '-';
  }

  /**
   * Renders a Markdown field through the office text format.
   */
  private function markdown(NodeInterface $node, string $field): array|string {
    $item = $node->get($field)->first();
    if (!$item || $item->value === NULL || $item->value === '') {
      return '-';
    }
    return ['#type' => 'processed_text', '#text' => $item->value, '#format' => $item->format];
  }

}
