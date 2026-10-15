<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_life\Bills;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\LogBook;

/**
 * Add a bill or subscription.
 */
final class BillForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected Bills $bills,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_bill';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Add a bill or subscription')];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#placeholder' => $this->t('Phone'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount ($, optional)'),
      '#min' => 0,
      '#step' => 0.01,
      '#attributes' => ['inputmode' => 'decimal'],
    ];
    $form['due'] = [
      '#type' => 'date',
      '#title' => $this->t('Next due'),
      '#default_value' => LogBook::today(),
      '#required' => TRUE,
    ];
    $form['repeat'] = [
      '#type' => 'radios',
      '#title' => $this->t('Repeats'),
      '#options' => array_map(fn ($label) => $this->t($label), LifeStructure::REPEATS),
      '#default_value' => 'monthly',
      '#required' => TRUE,
    ];
    $form['business'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Business expense: log it in Expenses each time it is paid'),
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note (optional)'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add the bill'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $amount = $form_state->getValue('amount');
    $bill = $this->bills->add(
      trim((string) $form_state->getValue('name')),
      $amount === '' || $amount === NULL ? NULL : (float) $amount,
      (string) $form_state->getValue('due'),
      (string) $form_state->getValue('repeat'),
      (bool) $form_state->getValue('business'),
      trim((string) $form_state->getValue('note')),
    );
    $this->messenger()->addStatus($this->t('Added: @line.', ['@line' => $this->bills->lineFor($bill)]));
  }

}
