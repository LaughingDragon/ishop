<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_office\OfficeManager;

/**
 * Opens "Turn on off-server backups now" for Liam at 10 real items.
 *
 * Liam paused off-server backups until there was real data (decision 131),
 * and asked for this item to open by itself once 10 real items exist
 * (directive, 2026-10-15). It opens once; the State key records it.
 */
final class OffsiteReminder {

  /**
   * Items needed before the reminder opens.
   */
  public const THRESHOLD = 10;

  /**
   * State key: the punch item's node ID once opened.
   */
  public const STATE = 'ishop_items.offsite_reminder_nid';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly OfficeManager $office,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Opens the punch item if the threshold is reached and it isn't open yet.
   *
   * @return int|null
   *   The new punch item's node ID, or NULL if nothing was opened.
   */
  public function check(): ?int {
    if ($this->state->get(self::STATE)) {
      return NULL;
    }
    $count = (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'item')
      ->count()->execute();
    if ($count < self::THRESHOLD) {
      return NULL;
    }
    $instructions = file_get_contents($this->moduleList->getPath('ishop_items') . '/instructions/offsite-backups.md');
    // Record first, so a failure below can't open it twice.
    $this->state->set(self::STATE, -1);
    $node = $this->office->addPunch(
      'Turn on off-server backups now',
      ['liam'],
      NULL,
      sprintf('Opened automatically: the site now holds %d items (the threshold Liam set was %d). Backups were paused by Liam until there was real data (decision 131, item 129).', $count, self::THRESHOLD),
      NULL,
      (string) $instructions,
    );
    $this->state->set(self::STATE, (int) $node->id());
    return (int) $node->id();
  }

}
