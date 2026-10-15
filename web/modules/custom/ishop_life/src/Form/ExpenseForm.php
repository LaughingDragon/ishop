<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\ishop_items\PhotoPrep;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\LogBook;

/**
 * Log an expense: date, what, amount, category, receipt photo, note.
 */
final class ExpenseForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected LogBook $logBook,
    protected PhotoPrep $photoPrep,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_expense';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Log an expense')];
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => LogBook::today(),
      '#required' => TRUE,
    ];
    $form['what'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What for'),
      '#placeholder' => $this->t('Bubble mailers'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount ($)'),
      '#min' => 0.01,
      '#step' => 0.01,
      '#required' => TRUE,
      '#attributes' => ['inputmode' => 'decimal'],
    ];
    $form['category'] = [
      '#type' => 'radios',
      '#title' => $this->t('Category'),
      '#options' => array_map(fn ($label) => $this->t($label), LifeStructure::CATEGORIES),
      '#default_value' => 'supplies',
      '#required' => TRUE,
    ];
    $form['receipt'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Receipt photo (optional)'),
      '#description' => $this->t('A photo or a PDF. Photos are turned into JPEG and their location data is removed.'),
      '#upload_location' => 'private://receipts/' . date('Y-m'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'jpg jpeg png webp heic heif pdf'],
        'FileSizeLimit' => ['fileLimit' => 32 * 1024 * 1024],
      ],
      '#accept' => 'image/*,application/pdf',
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note (optional)'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log the expense'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fid = (int) (($form_state->getValue('receipt') ?? [])[0] ?? 0);
    if ($fid && ($file = File::load($fid)) && !str_ends_with(strtolower($file->getFilename()), '.pdf')) {
      try {
        // Same preparation as item photos: JPEG, upright, no GPS, sensible size.
        $this->photoPrep->prepare($file);
        $file->save();
      }
      catch (\RuntimeException $e) {
        $this->messenger()->addWarning($this->t('The receipt photo could not be prepared, so it was kept as it was.'));
      }
    }
    $node = $this->logBook->addExpense(
      (string) $form_state->getValue('date'),
      trim((string) $form_state->getValue('what')),
      (float) $form_state->getValue('amount'),
      (string) $form_state->getValue('category'),
      $fid ?: NULL,
      trim((string) $form_state->getValue('note')),
    );
    $this->messenger()->addStatus($this->t('Logged: @what, $@amount.', [
      '@what' => $node->label(),
      '@amount' => number_format((float) $node->get('field_amount')->value, 2),
    ]));
  }

}
