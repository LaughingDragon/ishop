<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ishop_office\DiskStatus;
use Drupal\ishop_office\OfficeManager;
use Drupal\ishop_office\Vocabulary;
use Drupal\node\NodeInterface;

/**
 * The site office pages: plain headings, plain tables, no tabs or scripts.
 *
 * Markup follows the Construction theme's markup contract (see its README and
 * preview/site-office.html); it still reads as plain semantic HTML without it.
 */
final class OfficeController extends ControllerBase {

  public function __construct(
    private readonly OfficeManager $office,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The office tabs (so-nav), with the current page marked.
   */
  public static function nav(): array {
    $path = \Drupal::request()->getPathInfo();
    $links = [
      ['Status board', '/site-office', $path === '/site-office'],
      ['Plans', '/site-office/plans', $path === '/site-office/plans'],
      ['Build reports', '/site-office/reports', str_starts_with($path, '/site-office/reports')],
      ['Punch list', '/site-office/punch-list', $path === '/site-office/punch-list'],
      ['Decision log', '/site-office/decisions', $path === '/site-office/decisions'],
    ];
    return [
      '#type' => 'inline_template',
      '#template' => '<nav class="so-nav" aria-label="Site office">{% for l in links %}<a href="{{ l.url }}"{% if l.current %} aria-current="page"{% endif %}>{{ l.title }}</a>{% endfor %}</nav>',
      '#context' => [
        'links' => array_map(fn ($l) => [
          'title' => $l[0],
          'url' => Url::fromUserInput($l[1])->toString(),
          'current' => $l[2],
        ], $links),
      ],
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

  /**
   * A road-sign status pill (so-pill) for a phase status key.
   */
  public static function pill(string $status): array {
    $modifier = [
      'done' => 'done',
      'in_progress' => 'progress',
      'blocked' => 'blocked',
    ][$status] ?? 'idle';
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="so-pill so-pill--{{ modifier }}">{{ label }}</span>',
      '#context' => ['modifier' => $modifier, 'label' => Vocabulary::PHASE_STATUS[$status] ?? $status],
    ];
  }

  /**
   * A caution-tape progress bar with its percentage (so-progress-row).
   */
  public static function progress(?int $percent): array {
    if ($percent === NULL) {
      return ['#markup' => t('No checklist')];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="so-progress-row"><progress class="so-progress{% if p == 100 %} is-done{% endif %}" value="{{ p }}" max="100">{{ p }}%</progress><span class="so-progress-row__pct">{{ p }}%</span></div>',
      '#context' => ['p' => $percent],
    ];
  }

  /**
   * The landing page: summary sign, status board, punch list, recent activity.
   */
  public function board(): array {
    $build = $this->page();
    $phases = $this->office->statusBoard();
    $open = $this->office->punchItems('open');
    $done = array_sum(array_column($phases, 'checklist_done'));
    $total = array_sum(array_column($phases, 'checklist_total'));
    $latest = $this->office->reports(1);
    $latest = $latest ? (string) reset($latest)->get('field_office_date')->value : NULL;
    $build['sign'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-sign" aria-labelledby="so-sign-title"><div class="so-sign__inner"><div class="so-sign__text"><h2 id="so-sign-title">{{ title }}</h2><p>{{ text }}</p></div><div class="so-stats"><div class="so-stat"><span class="so-stat__value">{{ percent }}%</span><span class="so-stat__label">of the plan built</span></div><div class="so-stat"><span class="so-stat__value">{{ open }}</span><span class="so-stat__label">{{ open == 1 ? \'punch item open\' : \'punch items open\' }}</span></div></div></div></section>',
      '#context' => [
        'title' => $total && $done === $total ? $this->t('All built') : $this->t('Work in progress'),
        'text' => $this->t('@done of @total checklist items finished across @phases phases. Last report filed @date. Percent done counts the ticked items in ROADMAP.md.', [
          '@done' => $done,
          '@total' => $total,
          '@phases' => count($phases),
          '@date' => $latest ?? $this->t('never'),
        ]),
        'percent' => $total ? (int) floor(100 * $done / $total) : 0,
        'open' => count($open),
      ],
    ];

    $rows = [];
    foreach ($phases as $phase) {
      $report = $phase['latest_report'];
      $rows[] = [
        Link::fromTextAndUrl($phase['name'], Url::fromUri($phase['url']))->toString(),
        ['data' => self::pill($phase['status'])],
        ['data' => self::progress($phase['percent_done'])],
        ['data' => ['#markup' => '<code>' . $phase['checklist_done'] . ' / ' . $phase['checklist_total'] . '</code>']],
        ['data' => $this->time(substr($phase['last_updated'], 0, 10))],
        $report ? [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<a href="{{ url }}" title="{{ title }}"><time datetime="{{ date }}">{{ date }}</time></a>',
            '#context' => $report,
          ],
        ] : $this->t('None yet'),
      ];
    }
    $build['status'] = $this->section($this->t('Status board'), $this->table(
      ['Phase', 'Status', 'Progress', 'Checklist', 'Last update', 'Latest report'],
      $rows,
      $this->t('No phases yet. Run drush ishop:plans.'),
    ));

    $build['ask_cody'] = $this->askCodyCallout() + ['#weight' => -5];
    $build['storage'] = $this->storageNote();
    $build['punch'] = $this->punchCallout($open, $this->t('Punch list: waiting on someone'));
    $build['reports'] = $this->section($this->t('Latest build reports'), $this->reportCards($this->office->reports(5)));
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
    $build['reports'] = $this->reportCards($this->office->reports());
    return $build;
  }

  /**
   * Open items, then closed items with their answers.
   */
  public function punchList(): array {
    $build = $this->page();
    $build['open'] = $this->punchCallout($this->office->punchItems('open'), $this->t('Open: waiting on someone'));
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
      'pill' => self::pill((string) $phase->get('field_phase_status')->value),
      'bar' => self::progress($total ? (int) floor(100 * $done / $total) : NULL),
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $total
          ? $this->t('Done: @percent% (@done of @total checklist items ticked in ROADMAP.md).', [
            '@percent' => (int) floor(100 * $done / $total),
            '@done' => $done,
            '@total' => $total,
          ])
          : $this->t('Done: this phase has no checklist items in ROADMAP.md.'),
      ],
      '#weight' => 1,
    ];
    $build['reports'] = $this->section($this->t('Build reports for this phase'), $this->reportCards($this->office->forPhase('office_report', $phase))) + ['#weight' => 200];
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
      'storage' => DiskStatus::report(),
    ];
    $response = new CacheableJsonResponse($data);
    $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    $response->addCacheableDependency($this->cacheability());
    // Disk state changes without any Drupal event, so it is never cached.
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

  /**
   * The style questionnaire, for whoever may fill it in (Cody).
   *
   * A big link until answers are sent; "Continue" when a draft exists.
   */
  private function askCodyCallout(): array {
    $build = [
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list', 'config:webform.webform.ask_cody'],
      ],
    ];
    $webform = $this->entityTypeManager()->getStorage('webform')->load('ask_cody');
    if (!$webform || !$webform->access('submission_create') || !$webform->isOpen()) {
      return $build;
    }
    $storage = $this->entityTypeManager()->getStorage('webform_submission');
    $mine = $storage->loadByProperties(['webform_id' => 'ask_cody', 'uid' => $this->currentUser()->id()]);
    $sent = array_filter($mine, fn ($s) => !$s->isDraft());
    $draft = array_filter($mine, fn ($s) => $s->isDraft());
    $url = $webform->toUrl()->toString();
    $build += [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if not sent %} so-callout--warn{% endif %} ishop-ask-cody" aria-labelledby="ask-cody-title"><div class="so-callout__body"><h2 id="ask-cody-title">{{ title }}</h2>{% if sent %}<p>{{ thanks }}</p>{% else %}<p><a class="button button--hazard ishop-ask-cody__go" href="{{ url }}">{{ label }}</a></p><p>{{ hint }}</p>{% endif %}</div></section>',
      '#context' => [
        'sent' => (bool) $sent,
        'title' => $sent ? $this->t('Thanks for your style answers') : $this->t('Your turn: the look of your site'),
        'thanks' => $this->t('Liam and Claude are working from them. Two or three looks will show up here for you to choose from.'),
        'url' => $url,
        'label' => $draft ? $this->t('Continue: help design your site') : $this->t('Help design your site (about 10 min)'),
        'hint' => $this->t('One question at a time. Skip anything. Your answers save as you go.'),
      ],
    ];
    return $build;
  }

  /**
   * Who may read Cody's answers: whoever may view all ask_cody submissions.
   */
  public static function askCodyResultsAccess(AccountInterface $account): AccessResultInterface {
    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('ask_cody');
    if (!$webform) {
      return AccessResult::forbidden()->addCacheTags(['config:webform_list']);
    }
    // Named on the form's "view any" rule (webmaster), not just anyone whose
    // role passes Webform's check (Cody's administrator role would).
    $named = array_map('intval', $webform->getAccessRules()['view_any']['users'] ?? []);
    return AccessResult::allowedIf(in_array((int) $account->id(), $named, TRUE))
      ->addCacheableDependency($webform)->cachePerUser();
  }

  /**
   * Cody's answers, readable in the site office (drafts included).
   *
   * For Liam, and Claude (review) reading through Liam's browser.
   */
  public function askCodyResults(): array {
    $build = $this->page();
    $storage = $this->entityTypeManager()->getStorage('webform_submission');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('webform_id', 'ask_cody')
      ->sort('changed', 'DESC')
      ->execute();
    $builder = $this->entityTypeManager()->getViewBuilder('webform_submission');
    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $ids
        ? $this->t('Newest first. A draft means Cody started but has not pressed "Send my answers" yet; it shows what he has answered so far.')
        : $this->t('No answers yet.'),
    ];
    foreach ($storage->loadMultiple($ids) as $submission) {
      $build['s' . $submission->id()] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('@who · @state · @date', [
            '@who' => $submission->getOwner()->getDisplayName(),
            '@state' => $submission->isDraft() ? $this->t('draft, in progress') : $this->t('sent'),
            '@date' => $this->dateFormatter->format((int) $submission->getChangedTime(), 'custom', 'Y-m-d H:i'),
          ]),
        ],
        'answers' => $builder->view($submission, 'html'),
      ];
    }
    $build['#cache']['tags'][] = 'webform_submission_list';
    return $build;
  }

  /**
   * One line about the disks, or a warning callout when something is wrong.
   */
  private function storageNote(): array {
    $report = DiskStatus::report();
    $gb = fn (?array $d) => $d ? sprintf('%.1f GB free (%d%%)', $d['free_bytes'] / 1e9, $d['free_percent']) : 'not mounted';
    $problems = DiskStatus::problems($report);
    return [
      '#type' => 'inline_template',
      '#template' => '{% if problems %}<section class="so-callout so-callout--warn" aria-labelledby="so-storage-title"><span class="so-diamond"><span>!</span></span><div class="so-callout__body"><h2 id="so-storage-title">{{ title }}</h2><ul>{% for p in problems %}<li>{{ p }}</li>{% endfor %}</ul><p>{{ line }}</p></div></section>{% else %}<p class="so-storage">{{ line }}</p>{% endif %}',
      '#context' => [
        'title' => $this->t('Storage needs attention'),
        'problems' => $problems,
        'line' => $this->t('Storage: data disk @mounted. Boot disk @boot; data disk @data.', [
          '@mounted' => $report['data_disk_mounted'] ? $this->t('mounted') : $this->t('NOT mounted'),
          '@boot' => $gb($report['boot_disk']),
          '@data' => $gb($report['data_disk']),
        ]),
      ],
      '#cache' => ['max-age' => 0],
    ];
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
   * A plain table that scrolls sideways on phones (so-table-wrap).
   */
  private function table(array $header, array $rows, $empty): array {
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $empty,
      // No responsive-table script: every column stays in the HTML.
      '#responsive' => FALSE,
      '#prefix' => '<div class="so-table-wrap">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * A <time> element for an ISO date.
   */
  private function time(string $date): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<time datetime="{{ d }}">{{ d }}</time>',
      '#context' => ['d' => $date],
    ];
  }

  /**
   * Build reports as date-tile cards (so-card), newest first.
   */
  private function reportCards(array $reports): array {
    if (!$reports) {
      return ['#markup' => '<p>' . $this->t('No build reports yet.') . '</p>'];
    }
    $cards = [];
    foreach ($reports as $report) {
      $date = (string) $report->get('field_office_date')->value;
      $phase = $report->get('field_office_phase')->entity;
      $commits = count($report->get('field_commits'));
      $cards[] = [
        'url' => $report->toUrl()->toString(),
        'date' => $date,
        'month' => date('M', strtotime($date)),
        'day' => (int) substr($date, 8, 2),
        'title' => preg_replace('/^\d{4}-\d{2}-\d{2}: /', '', $report->label()),
        'meta' => implode(' · ', array_filter([
          $phase && $phase->access('view') ? $phase->label() : NULL,
          $date,
          $commits ? $this->formatPlural($commits, '1 commit', '@count commits') : NULL,
        ])),
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{% for c in cards %}<a class="so-card so-card--link" href="{{ c.url }}"><span class="so-date"><time datetime="{{ c.date }}"><span class="so-date__month">{{ c.month }}</span><span class="so-date__day">{{ c.day }}</span></time></span><span><span class="so-card__title">{{ c.title }}</span><span class="so-card__meta">{{ c.meta }}</span></span></a>{% endfor %}',
      '#context' => ['cards' => $cards],
    ];
  }

  /**
   * Open punch list items as a warning callout (so-callout), oldest first.
   */
  private function punchCallout(array $items, $title): array {
    $list = [];
    foreach ($items as $item) {
      $phase = $item->get('field_office_phase')->entity;
      $list[] = [
        'url' => $item->toUrl()->toString(),
        'answer' => $item->toUrl('canonical', ['fragment' => 'answer'])->toString(),
        'question' => $item->label(),
        'who' => $this->people($item, 'field_waiting_on'),
        'phase' => $phase && $phase->access('view') ? $phase->label() : NULL,
        'opened' => (string) $item->get('field_office_date')->value,
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if items %} so-callout--warn{% endif %}" aria-labelledby="so-punch-title"><span class="so-diamond"><span>{{ items|length }}</span></span><div class="so-callout__body"><h2 id="so-punch-title">{{ title }}</h2>{% if items %}<ul>{% for i in items %}<li><a href="{{ i.url }}">{{ i.question }}</a>. <strong>Waiting on {{ i.who }}</strong>{% if i.phase %} · {{ i.phase }}{% endif %} · opened <time datetime="{{ i.opened }}">{{ i.opened }}</time> <a class="button button--hazard" href="{{ i.answer }}">Answer it</a></li>{% endfor %}</ul>{% else %}<p>Nothing is waiting on anyone.</p>{% endif %}</div></section>',
      '#context' => ['items' => $list, 'title' => $title],
    ];
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
        ['data' => $this->time((string) $item->get('field_office_date')->value)],
        $this->phaseLink($item),
      ];
      if ($closed) {
        $row[] = ['data' => $this->time((string) $item->get('field_closed_date')->value)];
        $row[] = $this->markdown($item, 'field_answer');
        $row[] = $this->people($item, 'field_answered_by');
      }
      else {
        $row[] = Link::fromTextAndUrl($this->t('Answer it'), $item->toUrl('canonical', [
          'fragment' => 'answer',
          'attributes' => ['class' => ['button', 'button--hazard']],
        ]))->toString();
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
        ['data' => $this->time((string) $decision->get('field_office_date')->value)],
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
    return $this->table($header, $rows, $this->t('No decisions logged yet.'));
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
