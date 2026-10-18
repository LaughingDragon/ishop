<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingImport\ListingImporter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Undo an import (Phase 10): deletes only the items it brought in.
 */
final class ListingImportUndoForm extends ConfirmFormBase {

  /**
   * The import record.
   */
  protected array $import = [];

  public function __construct(
    protected ListingImporter $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_listing_import_undo';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $import = NULL): array {
    $record = $import ? $this->importer->get($import) : NULL;
    if (!$record || !$this->importer->canUndo($record)) {
      throw new NotFoundHttpException();
    }
    $this->import = $record;
    $form = parent::buildForm($form, $form_state);
    $changed = $this->importer->changedSince($record);
    if ($changed) {
      $form['description']['#markup'] .= '<p><strong>' . $this->t('Some of these items were changed after the import (for example photos added, or marked sold). Undo deletes them too, with those changes.') . '</strong></p>';
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->import['nids']), 'Undo the import of 1 item?', 'Undo the import of @count items?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This deletes the items brought in from @file on @date, and nothing else. Your spreadsheet and your listings on the marketplaces are not touched. It cannot be undone.', [
      '@file' => $this->import['file'],
      '@date' => \Drupal::service('date.formatter')->format($this->import['created'], 'custom', 'M j, g:i a'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Undo the import');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ishop_items.import_listings');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $deleted = $this->importer->undo((int) $this->import['id']);
    $this->messenger()->addStatus($this->formatPlural($deleted, 'Deleted 1 imported item.', 'Deleted @count imported items.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
