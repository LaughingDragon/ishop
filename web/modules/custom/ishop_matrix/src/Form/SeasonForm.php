<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Textfield;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\ishop_matrix\Season;

/**
 * My office: when the season starts, and the game days (with kickoff).
 */
final class SeasonForm extends FormBase implements TrustedCallbackInterface {

  /**
   * Empty game-day rows offered for adding more.
   */
  private const BLANK_ROWS = 3;

  public function __construct(
    private readonly Season $season,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_matrix_season';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->season->settings();
    [$month, $day] = array_map('intval', explode('-', $settings['start']));
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-season-form';

    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('This season')];
    $form['start'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('The season starts on'),
      '#description' => $this->t('The front desk scoreboard counts from this date. Usually January 1.'),
      '#attributes' => ['class' => ['rm-season-form__start']],
    ];
    $months = [];
    foreach (range(1, 12) as $m) {
      $months[$m] = date('F', mktime(0, 0, 0, $m, 1));
    }
    $form['start']['month'] = [
      '#type' => 'select',
      '#title' => $this->t('Month'),
      '#options' => $months,
      '#default_value' => $month,
    ];
    $form['start']['day'] = [
      '#type' => 'select',
      '#title' => $this->t('Day'),
      '#options' => array_combine(range(1, 31), range(1, 31)),
      '#default_value' => $day,
    ];

    $form['game_days'] = [
      '#type' => 'table',
      '#caption' => $this->t('Game days. On these days the front desk says "Game day" and shows the kickoff time. To remove one, clear its date.'),
      '#header' => [$this->t('Date'), $this->t('Kickoff (optional)')],
      '#attributes' => ['class' => ['rm-season-form__days']],
    ];
    $rows = array_merge($settings['game_days'], array_fill(0, self::BLANK_ROWS, ['date' => '', 'kickoff' => '']));
    foreach ($rows as $i => $row) {
      $form['game_days'][$i]['date'] = [
        '#type' => 'date',
        '#title' => $this->t('Game day @n date', ['@n' => $i + 1]),
        '#title_display' => 'invisible',
        '#default_value' => $row['date'],
      ];
      // A plain text value shown as a time picker on the phone (core's
      // time-only datetime element rejects every time as an invalid date).
      $form['game_days'][$i]['kickoff'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Game day @n kickoff time', ['@n' => $i + 1]),
        '#title_display' => 'invisible',
        '#size' => 8,
        '#default_value' => $row['kickoff'],
        '#pre_render' => [[Textfield::class, 'preRenderTextfield'], [self::class, 'timeInput']],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save the season'),
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
    $month = (int) $form_state->getValue('month');
    $day = (int) $form_state->getValue('day');
    // Feb 29 is allowed: a leap day still names a real date most years.
    if (!checkdate($month, $day, 2024)) {
      $form_state->setErrorByName('day', $this->t('That month has no day @day.', ['@day' => $day]));
    }
    foreach ($form_state->getValue('game_days') ?? [] as $i => $row) {
      $kickoff = trim((string) $row['kickoff']);
      if ($kickoff !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $kickoff)) {
        $form_state->setErrorByName("game_days][$i][kickoff", $this->t('Kickoff times look like 13:25.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $start = sprintf('%02d-%02d', $form_state->getValue('month'), $form_state->getValue('day'));
    $days = [];
    foreach ($form_state->getValue('game_days') ?? [] as $row) {
      $days[] = ['date' => (string) $row['date'], 'kickoff' => substr(trim((string) $row['kickoff']), 0, 5)];
    }
    $this->season->save($start, $days);
    $this->messenger()->addStatus($this->t('Season saved.'));
  }

}
