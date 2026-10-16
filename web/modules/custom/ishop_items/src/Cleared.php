<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\node\NodeInterface;

/**
 * Cleared to sell (decision 265): nothing is posted until it's ticked.
 *
 * One check for every way an item gets posted: the Post this item pages,
 * the to-post queue (and so Depop bulk and Previous/Next), Stockroom "Mark
 * posted" and the listings grid.
 */
final class Cleared {

  public const FIELD = 'field_cleared_to_sell';

  /**
   * What Cody sees where posting is held back.
   */
  public const MESSAGE = "Check it's OK to sell first.";

  /**
   * Whether the item has been cleared to sell.
   */
  public static function isCleared(NodeInterface $item): bool {
    return $item->hasField(self::FIELD) && (bool) $item->get(self::FIELD)->value;
  }

}
