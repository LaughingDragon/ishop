<?php

/**
 * @file
 * Hooks provided by the iShop items module.
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;

/**
 * Alter what Check sold prices searches for.
 *
 * @param string $title
 *   The item's title; replace it to search for something better (ishop_ai
 *   uses the AI draft's title while the item has a placeholder title).
 * @param \Drupal\node\NodeInterface $node
 *   The item.
 */
function hook_ishop_sold_search_title_alter(string &$title, NodeInterface $node): void {
  if ($title === '') {
    $title = 'Brass table lamp';
  }
}
