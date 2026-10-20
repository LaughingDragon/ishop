<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ishop_office\DiskStatus;
use Drupal\ishop_office\OfficeStructure;

/**
 * Needs you now, crew only: a disk is getting full (handover step 1f).
 */
final class DiskHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_ishop_needs(): one line per disk under 25% free.
   */
  #[Hook('ishop_needs')]
  public function needs(string $return): array {
    if (!OfficeStructure::isCrew($this->currentUser)) {
      return [];
    }
    $rows = [];
    foreach (DiskStatus::earlyWarnings(DiskStatus::report()) as $label => $percent) {
      $rows[] = [
        'text' => (string) $this->t('Crew: @disk is @percent% free (warning below @line%).', [
          '@disk' => $label,
          '@percent' => $percent,
          '@line' => DiskStatus::WARN_FREE_PERCENT,
        ]),
        'url' => Url::fromRoute('ishop_office.board')->toString(),
        'action' => (string) $this->t('Status board'),
        'ghost' => FALSE,
      ];
    }
    return $rows;
  }

}
