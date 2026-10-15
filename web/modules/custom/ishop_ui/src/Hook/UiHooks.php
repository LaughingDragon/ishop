<?php

declare(strict_types=1);

namespace Drupal\ishop_ui\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;

/**
 * Site-wide interface tweaks.
 */
final class UiHooks {

  /**
   * Implements hook_block_build_BASE_BLOCK_ID_alter() for local tasks.
   *
   * Core's Navigation module hides the theme's View / Edit tabs and offers
   * only a small Edit button in its top bar, which Liam could not find. Run
   * after Navigation and show the tabs on the page again.
   */
  #[Hook('block_build_local_tasks_block_alter', order: Order::Last)]
  public function showLocalTasks(array &$build, BlockPluginInterface $block): void {
    if ($block->getPluginId() === 'local_tasks_block') {
      $build['#access'] = TRUE;
    }
  }

  /**
   * Implements hook_page_attachments().
   *
   * Tab styling: always expanded and large. (Attachments added in the block
   * alter above are not kept, so the CSS is attached to every page here.)
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'ishop_ui/tabs';
  }

}
