<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An item's photos: pinned to it by file name, added in bulk, cleared after.
 *
 * Phase 10 (Liam, 2026-10-18): each photo's stored file name carries the
 * item it belongs to ("item-123-blue-hoodie-456.jpg"), whichever way it was
 * added. Up to MAX photos per item. A sold item's photos are deleted once
 * the sale is finished: delivered, and the return window
 * (photo_keep_days per platform) is over.
 */
final class ItemPhotos {

  /**
   * Most photos an item can have.
   */
  public const MAX = 10;

  /**
   * Days after delivery before a sold item's photos go, if not configured.
   */
  public const KEEP_DAYS = 30;

  /**
   * Item statuses that still need photos to sell.
   */
  public const SELLING = ['draft', 'ready', 'listed'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'transliteration')]
    private readonly TransliterationInterface $transliteration,
    #[Autowire(service: 'logger.channel.ishop_items')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The file name a photo gets on an item: item-<nid>-<words>-<fid>.<ext>.
   */
  public function fileName(NodeInterface $item, int $fid, string $current): string {
    $extension = strtolower(pathinfo($current, PATHINFO_EXTENSION)) ?: 'jpg';
    return sprintf('%s%s-%d.%s', self::prefix((int) $item->id()), $this->slug((string) $item->label()), $fid, $extension);
  }

  /**
   * The start of every file name pinned to an item.
   */
  public static function prefix(int $nid): string {
    return 'item-' . $nid . '-';
  }

  /**
   * A few words of the title, safe for a file name.
   */
  public function slug(string $title): string {
    $ascii = strtolower($this->transliteration->transliterate($title, 'en', ''));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '', '-');
    $slug = substr($slug, 0, 40);
    return rtrim($slug, '-') ?: 'item';
  }

  /**
   * Renames the item's photo files that don't carry its number yet.
   *
   * @return int
   *   How many files were renamed.
   */
  public function pin(NodeInterface $item): int {
    if ($item->bundle() !== 'item' || $item->isNew() || !$item->hasField('field_photos')) {
      return 0;
    }
    $renamed = 0;
    foreach ($item->get('field_photos') as $reference) {
      $media = $reference->entity;
      $file = $media?->get('field_media_image_1')->entity;
      if (!$file || str_starts_with($file->getFilename(), self::prefix((int) $item->id()))) {
        continue;
      }
      $name = $this->fileName($item, (int) $file->id(), $file->getFilename());
      try {
        $file = $this->fileRepository->move($file, dirname($file->getFileUri()) . '/' . $name, FileExists::Rename);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not rename photo @fid for item @nid: @message', [
          '@fid' => $file->id(),
          '@nid' => $item->id(),
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      $media->set('name', $file->getFilename());
      $media->save();
      $renamed++;
    }
    return $renamed;
  }

  /**
   * The next item still selling with no photos, after $after (wrapping).
   */
  public function nextWithoutPhotos(int $after = 0): ?int {
    $ids = $this->withoutPhotos();
    foreach ($ids as $nid) {
      if ($nid > $after) {
        return $nid;
      }
    }
    $first = $ids[0] ?? NULL;
    return $first !== NULL && $first !== $after ? $first : NULL;
  }

  /**
   * Items still selling with no photos, oldest first.
   *
   * @return int[]
   *   Node IDs.
   */
  public function withoutPhotos(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_item_status', 's', 's.entity_id = n.nid');
    $query->leftJoin('node__field_photos', 'p', 'p.entity_id = n.nid');
    $query->fields('n', ['nid'])
      ->condition('n.type', 'item')
      ->condition('s.field_item_status_value', self::SELLING, 'IN')
      ->isNull('p.entity_id')
      ->orderBy('n.nid');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Deletes a sold item's photos once its sale is finished.
   *
   * Finished: Sold here, delivered, and photo_keep_days (per platform,
   * default KEEP_DAYS) have passed since, so the return window is over.
   *
   * @return int
   *   How many photos were deleted.
   */
  public function deleteFinished(): int {
    $keep = (array) ($this->configFactory->get('ishop_items.shipping')->get('photo_keep_days') ?? []);
    $today = new \DateTimeImmutable(Stale::today());
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->exists('date_delivered')
      ->execute();
    $deleted = 0;
    foreach ($storage->loadMultiple($ids) as $sale) {
      $item = $sale->getItem();
      if (!$item || $item->get('field_photos')->isEmpty() || $item->get('field_item_status')->value !== 'sold') {
        continue;
      }
      $days = (int) ($keep[$sale->getPlatform()] ?? self::KEEP_DAYS);
      $until = (new \DateTimeImmutable((string) $sale->get('date_delivered')->value))->modify("+$days days");
      if ($until >= $today) {
        continue;
      }
      $media = array_filter(array_map(fn ($r) => $r->entity, iterator_to_array($item->get('field_photos'))));
      $item->set('field_photos', []);
      $item->setNewRevision(TRUE);
      $item->setRevisionLogMessage(sprintf('Photos deleted: sold on %s, delivered %s, return window (%d days) over.', Platforms::PLATFORMS[$sale->getPlatform()] ?? $sale->getPlatform(), $sale->get('date_delivered')->value, $days));
      $item->setRevisionUserId((int) $this->currentUser->id());
      $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $item->save();
      foreach ($media as $m) {
        $file = $m->get('field_media_image_1')->entity;
        $m->delete();
        $file?->delete();
        $deleted++;
      }
    }
    return $deleted;
  }

}
