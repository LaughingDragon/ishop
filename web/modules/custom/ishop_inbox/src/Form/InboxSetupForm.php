<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_inbox\Inbox;
use Drupal\ishop_inbox\InboxCredentials;
use Drupal\ishop_office\Form\MailSetupForm;

/**
 * Mail setup, second part: the marketplace inbox's app password (Phase 10 B).
 *
 * Cody saves a Gmail app password for the business Gmail; the site checks
 * it can log in (read-only) before keeping it. Separate from the password
 * the site sends mail with, so either can be revoked on its own.
 */
final class InboxSetupForm extends FormBase {

  public function __construct(
    protected Inbox $inbox,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_inbox_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['id'] = 'inbox';
    $set_up = InboxCredentials::hasPassword();
    $own = InboxCredentials::ownPassword();
    $on = (bool) $this->config('ishop_inbox.settings')->get('enabled');
    $address = InboxCredentials::address();
    $form['state'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if not set_up %} so-callout--warn{% endif %}"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p></div></section>',
      '#context' => [
        'set_up' => $set_up,
        'title' => $this->t('Marketplace inbox'),
        'text' => match (TRUE) {
          $set_up && $on => $this->t('The site reads emails from eBay, Poshmark, Mercari, Depop, Grailed, Vinted and Facebook Marketplace in @address every 10 minutes, and puts what needs you on the front desk. It only reads: it never sends, deletes or marks anything in your mailbox.', ['@address' => $address]),
          $set_up => $this->t('The inbox uses the same app password the site sends mail with. It switches on after a test with the emails already in your inbox.'),
          default => $this->t('Save the app password in the part above first: the inbox uses the same one to read your marketplace emails (sales, labels, ship-by dates, offers, returns). It only reads: it never sends, deletes or marks anything.'),
        },
      ],
    ];
    $form['inbox_address'] = [
      '#type' => 'email',
      '#title' => $this->t('Gmail address'),
      '#default_value' => $address,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['inbox_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Different app password for the inbox (optional)'),
      '#description' => $this->t('Leave empty: the inbox uses the app password above. Only fill this in to give the inbox its own. It is saved privately on the server and never shown again.'),
      '#attributes' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test the inbox'),
      '#button_type' => 'primary',
    ];
    if ($own) {
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove the inbox app password'),
        '#submit' => ['::remove'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['button--danger'], 'formnovalidate' => 'formnovalidate'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $address = strtolower(trim((string) $form_state->getValue('inbox_address')));
    if (!preg_match('/^[a-z0-9.+_-]+@gmail\.com$/', $address)) {
      $form_state->setErrorByName('inbox_address', $this->t('Use the full Gmail address, ending in @gmail.com.'));
    }
    $typed = (string) $form_state->getValue('inbox_password');
    if ($typed === '' && !InboxCredentials::hasPassword()) {
      $form_state->setErrorByName('inbox_password', $this->t('No app password saved yet: save one in the part above first.'));
    }
    if ($typed !== '' && MailSetupForm::normalizePassword($typed) === NULL) {
      $form_state->setErrorByName('inbox_password', $this->t("That doesn't look like a Google app password: it should be 16 letters (Google shows them in four groups of four)."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $address = strtolower(trim((string) $form_state->getValue('inbox_address')));
    $typed = (string) $form_state->getValue('inbox_password');
    $form_state->setValue('inbox_password', NULL);
    if ($typed === '') {
      $error = $this->inbox->testLogin($address, InboxCredentials::password());
      if ($error !== NULL) {
        $this->messenger()->addError($this->t('The inbox could not log in: @error', ['@error' => $error]));
        return;
      }
      $this->inbox->resetStatus();
      $this->messenger()->addStatus($this->t('The site logged in to @address and could read it.', ['@address' => $address]));
      return;
    }
    $password = MailSetupForm::normalizePassword($typed);
    unset($typed);
    $error = $this->inbox->testLogin($address, $password);
    if ($error !== NULL) {
      unset($password);
      $this->messenger()->addError($this->t('Not saved: @error Check the address, make a new app password, and try again.', ['@error' => $error]));
      return;
    }
    if (!InboxCredentials::save($address, $password)) {
      unset($password);
      $this->messenger()->addError($this->t('Could not save on the server. Nothing was changed; tell site-foreman.'));
      return;
    }
    unset($password);
    $this->inbox->resetStatus();
    $this->messenger()->addStatus($this->t('Saved. The site logged in to @address and could read it.', ['@address' => $address]));
    $this->logger('ishop_inbox')->notice('Inbox app password saved by @user; login test passed.', ['@user' => $this->currentUser()->getAccountName()]);
  }

  /**
   * Deletes the inbox-only app password; the sending one takes over.
   */
  public function remove(array &$form, FormStateInterface $form_state): void {
    InboxCredentials::forget();
    $this->messenger()->addStatus($this->t('The inbox app password was deleted from the server. The inbox now uses the app password above.'));
    $this->logger('ishop_inbox')->notice('Inbox app password removed by @user.', ['@user' => $this->currentUser()->getAccountName()]);
  }

}
