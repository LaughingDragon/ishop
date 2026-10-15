<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Hook;

use Drupal\block\Entity\Block;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ishop_office\Controller\OfficeController;
use Drupal\ishop_office\Form\AnswerPunchForm;
use Drupal\ishop_office\OfficeStructure;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Keeps the site office private and readable.
 */
final class OfficeHooks {

  use StringTranslationTrait;

  private const GRANT_REALM = 'ishop_office';

  /**
   * Realm for crew-only office nodes (webmaster and site-foreman).
   */
  private const CREW_REALM = 'ishop_office_crew';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Whether a node is site office content.
   */
  public static function isOffice(NodeInterface $node): bool {
    return isset(OfficeStructure::BUNDLES[$node->bundle()]);
  }

  /**
   * Implements hook_node_access_records().
   *
   * Office nodes are only granted to the office realm, so listings and
   * searches leave them out for everyone without the permission.
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node): array {
    if (!self::isOffice($node)) {
      return [];
    }
    return [[
      'realm' => OfficeStructure::isCrewOnly($node) ? self::CREW_REALM : self::GRANT_REALM,
      'gid' => 1,
      'grant_view' => (int) $node->isPublished(),
      'grant_update' => 0,
      'grant_delete' => 0,
    ],
    ];
  }

  /**
   * Implements hook_node_grants().
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, string $operation): array {
    if ($operation !== 'view' || !$account->hasPermission('access site office')) {
      return [];
    }
    $grants = [self::GRANT_REALM => [1]];
    if (OfficeStructure::isCrew($account)) {
      $grants[self::CREW_REALM] = [1];
    }
    return $grants;
  }

  /**
   * Implements hook_query_TAG_alter() for node_access.
   *
   * Crew-only office nodes are left out of every node list (site office,
   * admin content list, autocomplete) for anyone who is not crew. This also
   * covers administrators, who bypass node grants.
   */
  #[Hook('query_node_access_alter')]
  public function queryNodeAccessAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }
    $account = $query->getMetaData('account') ?? \Drupal::currentUser();
    if (OfficeStructure::isCrew($account)) {
      return;
    }
    foreach ($query->getTables() as $alias => $table) {
      if (in_array($table['table'] ?? NULL, ['node', 'node_field_data'], TRUE) && empty($table['join type'])) {
        $hidden = \Drupal::database()->select('node__field_crew_only', 'crew')
          ->fields('crew', ['entity_id'])
          ->condition('crew.field_crew_only_value', 1);
        $query->condition($alias . '.nid', $hidden, 'NOT IN');
        return;
      }
    }
  }

  /**
   * Implements hook_node_access().
   *
   * Without the permission, office nodes are off limits even to content
   * editors with broad node permissions.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, string $operation, AccountInterface $account): AccessResultInterface {
    if (!self::isOffice($node)) {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!$account->hasPermission('access site office'))->cachePerPermissions()
      ->orIf(AccessResult::forbiddenIf(OfficeStructure::isCrewOnly($node) && !OfficeStructure::isCrew($account))->cachePerUser()->addCacheableDependency($node));
  }

  /**
   * Implements hook_node_view().
   *
   * Long text sections get a real <h2> each, and every office page links
   * back to the office.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if (!self::isOffice($node) || $view_mode !== 'full') {
      return;
    }
    $build['ishop_office_nav'] = OfficeController::nav() + ['#weight' => -100];
    if ($node->bundle() === 'office_phase') {
      $controller = \Drupal::classResolver(OfficeController::class);
      $sections = $controller->phaseSections($node);
      unset($sections['#cache']);
      $build += $sections;
      $controller->addCacheability($build);
    }
    if ($node->bundle() === 'office_report') {
      $this->reportSections($build, $node);
    }
    foreach (OfficeStructure::SECTION_FIELDS as $field) {
      // The plans page is one long document with its own headings.
      if (isset($build[$field]['#theme']) && !in_array($node->bundle(), ['office_plan', 'office_report'], TRUE)) {
        $label = $node->getFieldDefinition($field)->getLabel();
        $build[$field] = [
          'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $label],
          'field' => $build[$field],
          '#weight' => $build[$field]['#weight'] ?? 0,
        ];
      }
    }
    if ($node->bundle() === 'office_punch' && $node->get('field_punch_status')->value === 'open') {
      $build['ishop_office_answer'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'answer', 'class' => ['ishop-office-answer']],
        // Right after "What to do" (weight 10 on the display).
        '#weight' => 10.5,
        'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Answer this')],
        'form' => \Drupal::formBuilder()->getForm(AnswerPunchForm::class, $node),
      ];
      // The form carries a per-user token, so this page is never cached.
      $build['#cache']['max-age'] = 0;
    }
    $build['#attached']['library'][] = 'ishop_office/office';
  }

  /**
   * Turns a report's fields into so-report sections.
   *
   * Each part is a <section> with an <h2>; real blockers get the warning
   * panel; commits become hash chips. Date, phase and commits move to the
   * header band (see node--office-report.html.twig).
   */
  private function reportSections(array &$build, NodeInterface $node): void {
    $fields = [
      'field_summary', 'field_work_done', 'field_next_steps',
      'field_blockers', 'field_decisions_needed', 'field_tests',
    ];
    foreach ($fields as $field) {
      if (!isset($build[$field]['#theme'])) {
        continue;
      }
      $heading = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $node->getFieldDefinition($field)->getLabel()];
      // "None." or "None. The site runs normally..." is not a blocker.
      $blocked = $field === 'field_blockers' && !preg_match('/^\s*none\b/i', (string) $node->get($field)->value);
      $build[$field] = [
        '#type' => 'html_tag',
        '#tag' => 'section',
        '#attributes' => $blocked ? ['class' => ['so-report__blockers']] : [],
        '#weight' => $build[$field]['#weight'] ?? 0,
        'diamond' => $blocked ? ['#markup' => '<span class="so-diamond so-diamond--small"><span>!</span></span>'] : [],
        'body' => $blocked ? [
          '#type' => 'container',
          '#attributes' => ['class' => ['so-callout__body']],
          'heading' => $heading,
          'field' => $build[$field],
        ] : ['heading' => $heading, 'field' => $build[$field]],
      ];
    }
    $hashes = array_column($node->get('field_commits')->getValue(), 'value');
    unset($build['field_commits'], $build['field_office_date'], $build['field_office_phase']);
    if ($hashes) {
      $build['ishop_office_commits'] = [
        '#type' => 'inline_template',
        '#template' => '<section><h2>{{ title }}</h2><ul class="so-hashes">{% for h in hashes %}<li><code class="so-hash">{{ h }}</code></li>{% endfor %}</ul></section>',
        '#context' => ['title' => $this->t('Git commits'), 'hashes' => $hashes],
        '#weight' => 100,
      ];
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'node__office_report' => [
        'base hook' => 'node',
        'template' => 'node--office-report',
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for node templates.
   *
   * Header band facts for build reports.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'];
    if (!$node instanceof NodeInterface || $node->bundle() !== 'office_report') {
      return;
    }
    $phase = $node->get('field_office_phase')->entity;
    $variables['report_date'] = (string) $node->get('field_office_date')->value;
    $variables['report_phase_label'] = $phase ? $phase->label() : '';
    $variables['report_phase'] = $phase && $phase->access('view') ? $phase->toLink()->toRenderable() : '-';
  }

  /**
   * Implements hook_block_access().
   *
   * A build report carries its own <h1> in the header band, so the theme's
   * page title block is hidden there to keep one <h1> per page.
   */
  #[Hook('block_access')]
  public function blockAccess(Block $block, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view' || !in_array($block->getPluginId(), ['page_title_block', 'local_tasks_block'], TRUE)) {
      return AccessResult::neutral();
    }
    // The questionnaire for Cody: no admin tabs (Test, Results, Build...)
    // above it, even though Cody's account is an administrator.
    if (in_array($this->routeMatch->getRouteName(), ['entity.webform.canonical', 'entity.webform.confirmation'], TRUE)
      && $block->getPluginId() === 'local_tasks_block') {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }
    $node = $this->routeMatch->getParameter('node');
    $is_report = $this->routeMatch->getRouteName() === 'entity.node.canonical'
      && $node instanceof NodeInterface && $node->bundle() === 'office_report';
    return AccessResult::forbiddenIf($is_report && $block->getPluginId() === 'page_title_block')->addCacheContexts(['route']);
  }

  /**
   * Implements hook_modules_installed().
   *
   * The site is private: visitors who are not logged in get no permissions.
   * Some modules grant some on install (Media grants "view media", which
   * exposed private item photos on 2026-10-15), so anything granted to the
   * anonymous role is revoked straight away and logged.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    $role = Role::load(RoleInterface::ANONYMOUS_ID);
    $granted = $role ? $role->getPermissions() : [];
    if (!$granted) {
      return;
    }
    foreach ($granted as $permission) {
      $role->revokePermission($permission);
    }
    $role->save();
    \Drupal::logger('ishop_office')->warning('Installing @modules granted anonymous visitors: @perms. Revoked; the site stays private.', [
      '@modules' => implode(', ', $modules),
      '@perms' => implode(', ', $granted),
    ]);
  }

  /**
   * Implements hook_page_attachments().
   *
   * Search engines are told to stay out of every office page.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $route = (string) $this->routeMatch->getRouteName();
    $node = $this->routeMatch->getParameter('node');
    $is_office = str_starts_with($route, 'ishop_office.')
      || ($node instanceof NodeInterface && self::isOffice($node));
    if (!$is_office) {
      return;
    }
    $attachments['#attached']['html_head'][] = [
      ['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'noindex, nofollow']],
      'ishop_office_robots',
    ];
    $attachments['#attached']['http_header'][] = ['X-Robots-Tag', 'noindex, nofollow'];
  }

}
