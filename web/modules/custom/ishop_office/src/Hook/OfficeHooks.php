<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ishop_office\Controller\OfficeController;
use Drupal\ishop_office\OfficeStructure;
use Drupal\node\NodeInterface;

/**
 * Keeps the site office private and readable.
 */
final class OfficeHooks {

  use StringTranslationTrait;

  private const GRANT_REALM = 'ishop_office';

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
      'realm' => self::GRANT_REALM,
      'gid' => 1,
      'grant_view' => (int) $node->isPublished(),
      'grant_update' => 0,
      'grant_delete' => 0,
    ]];
  }

  /**
   * Implements hook_node_grants().
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, string $operation): array {
    return $operation === 'view' && $account->hasPermission('access site office') ? [self::GRANT_REALM => [1]] : [];
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
    return AccessResult::forbiddenIf(!$account->hasPermission('access site office'))->cachePerPermissions();
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
    foreach (OfficeStructure::SECTION_FIELDS as $field) {
      // The plans page is one long document with its own headings.
      if (isset($build[$field]) && $node->bundle() !== 'office_plan') {
        $label = $node->getFieldDefinition($field)->getLabel();
        $build[$field] = [
          'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $label],
          'field' => $build[$field],
          '#weight' => $build[$field]['#weight'] ?? 0,
        ];
      }
    }
    $build['#attached']['library'][] = 'ishop_office/office';
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
