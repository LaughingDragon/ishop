<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\ishop_items\PhotoPrep;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The private document vault in the Mailroom (Phase 5 step 5).
 *
 * Receipts, warranties and manuals as photos or PDFs, in private files only.
 * Photos are prepared like item photos (JPEG, upright, no GPS).
 */
final class Vault {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly PhotoPrep $photoPrep,
    #[Autowire(service: 'logger.channel.ishop_life')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Adds a document; photos are prepared first.
   *
   * @param string $title
   *   What it is.
   * @param string $kind
   *   A LifeStructure::DOC_KINDS key.
   * @param int[] $fids
   *   Uploaded files.
   * @param int|null $item
   *   The item it belongs to, if any.
   * @param string|null $until
   *   Warranty end date (Y-m-d), if any.
   * @param string $note
   *   A note, or ''.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: int}
   *   The document and how many photos could not be prepared.
   */
  public function add(string $title, string $kind, array $fids, ?int $item, ?string $until, string $note): array {
    $failed = 0;
    foreach ($this->entityTypeManager->getStorage('file')->loadMultiple($fids) as $file) {
      $failed += $this->prepare($file) ? 0 : 1;
    }
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'document',
      'title' => mb_substr($title, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
      'field_doc_kind' => $kind,
      'field_doc_files' => array_map(fn ($fid) => ['target_id' => $fid], array_values($fids)),
      'field_doc_item' => $item ?: NULL,
      'field_doc_until' => $until ?: NULL,
      'field_log_note' => $note,
    ]);
    $node->setRevisionLogMessage('Added in the Mailroom.');
    $node->save();
    return [$node, $failed];
  }

  /**
   * Prepares a photo (not a PDF) like an item photo. FALSE if that failed.
   */
  private function prepare(FileInterface $file): bool {
    if (str_ends_with(strtolower($file->getFilename()), '.pdf')) {
      return TRUE;
    }
    try {
      $this->photoPrep->prepare($file);
      $file->save();
      return TRUE;
    }
    catch (\RuntimeException $e) {
      $this->logger->warning('Vault photo @fid kept as uploaded: @error', [
        '@fid' => $file->id(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Documents, newest first, optionally of one kind or for one item.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Document nodes.
   */
  public function documents(?string $kind = NULL, ?int $item = NULL, int $limit = 100): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'document')
      ->sort('created', 'DESC')
      ->range(0, $limit);
    if ($kind) {
      $query->condition('field_doc_kind', $kind);
    }
    if ($item) {
      $query->condition('field_doc_item', $item);
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * One document as plain values for templates.
   */
  public function describe(NodeInterface $doc, string $return): array {
    $files = [];
    foreach ($doc->get('field_doc_files') as $i => $ref) {
      if ($file = $ref->entity) {
        $files[] = [
          'url' => $file->createFileUrl(),
          'name' => count($doc->get('field_doc_files')) > 1 ? t('File @n', ['@n' => $i + 1]) : t('Open'),
          'pdf' => str_ends_with(strtolower($file->getFilename()), '.pdf'),
        ];
      }
    }
    $item = $doc->get('field_doc_item')->entity;
    return [
      'title' => $doc->label(),
      'kind' => LifeStructure::DOC_KINDS[$doc->get('field_doc_kind')->value] ?? '',
      'until' => (string) $doc->get('field_doc_until')->value,
      'note' => (string) $doc->get('field_log_note')->value,
      'files' => $files,
      'item' => $item ? ['title' => $item->label(), 'url' => $item->toUrl()->toString()] : NULL,
      'edit' => $doc->toUrl('edit-form', ['query' => ['destination' => $return]])->toString(),
      'delete' => $doc->toUrl('delete-form', ['query' => ['destination' => $return]])->toString(),
    ];
  }

  /**
   * Warranty end dates between two dates, as Calendar rows.
   */
  public function calendarRows(string $from, string $to): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'document')
      ->condition('field_doc_until', $from, '>=')
      ->condition('field_doc_until', $to, '<=')
      ->execute();
    $url = Url::fromRoute('ishop_life.mailroom')->toString();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $doc) {
      $rows[] = [
        'date' => (string) $doc->get('field_doc_until')->value,
        'time' => '',
        'title' => (string) t('Warranty ends: @title', ['@title' => $doc->label()]),
        'kind' => 'doc',
        'url' => $url,
        'edit' => NULL,
        'delete' => NULL,
      ];
    }
    return $rows;
  }

}
