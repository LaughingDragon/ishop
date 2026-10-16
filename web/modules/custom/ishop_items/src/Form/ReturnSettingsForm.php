<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_items\ReturnWindow;

/**
 * Return window settings: the days and the holiday rule (Phase 6).
 */
final class ReturnSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_return_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ReturnWindow::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t("When an item has a purchase date, its return-by date fills in from these settings. It counts from the order date, so it is about right: Amazon counts from delivery, which is usually a few days later, so reminders come a little early. Change any item's date on its edit page. New settings apply to dates filled in from now on.") . '</p>',
    ];
    $form['days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to return'),
      '#field_suffix' => $this->t('days from the order date'),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
      '#config_target' => ReturnWindow::CONFIG . ':days',
    ];
    $form['holiday'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the holiday returns rule'),
      '#description' => $this->t('Amazon usually lets holiday orders be returned until late January. Check its returns page each fall: the dates can change.'),
      '#config_target' => ReturnWindow::CONFIG . ':holiday',
    ];
    $form['dates'] = [
      '#type' => 'container',
      '#states' => ['visible' => [':input[name="holiday"]' => ['checked' => TRUE]]],
    ];
    $dates = [
      'holiday_from' => $this->t('Orders from'),
      'holiday_to' => $this->t('Orders through'),
      'holiday_until' => $this->t('Returnable until'),
    ];
    foreach ($dates as $key => $title) {
      $form['dates'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#description' => $this->t('Month and day, like 11-01.'),
        '#size' => 6,
        '#maxlength' => 5,
        '#required' => TRUE,
        '#config_target' => ReturnWindow::CONFIG . ':' . $key,
      ];
    }
    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Save');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['holiday_from', 'holiday_to', 'holiday_until'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      if (!preg_match('/^(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[1], (int) $m[2], 2000)) {
        $form_state->setErrorByName($key, $this->t('Use month-day, like 11-01.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

}
