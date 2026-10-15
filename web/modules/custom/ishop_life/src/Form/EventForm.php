<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Textfield;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\ishop_life\Calendar;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\LogBook;

/**
 * Add an event to the Calendar wall.
 */
final class EventForm extends FormBase implements TrustedCallbackInterface {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected Calendar $calendar,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_event';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Add an event')];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What'),
      '#placeholder' => $this->t('Dentist'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => LogBook::today(),
      '#required' => TRUE,
    ];
    // A plain text value shown as a time picker on the phone.
    $form['time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Time (optional)'),
      '#size' => 8,
      '#pre_render' => [[Textfield::class, 'preRenderTextfield'], [self::class, 'timeInput']],
    ];
    $form['repeat'] = [
      '#type' => 'radios',
      '#title' => $this->t('Repeats'),
      '#options' => array_map(fn ($label) => $this->t($label), LifeStructure::REPEATS),
      '#default_value' => 'once',
      '#required' => TRUE,
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note (optional)'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add the event'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Pre-render callback: renders a text field as a time input.
   */
  public static function timeInput(array $element): array {
    $element['#attributes']['type'] = 'time';
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['timeInput'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $time = trim((string) $form_state->getValue('time'));
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
      $form_state->setErrorByName('time', $this->t('Times look like 13:25.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->calendar->addEvent(
      trim((string) $form_state->getValue('title')),
      (string) $form_state->getValue('date'),
      substr(trim((string) $form_state->getValue('time')), 0, 5),
      (string) $form_state->getValue('repeat'),
      trim((string) $form_state->getValue('note')),
    );
    $this->messenger()->addStatus($this->t('Added to the calendar: @title.', ['@title' => $event->label()]));
  }

}
