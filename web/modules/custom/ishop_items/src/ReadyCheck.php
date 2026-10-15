<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * What an item still needs before it is ready to list.
 *
 * Only photos are required when adding an item on the phone; everything else
 * is shown as a checklist instead of blocking the save.
 */
final class ReadyCheck {

  /**
   * Prefix of the title given to items added on the phone without one.
   */
  public const DEFAULT_TITLE_PREFIX = 'New item ';

  /**
   * The checklist, in the order it is shown: key => label.
   */
  public const ITEMS = [
    'photos' => 'At least one photo',
    'title' => 'A real title (not "New item ...")',
    'description' => 'Description',
    'category' => 'Category',
    'condition' => 'Condition',
    'price' => 'Asking price',
  ];

  /**
   * Returns the labels of what is missing, in checklist order.
   *
   * @param array{photos: int, title: string, description: string, category: bool, condition: bool, price: ?string} $item
   *   Plain values read from the item.
   */
  public static function missing(array $item): array {
    $done = [
      'photos' => ($item['photos'] ?? 0) > 0,
      'title' => trim($item['title'] ?? '') !== '' && !str_starts_with($item['title'], self::DEFAULT_TITLE_PREFIX),
      'description' => trim($item['description'] ?? '') !== '',
      'category' => !empty($item['category']),
      'condition' => !empty($item['condition']),
      'price' => isset($item['price']) && $item['price'] !== '' && (float) $item['price'] > 0,
    ];
    return array_values(array_intersect_key(self::ITEMS, array_filter($done, fn ($ok) => !$ok)));
  }

}
