<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * My office > Mail setup: "Mark certain sales automatically" (on by default).
 *
 * On: certain matches act by themselves (sales with Undo for 7 days,
 * shipped, delivered, payouts, listing links), listed in the morning digest.
 * Off: every email asks first, as before.
 */
final class InboxAutoForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_inbox_auto';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['auto'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark certain sales automatically'),
      '#default_value' => $this->config('ishop_inbox.settings')->get('auto') ?? TRUE,
      '#description' => $this->t('When an email is certainly about one of your items (same site, the listing is Posted, the title matches only that item, and the price is within 10% of what you listed), the site marks it sold, saves the price, date, label and ship-by date, and reminds you to take it down elsewhere. You can Undo it on the front desk for 7 days. Shipped, delivered, payouts and listing links work the same way. Returns and cases always wait for you. Everything it did by itself is in the morning email under "While you were away". Untick to have it ask you every time.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $on = (bool) $form_state->getValue('auto');
    $this->configFactory()->getEditable('ishop_inbox.settings')->set('auto', $on)->save();
    $this->messenger()->addStatus($on
      ? $this->t('Saved: certain sales are marked automatically, with Undo on the front desk.')
      : $this->t('Saved: the site will ask you about every email.'));
    $this->logger('ishop_inbox')->notice('Automatic inbox actions @state by @user.', ['@state' => $on ? 'on' : 'off', '@user' => $this->currentUser()->getAccountName()]);
  }

}
