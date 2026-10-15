<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_life\LogBook;

/**
 * Log a trip: date, trip, miles, note; or "Same as last time" in one tap.
 */
final class MileageForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected LogBook $logBook,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_mileage';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Log a trip')];

    $last = current($this->logBook->recent('mileage', 1)) ?: NULL;
    if ($last) {
      $miles = rtrim(rtrim(number_format((float) $last->get('field_miles')->value, 1, '.', ''), '0'), '.');
      $form['again'] = [
        '#type' => 'submit',
        '#value' => $this->t('Same as last time: @trip, @miles mi', [
          '@trip' => $last->get('field_trip')->value,
          '@miles' => $miles,
        ]),
        '#submit' => ['::again'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['rm-log-form__again']],
      ];
      $form_state->set('last', [
        'trip' => (string) $last->get('field_trip')->value,
        'miles' => (float) $last->get('field_miles')->value,
      ]);
    }
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => LogBook::today(),
      '#required' => TRUE,
    ];
    $form['trip'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Trip'),
      '#placeholder' => $this->t('Post office'),
      '#maxlength' => 120,
      '#required' => TRUE,
      '#attributes' => ['list' => 'rm-log-trips', 'autocomplete' => 'off'],
      '#suffix' => $this->datalist(),
    ];
    $form['miles'] = [
      '#type' => 'number',
      '#title' => $this->t('Miles'),
      '#min' => 0.1,
      '#step' => 0.1,
      '#required' => TRUE,
      '#attributes' => ['inputmode' => 'decimal'],
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note (optional)'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log the trip'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Suggestions for the trip box: trips logged before.
   */
  private function datalist(): string {
    $options = '';
    foreach ($this->logBook->tripNames() as $name) {
      $options .= '<option value="' . htmlspecialchars($name, ENT_QUOTES) . '"></option>';
    }
    return '<datalist id="rm-log-trips">' . $options . '</datalist>';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->logBook->addMileage(
      (string) $form_state->getValue('date'),
      trim((string) $form_state->getValue('trip')),
      (float) $form_state->getValue('miles'),
      trim((string) $form_state->getValue('note')),
    );
    $this->messenger()->addStatus($this->t('Logged: @title.', ['@title' => $node->label()]));
  }

  /**
   * Logs the previous trip again, dated today ("Same as last time").
   */
  public function again(array &$form, FormStateInterface $form_state): void {
    $last = $form_state->get('last');
    if (!$last) {
      return;
    }
    $node = $this->logBook->addMileage(LogBook::today(), $last['trip'], $last['miles'], '');
    $this->messenger()->addStatus($this->t('Logged: @title.', ['@title' => $node->label()]));
  }

}
