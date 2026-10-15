<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ishop_matrix\Birthday;
use Drupal\ishop_matrix\BirthdayMail;

/**
 * Birthday mode settings and preview (TEMPORARY: see TEMPORARY.md).
 *
 * Only webmaster and site-foreman can open it.
 */
final class BirthdayForm extends FormBase {

  public function __construct(
    private readonly Birthday $birthday,
    private readonly BirthdayMail $birthdayMail,
  ) {}

  /**
   * Access: webmaster and site-foreman only (by account).
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(Birthday::isManager($account))->addCacheContexts(['user']);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_matrix_birthday';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->birthday->settings();
    $uid = (int) $this->currentUser()->id();
    $on_for_me = $this->birthday->isActive($this->currentUser());

    $form['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $on_for_me
        ? $this->t('<strong>Birthday mode is ON for you right now.</strong>')
        : $this->t('<strong>Birthday mode is off for you right now.</strong>'),
    ];
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t("Cody's birthday"),
      '#description' => $this->t('Only the month and day are used. Leave empty until the date is confirmed.'),
      '#default_value' => $settings['date'] !== '' ? '2026-' . $settings['date'] : '',
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('For everyone'),
      '#options' => array_map(fn ($label) => $this->t($label), Birthday::MODES),
      '#default_value' => $settings['mode'],
    ];
    $form['preview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preview it for me only'),
      '#description' => $this->t('Shows birthday mode on the Reseller Matrix pages for your account only. Cody does not see it.'),
      '#default_value' => in_array($uid, $settings['preview'], TRUE),
    ];
    $form['launch_again'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Play the first-open launch again for me'),
      '#description' => $this->t('The first page Cody opens on the day starts the party by itself (or asks for one tap where the browser requires it), once per day. Tick this to see that again on your next page.'),
    ];
    $form['video'] = [
      '#type' => 'item',
      '#title' => $this->t("Cody's requested birthday video"),
      '#markup' => $this->t('<a href=":url" target="_blank" rel="noopener noreferrer">:url</a><br>On the day, the dashboard shows it below the birthday sign as "Your requested wake-up call", with a "Play it on YouTube" button (a plain link out, no embed). It does not replace the song.', [':url' => Birthday::VIDEO_URL]),
    ];

    $sent = $settings['mail_sent'] ?? '';
    $form['mail'] = [
      '#type' => 'details',
      '#title' => $this->t('Birthday email to Cody'),
      '#open' => TRUE,
      '#description' => $this->t("Sent once at 7:00 a.m. Pacific on the birthday to Cody's account email, through the site's Gmail, only if the text below is approved. @status", [
        '@status' => $sent !== '' ? $this->t('Sent on @date.', ['@date' => $sent]) : $this->t('Not sent yet.'),
      ]),
    ];
    $form['mail']['mail_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $settings['mail_subject'],
      '#maxlength' => 200,
    ];
    $form['mail']['mail_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text'),
      '#default_value' => $settings['mail_body'],
      '#rows' => 10,
      '#description' => $this->t('Plain text, exactly as Cody will read it.'),
    ];
    $form['mail']['mail_approved'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Approved: send this to Cody on the day'),
      '#default_value' => !empty($settings['mail_approved']),
    ];
    if ($uid === BirthdayMail::WEBMASTER_UID) {
      $form['mail']['test'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save and send a test to webmaster'),
        '#submit' => ['::submitForm', '::sendTest'],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    $form['actions']['dashboard'] = [
      '#type' => 'link',
      '#title' => $this->t('Open the dashboard'),
      '#url' => Url::fromRoute('ishop_matrix.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->birthday->settings();
    $uid = (int) $this->currentUser()->id();
    $date = (string) $form_state->getValue('date');
    $settings['date'] = $date !== '' ? substr($date, 5, 5) : '';
    $settings['mode'] = isset(Birthday::MODES[$form_state->getValue('mode')]) ? $form_state->getValue('mode') : 'auto';
    $settings['preview'] = array_diff($settings['preview'], [$uid]);
    if ($form_state->getValue('preview')) {
      $settings['preview'][] = $uid;
    }
    $settings['mail_subject'] = trim((string) $form_state->getValue('mail_subject'));
    $settings['mail_body'] = (string) $form_state->getValue('mail_body');
    $settings['mail_approved'] = (bool) $form_state->getValue('mail_approved');
    $this->birthday->save($settings);
    if ($form_state->getValue('launch_again')) {
      $this->birthday->resetLaunch($this->currentUser());
    }
    $this->messenger()->addStatus($this->t('Birthday mode saved.'));
  }

  /**
   * Sends the email, as saved, to webmaster only.
   */
  public function sendTest(array &$form, FormStateInterface $form_state): void {
    if (!$this->birthdayMail->hasText()) {
      $this->messenger()->addError($this->t('Write a subject and text first.'));
      return;
    }
    $this->birthdayMail->sendTest($this->currentUser())
      ? $this->messenger()->addStatus($this->t("A test was sent to webmaster's email only. Cody gets nothing until the day."))
      : $this->messenger()->addError($this->t('The test could not be sent. Check the mail status on the site office board.'));
  }

}
