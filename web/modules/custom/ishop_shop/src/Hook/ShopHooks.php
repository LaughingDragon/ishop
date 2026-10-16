<?php

declare(strict_types=1);

namespace Drupal\ishop_shop\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ishop_shop\ShopPhotos;
use Drupal\node\NodeInterface;

/**
 * Hooks for the public shop.
 */
final class ShopHooks {

  public function __construct(
    private readonly ShopPhotos $photos,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ishop_shop_catalog' => [
        'variables' => ['cards' => [], 'filters' => [], 'preview' => NULL],
      ],
      'ishop_shop_item' => [
        'variables' => [
          'title_text' => '',
          'price' => '',
          'photos' => [],
          'description' => '',
          'flaws' => '',
          'size' => '',
          'condition' => NULL,
          'links' => [],
          'back' => '',
          'preview' => NULL,
        ],
      ],
    ];
  }

  /**
   * Whether a change to this entity can change the shop's photos.
   */
  private static function matters(EntityInterface $entity): bool {
    return ($entity instanceof NodeInterface && $entity->bundle() === 'item')
      || in_array($entity->getEntityTypeId(), ['media', 'file'], TRUE);
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (self::matters($entity)) {
      $this->photos->markDirty();
    }
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if (self::matters($entity)) {
      $this->photos->markDirty();
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if (self::matters($entity)) {
      $this->photos->markDirty();
    }
  }

  /**
   * Implements hook_cron(): a full check, in case a request was cut short.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->photos->sync();
  }

}
