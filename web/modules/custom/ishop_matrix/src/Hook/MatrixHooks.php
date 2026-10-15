<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Hook;

use Drupal\block\Entity\Block;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Hooks for the Reseller Matrix front of house.
 */
final class MatrixHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

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
