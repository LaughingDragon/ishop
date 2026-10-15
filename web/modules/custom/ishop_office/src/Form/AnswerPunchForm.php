<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_office\OfficeManager;
use Drupal\ishop_office\Parser\SecretScanner;
use Drupal\ishop_office\Vocabulary;
use Drupal\node\NodeInterface;

/**
 * Lets Liam or Cody answer an open punch list item on its own page.
 */
final class AnswerPunchForm extends FormBase {

  public function __construct(
    private readonly OfficeManager $office,
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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
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
    $this->messenger()->addStatus($this->t('Thanks. "@question" is answered and closed.', ['@question' => $node->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('ishop_office.punch_list'));
  }

}
