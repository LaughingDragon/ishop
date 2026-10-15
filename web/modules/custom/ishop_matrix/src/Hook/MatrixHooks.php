<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Hook;

use Drupal\block\Entity\Block;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Hooks for the Reseller Matrix front of house.
 */
final class MatrixHooks {

  /**
   * Cody's front-end theme.
   */
  public const THEME = 'reseller_matrix';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * Whether this page is in the Reseller Matrix theme.
   */
  private function isMatrix(): bool {
    return $this->themeManager->getActiveTheme()->getName() === self::THEME;
  }

  /**
   * Implements hook_page_attachments_alter(), after Gin Toolbar's.
   *
   * Without the sidebar, its styles (body padding, the grey bar) go too.
   */
  #[Hook('page_attachments_alter', order: new OrderAfter(modules: ['gin_toolbar', 'navigation']))]
  public function pageAttachmentsAlter(array &$page): void {
    if (!$this->isMatrix() || empty($page['#attached']['library'])) {
      return;
    }
    $page['#attached']['library'] = array_values(array_filter(
      $page['#attached']['library'],
      fn (string $library) => !preg_match('#^(gin|gin_toolbar|navigation|toolbar)/#', $library),
    ));
  }

  /**
   * Implements hook_preprocess_HOOK() for html.
   *
   * Front-end (Reseller Matrix) pages have no admin sidebar or top bar, for
   * everyone; admin pages and the site office keep them. Crew reach admin
   * pages through the "Admin" button in the header. Navigation adds them in
   * hook_page_top() with Order::Last and there is no page_top alter, so they
   * are removed here, together with Gin Toolbar's body classes.
   */
  #[Hook('preprocess_html', order: new OrderAfter(modules: ['gin_toolbar']))]
  public function preprocessHtml(array &$variables): void {
    if (!$this->isMatrix()) {
      return;
    }
    unset($variables['page_top']['navigation'], $variables['page_top']['top_bar']);
    if (!empty($variables['attributes']['class'])) {
      $variables['attributes']['class'] = array_values(array_filter(
        (array) $variables['attributes']['class'],
        fn ($class) => !str_starts_with((string) $class, 'gin--'),
      ));
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for page: the header's "Admin" button.
   *
   * Shown only to crew who can use the admin pages.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $variables['#cache']['contexts'][] = 'user.permissions';
    $variables['admin_url'] = \Drupal::currentUser()->hasPermission('access administration pages')
      ? Url::fromRoute('system.admin')->toString()
      : NULL;
  }

  /**
   * Implements hook_block_access().
   *
   * The dashboard's greeting is its h1, so the page title block is hidden
   * there (one h1 per page).
   */
  #[Hook('block_access')]
  public function blockAccess(Block $block, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view' || $block->getPluginId() !== 'page_title_block') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf($this->routeMatch->getRouteName() === 'ishop_matrix.dashboard')
      ->addCacheContexts(['route']);
  }

}
