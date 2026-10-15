<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_office\Crew;
use Drupal\ishop_office\CrewState;
use Drupal\ishop_office\OfficeManager;
use Drupal\ishop_office\Parser\SecretScanner;
use Drupal\ishop_office\Vocabulary;
use Drupal\node\NodeInterface;

/**
 * Lets Liam or Cody answer an open punch list item on its own page.
 *
 * While the crew is on site (see CrewState) answers are locked: the form
 * renders disabled and saves are refused. The webmaster can answer anyway.
 */
final class AnswerPunchForm extends FormBase {

  /**
   * The account that may answer while the crew is on site.
   */
  private const OVERRIDE_ACCOUNT = 'webmaster';

  public function __construct(
    private readonly OfficeManager $office,
    private readonly CrewState $crew,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_office_answer_punch';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('nid', $node?->id());
    $form['answer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your answer'),
      '#description' => $this->t('Saving closes the item and records your answer. Never put passwords or keys here.'),
      '#rows' => 5,
      '#required' => TRUE,
    ];
    // Shown once an answer containing an email address has been submitted.
    $typed = (string) ($form_state->getUserInput()['answer'] ?? '');
    if (SecretScanner::classify(SecretScanner::scan($typed))['emails']) {
      $form['email_ok'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("This email address is ours (for example the address the site sends from), not a customer's."),
      ];
    }
    // Claude (review) answers from Liam's logged-in browser, so it says so.
    if ($node && in_array('claude', array_column($node->get('field_waiting_on')->getValue(), 'value'), TRUE)) {
      $me = Vocabulary::ACCOUNTS[$this->currentUser()->getAccountName()] ?? NULL;
      $form['answered_as'] = [
        '#type' => 'radios',
        '#title' => $this->t('Who is answering?'),
        '#options' => array_filter([
          $me => $me ? Vocabulary::PEOPLE[$me] : NULL,
          'claude' => Vocabulary::PEOPLE['claude'],
        ]),
        '#default_value' => $me ?? 'claude',
        '#required' => TRUE,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save answer and close'),
      '#button_type' => 'primary',
    ];
    if ($this->crew->isActive()) {
      $this->lock($form);
    }
    return $form;
  }

  /**
   * Renders the form disabled while the crew is on site.
   *
   * Uses the HTML disabled attribute rather than #disabled, so an answer typed
   * before the crew arrived is kept when the save is refused.
   */
  private function lock(array &$form): void {
    $form['crew_lock'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="ishop-office-crew-lock" role="status">{{ text }} <a href="{{ url }}">{{ link }}</a></p>',
      '#context' => [
        'text' => $this->t('Crew on site: answers open when they finish.'),
        'url' => Url::fromRoute('ishop_office.crew')->toString(),
        'link' => $this->t('What is the crew doing?'),
      ],
      '#weight' => -20,
    ];
    if ($this->currentUser()->getAccountName() === self::OVERRIDE_ACCOUNT) {
      $form['answer_anyway'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Answer anyway (emergencies only: the crew is working right now)'),
        '#weight' => -10,
      ];
      $off = [':input[name="answer_anyway"]' => ['checked' => FALSE]];
      $form['answer']['#states']['disabled'] = $off;
      $form['actions']['submit']['#states']['disabled'] = $off;
    }
    else {
      $form['answer']['#attributes']['disabled'] = 'disabled';
      $form['actions']['submit']['#attributes']['disabled'] = 'disabled';
    }
    $form['#cache']['tags'][] = Crew::CACHE_TAG;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Checked on the server too: the form may have been loaded before the
    // crew arrived, or its disabled attribute removed.
    if ($this->crew->isActive()) {
      $override = $this->currentUser()->getAccountName() === self::OVERRIDE_ACCOUNT && $form_state->getValue('answer_anyway');
      if (!$override) {
        $form_state->setErrorByName('answer', $this->t('Not saved: the crew is on site, so answers are locked until they finish. Your answer is kept below.'));
        return;
      }
    }
    $found = SecretScanner::classify(SecretScanner::scan((string) $form_state->getValue('answer')));
    if ($found['secrets']) {
      $form_state->setErrorByName('answer', $this->t('Not saved: your answer looks like it contains a secret (@kinds). Please remove it and save again. Passwords and keys never go in the site office.', ['@kinds' => implode(', ', $found['secrets'])]));
    }
    elseif ($found['emails'] && !$form_state->getValue('email_ok')) {
      $form_state->setErrorByName('email_ok', $this->t("Not saved yet: your answer includes an email address. If it's one of ours, tick the box under the answer and press Save again. Customer email addresses must not go in the site office."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $person = Vocabulary::ACCOUNTS[$account->getAccountName()] ?? NULL;
    if ($form_state->getValue('answered_as') === 'claude') {
      $person = 'claude';
    }
    if ($person === NULL) {
      $this->messenger()->addError($this->t('Your account is not linked to a person in the site office, so the answer was not saved.'));
      return;
    }
    try {
      $node = $this->office->closePunch((int) $form_state->get('nid'), trim((string) $form_state->getValue('answer')), $person, NULL, $account);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    if ($form_state->getValue('answer_anyway')) {
      $this->logger('ishop_office')->warning('Punch list item @nid answered while the crew was on site (Answer anyway, @user).', [
        '@nid' => $node->id(),
        '@user' => $account->getAccountName(),
      ]);
    }
    $this->messenger()->addStatus($this->t('Thanks. "@question" is answered and closed.', ['@question' => $node->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('ishop_office.punch_list'));
  }

}
