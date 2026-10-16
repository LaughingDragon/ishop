<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\Vault;
use Drupal\node\Entity\Node;

/**
 * Mailroom: add a document (receipt, warranty, manual) to the vault.
 */
final class DocumentForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected Vault $vault,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_document';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Add a document')];
    $form['files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photos or PDFs'),
      '#description' => $this->t('Take a photo, choose one, or pick a PDF. Several at once is fine. Photos lose their location data.'),
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#upload_location' => 'private://vault/' . date('Y-m'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'jpg jpeg png webp heic heif pdf'],
        'FileSizeLimit' => ['fileLimit' => 32 * 1024 * 1024],
      ],
      '#accept' => 'image/*,application/pdf',
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What is it?'),
      '#placeholder' => $this->t('Vacuum warranty'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['kind'] = [
      '#type' => 'radios',
      '#title' => $this->t('Kind'),
      '#options' => array_map(fn ($label) => $this->t($label), LifeStructure::DOC_KINDS),
      '#default_value' => 'receipt',
      '#required' => TRUE,
    ];
    $item = (int) $this->getRequest()->query->get('item');
    $form['item'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Item it belongs to (optional)'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['item']],
      '#default_value' => $item ? Node::load($item) : NULL,
    ];
    $form['until'] = [
      '#type' => 'date',
      '#title' => $this->t('Warranty until (optional)'),
      '#description' => $this->t('The end date shows on the Calendar wall.'),
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note (optional)'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save to the vault'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$doc, $failed] = $this->vault->add(
      trim((string) $form_state->getValue('title')),
      (string) $form_state->getValue('kind'),
      array_map('intval', (array) $form_state->getValue('files')),
      $form_state->getValue('item') ? (int) $form_state->getValue('item') : NULL,
      $form_state->getValue('until') ?: NULL,
      trim((string) $form_state->getValue('note')),
    );
    $this->messenger()->addStatus($this->t('Saved to the vault: @title.', ['@title' => $doc->label()]));
    if ($failed) {
      $this->messenger()->addWarning($this->formatPlural($failed, 'One photo could not be prepared and was kept as it was.', '@count photos could not be prepared and were kept as they were.'));
    }
  }

}
