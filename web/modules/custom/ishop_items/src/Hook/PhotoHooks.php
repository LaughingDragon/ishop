<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ishop_items\ItemPhotos;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Item photos (Phase 10): pinned by file name, cleared after a sale.
 */
final class PhotoHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ItemPhotos $photos,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'logger.channel.ishop_items')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_node_insert(): new photos get the item's file names.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->photos->pin($node);
  }

  /**
   * Implements hook_node_update(): so do photos added later.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->photos->pin($node);
  }

  /**
   * Implements hook_cron(): a finished sale's photos are deleted.
   */
  #[Hook('cron')]
  public function cron(): void {
    if ($deleted = $this->photos->deleteFinished()) {
      $this->logger->info('Deleted @n photos of finished sales (delivered, return window over).', ['@n' => $deleted]);
    }
  }

  /**
   * Implements hook_ishop_needs(): items still selling with no photos.
   */
  #[Hook('ishop_needs')]
  public function needs(string $return): array {
    if (!$this->currentUser->hasPermission('manage platform listings')) {
      return [];
    }
    $count = count($this->photos->withoutPhotos());
    $next = $this->photos->nextWithoutPhotos();
    if (!$count || $next === NULL) {
      return [];
    }
    return [
      [
        'text' => (string) $this->formatPlural($count, '1 item has no photos yet.', '@count items have no photos yet.'),
        'url' => Url::fromRoute('ishop_items.item_photos', ['node' => $next])->toString(),
        'action' => (string) $this->t('Add photos'),
        'ghost' => FALSE,
      ],
    ];
  }

}
