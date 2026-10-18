<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingImport\ListingImporter;

/**
 * Needs you now: notes from the imported spreadsheet (Phase 10).
 */
final class SheetNotesHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ListingImporter $importer,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_ishop_needs(): one line while any note is waiting.
   */
  #[Hook('ishop_needs')]
  public function needs(string $return): array {
    if (!$this->currentUser->hasPermission('manage platform listings')) {
      return [];
    }
    $count = count($this->importer->sheetNotes());
    if (!$count) {
      return [];
    }
    return [
      [
        'text' => (string) $this->formatPlural($count, '1 item has a note from your old spreadsheet to check.', '@count items have notes from your old spreadsheet to check.'),
        'url' => Url::fromRoute('ishop_items.sheet_notes')->toString(),
        'action' => (string) $this->t('See them'),
        'ghost' => FALSE,
      ],
    ];
  }

}
