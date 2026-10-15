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
    if (SecretScanner::scan((string) $form_state->getValue('answer'))) {
      $form_state->setErrorByName('answer', $this->t('This looks like it contains a password, key or email address. Please remove it; the site office must never hold secrets.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $person = Vocabulary::ACCOUNTS[$account->getAccountName()] ?? NULL;
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
