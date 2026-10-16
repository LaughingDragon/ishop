<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ishop_ai\AiKeyManager;
use Drupal\ishop_ai\AiStatus;
use Drupal\ishop_ai\AnthropicKey;

/**
 * AI setup and renewal: the owner's (Cody's) Anthropic key.
 *
 * Shows what the site knows about the key (never the key), and renews it:
 * a new key is tested first and switched over only if it works, so a failed
 * test leaves the old key in place. Anthropic keys last 30 days.
 */
final class AiSetupForm extends FormBase {

  /**
   * Accounts allowed here: Cody and the webmaster.
   */
  public const ACCOUNTS = ['theowner', 'webmaster'];

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected AiKeyManager $keys,
    protected AiStatus $status,
  ) {}

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
    $status = $this->status->get();
    $today = AiStatus::today();
    $paused = AiStatus::pausedReason($status, $today);
    $days = $status['configured'] && $status['expires_on'] ? AiStatus::daysLeft($status['expires_on'], $today) : NULL;
    $rows = [];
    if ($status['configured']) {
      $rows = [
        ['label' => $this->t('Key'), 'value' => $this->t('ends in …@last4', ['@last4' => $status['last4']])],
        ['label' => $this->t('Saved on'), 'value' => $status['saved_on']],
        [
          'label' => $this->t('Expires on'),
          'value' => $days === NULL ? '' : ($days < 0
            ? $this->t('@date (expired)', ['@date' => $status['expires_on']])
            : $this->formatPlural($days, '@date (1 day left)', '@date (@count days left)', ['@date' => $status['expires_on']])),
        ],
        [
          'label' => $this->t('Last worked'),
          'value' => $status['last_ok'] ? substr((string) $status['last_ok'], 0, 16) : $this->t('not yet'),
        ],
        [
          'label' => $this->t('Last error'),
          'value' => $status['last_error'] ? substr($status['last_error']['time'], 0, 16) . ': ' . $status['last_error']['message'] : $this->t('none'),
        ],
      ];
    }
    $form['state'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if warn %} so-callout--warn{% endif %}"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p>{% if rows %}<dl>{% for r in rows %}<dt>{{ r.label }}</dt><dd>{{ r.value }}</dd>{% endfor %}</dl>{% endif %}</div></section>',
      '#context' => [
        'warn' => !$status['configured'] || $paused || ($days !== NULL && $days <= AiStatus::SHOW_FROM_DAYS),
        'title' => match (TRUE) {
          !$status['configured'] => $this->t('No API key yet'),
          (bool) $paused => $this->t('AI is paused: @reason', ['@reason' => AiStatus::PAUSE_REASONS[$paused] ?? $paused]),
          default => $this->t('AI is on'),
        },
        'text' => $status['configured']
          ? $this->t('The AI helpers use your own Anthropic account. Keys last 30 days; you get a reminder 7 days, 2 days and on the day before it expires. When AI is paused, everything else keeps working and items and photos still save.')
          : $this->t('The AI listing helpers (Phase 7) run on your own Anthropic account and are paid by it.'),
        'rows' => $rows,
      ],
    ];

    $form['renew'] = [
      '#type' => 'details',
      '#title' => $status['configured'] ? $this->t('Renew or replace the key') : $this->t('Connect your key'),
      '#open' => !$status['configured'] || $paused || ($days !== NULL && $days <= AiStatus::SHOW_FROM_DAYS),
      '#attributes' => ['id' => 'renew'],
    ];
    $form['renew']['steps'] = [
      '#type' => 'inline_template',
      '#template' => '<ol>{% for s in steps %}<li>{{ s }}</li>{% endfor %}</ol>',
      '#context' => [
        'steps' => [
          $this->t('Go to console.anthropic.com and sign in with your own account.'),
          $this->t('Open Settings, then API keys, and choose Create key. Name it "Reseller Matrix @month".', ['@month' => date('Y-m')]),
          $this->t('Anthropic shows the key once. It starts with "sk-ant-". Copy it.'),
          $this->t('Paste it below. If Anthropic shows an expiry date for the key, enter it; otherwise leave the date as it is (30 days from today).'),
          $this->t('Tap "Test and switch to this key". If the test works, the site switches over at once. If it fails, nothing changes and the old key keeps working.'),
        ],
      ],
    ];
    $form['renew']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('New Anthropic API key'),
      '#description' => $this->t('Saved privately on the server and never shown again, not even to you.'),
      '#attributes' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['renew']['expires'] = [
      '#type' => 'date',
      '#title' => $this->t('Key expires on'),
      '#default_value' => AiStatus::defaultExpiry($today),
    ];
    $form['renew']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test and switch to this key'),
      '#button_type' => 'primary',
    ];

    if ($status['configured']) {
      $form['actions'] = ['#type' => 'actions'];
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
    $expires = (string) $form_state->getValue('expires');
    if ($expires === '' || $expires < AiStatus::today()) {
      $form_state->setErrorByName('expires', $this->t('Enter the date the new key expires (today or later).'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $typed = (string) $form_state->getValue('api_key');
    // Drop the typed value from the form state as soon as possible.
    $form_state->setValue('api_key', NULL);
    $result = $this->keys->renew($typed, (string) $form_state->getValue('expires'));
    unset($typed);
    if (!$result['ok']) {
      $this->messenger()->addError($this->t('The new key did not work, so nothing changed (the old key, if any, is still in use): @message', ['@message' => $result['message']]));
      return;
    }
    $this->messenger()->addStatus($this->t('Key works. The site is now using the new key.'));
    if ($result['old4']) {
      $this->messenger()->addWarning($this->t('Now delete the old key in your Anthropic account: Settings, then API keys, the key ending in …@old4.', ['@old4' => $result['old4']]));
    }
  }

  /**
   * Tests the saved key (and pauses or resumes AI accordingly).
   */
  public function testSaved(array &$form, FormStateInterface $form_state): void {
    $result = $this->keys->testSaved();
    $result['ok']
      ? $this->messenger()->addStatus($this->t('Key works. AI is on.'))
      : $this->messenger()->addError($this->t('The saved key does not work: @message', ['@message' => $result['message']]));
  }

  /**
   * Removes the key.
   */
  public function remove(array &$form, FormStateInterface $form_state): void {
    $this->keys->remove();
    $this->messenger()->addStatus($this->t('Key removed: it was deleted from the server. The AI helpers are off until a new key is saved. You can also delete it in your Anthropic account.'));
  }

}
