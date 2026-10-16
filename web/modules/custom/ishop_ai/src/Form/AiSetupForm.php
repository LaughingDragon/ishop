<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ishop_ai\AnthropicKey;

/**
 * Lets the owner (Cody) save an Anthropic API key (Phase 7 step 1).
 *
 * Built like the mail setup page: the key goes only into secrets/, is never
 * shown again, and saving makes one tiny test call so Cody sees at once
 * whether it works.
 */
final class AiSetupForm extends FormBase {

  /**
   * Accounts allowed to set this up: Cody and the webmaster.
   */
  public const ACCOUNTS = ['theowner', 'webmaster'];

  /**
   * Access: Cody and the webmaster only.
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(in_array($account->getAccountName(), self::ACCOUNTS, TRUE))->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_ai_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $key = AnthropicKey::get();
    $form['state'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if not set_up %} so-callout--warn{% endif %}"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p></div></section>',
      '#context' => [
        'set_up' => $key !== NULL,
        'title' => $key !== NULL ? $this->t('An API key is saved') : $this->t('No API key yet'),
        'text' => $key !== NULL
          ? $this->t('The site uses the key ending @mask on your own Anthropic account. To replace it, save a new one below; "Test the saved key" checks it again.', ['@mask' => AnthropicKey::mask($key)])
          : $this->t('The AI listing helpers (Phase 7) run on your own Anthropic account and are paid by it. Paste your API key below.'),
      ],
    ];
    unset($key);
    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Anthropic API key'),
      '#description' => $this->t('Starts with "sk-ant-". It is saved privately on the server and never shown again, not even to you.'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and test the key'),
      '#button_type' => 'primary',
    ];
    if (AnthropicKey::has()) {
      $form['actions']['test'] = [
        '#type' => 'submit',
        '#value' => $this->t('Test the saved key'),
        '#submit' => ['::testSaved'],
        '#limit_validation_errors' => [],
      ];
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove key'),
        '#submit' => ['::remove'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['button--danger']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (AnthropicKey::normalize((string) $form_state->getValue('api_key')) === NULL) {
      // Never echo what was typed.
      $form_state->setErrorByName('api_key', $this->t('That doesn\'t look like an Anthropic API key: it should start with "sk-ant-" and be one long line. Copy it again from the Anthropic Console.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $key = AnthropicKey::normalize((string) $form_state->getValue('api_key'));
    // Drop the typed value from the form state as soon as possible.
    $form_state->setValue('api_key', NULL);
    if (!AnthropicKey::save($key)) {
      $this->messenger()->addError($this->t('Could not save on the server. Nothing was changed; tell site-foreman.'));
      return;
    }
    $this->report($key, 'saved');
    unset($key);
  }

  /**
   * Tests the key that is already saved.
   */
  public function testSaved(array &$form, FormStateInterface $form_state): void {
    $key = AnthropicKey::get();
    if ($key === NULL) {
      $this->messenger()->addError($this->t('No key is saved.'));
      return;
    }
    $this->report($key, 'tested');
    unset($key);
  }

  /**
   * Removes the key.
   */
  public function remove(array &$form, FormStateInterface $form_state): void {
    AnthropicKey::remove();
    $this->messenger()->addStatus($this->t('Key removed: it was deleted from the server. The AI helpers are off until a new key is saved.'));
    $this->logger('ishop_ai')->notice('Anthropic API key removed by @user.', ['@user' => $this->currentUser()->getAccountName()]);
  }

  /**
   * Runs the test call and says plainly whether the key works.
   */
  private function report(string $key, string $what): void {
    $error = AnthropicKey::test($key);
    $mask = AnthropicKey::mask($key);
    if ($error === NULL) {
      $this->messenger()->addStatus($this->t('Key works (@mask). Anthropic answered a tiny test message.', ['@mask' => $mask]));
      $this->logger('ishop_ai')->notice('Anthropic API key @what by @user: test call OK.', [
        '@what' => $what,
        '@user' => $this->currentUser()->getAccountName(),
      ]);
    }
    else {
      $this->messenger()->addError($this->t('Key @mask does not work yet: @error', [
        '@mask' => $mask,
        '@error' => $error,
      ]));
      $this->logger('ishop_ai')->warning('Anthropic API key @what by @user: test call failed: @error', [
        '@what' => $what,
        '@user' => $this->currentUser()->getAccountName(),
        '@error' => $error,
      ]);
    }
  }

}
