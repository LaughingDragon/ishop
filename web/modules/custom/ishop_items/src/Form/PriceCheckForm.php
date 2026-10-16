<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * After checking sold prices: Lower / About right / Higher, or a price.
 *
 * Phase 7.5 (item 270). Records Cody's answer on the item (and in its
 * revision log). A typed price becomes the asking price; Lower or Higher
 * without a price is noted, and the asking price is left alone.
 */
final class PriceCheckForm extends FormBase {

  /**
   * The answers, as stored in field_price_check.
   */
  public const VERDICTS = [
    'lower' => 'Lower',
    'about_right' => 'About right',
    'higher' => 'Higher',
  ];

  public function __construct(
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_price_check';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $item = NULL): array {
    $form_state->set('nid', (int) $item->id());
    $asking = $item->get('field_asking_price')->value;
    $form['#attributes']['class'][] = 'ishop-price-check';
    $form['question'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $asking !== NULL && $asking !== ''
        ? $this->t('Compared with what sold, your price of @price is:', ['@price' => self::dollars((float) $asking)])
        : $this->t('No asking price yet. Compared with what sold, the AI price is:'),
    ];
    // A plain container: an "actions" element would sink below the price.
    $form['verdicts'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions', 'ishop-price-check__verdicts']],
    ];
    foreach (self::VERDICTS as $key => $label) {
      $form['verdicts'][$key] = [
        '#type' => 'submit',
        '#value' => $this->t($label),
        '#name' => 'verdict_' . $key,
        '#verdict' => $key,
        '#attributes' => ['class' => ['rm-button', $key === 'about_right' ? 'rm-button--gold' : 'rm-button--ghost']],
      ];
    }
    $form['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Or type the price it should be'),
      '#field_prefix' => '$',
      '#min' => 0,
      '#step' => '0.01',
      '#size' => 8,
    ];
    $form['use'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use this price'),
      '#name' => 'verdict_typed',
      '#verdict' => 'typed',
      '#attributes' => ['class' => ['rm-button', 'rm-button--gold']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $verdict = $form_state->getTriggeringElement()['#verdict'] ?? NULL;
    $price = $form_state->getValue('price');
    if ($verdict === 'typed' && ($price === '' || $price === NULL)) {
      $form_state->setErrorByName('price', $this->t('Type a price first.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $verdict = $form_state->getTriggeringElement()['#verdict'] ?? NULL;
    $item = $this->entityTypeManager->getStorage('node')->load($form_state->get('nid'));
    if (!$item instanceof NodeInterface || !$item->access('update') || !$verdict) {
      return;
    }
    $price = $form_state->getValue('price');
    $price = $price === '' || $price === NULL ? NULL : round((float) $price, 2);
    [$log, $message] = self::outcome($verdict, $price);
    $item->set('field_price_check', $verdict);
    $item->set('field_price_check_at', $this->time->getRequestTime());
    $item->set('field_price_check_price', $price);
    if ($price !== NULL) {
      $item->set('field_asking_price', $price);
    }
    $item->setNewRevision();
    $item->setRevisionLogMessage($log);
    $item->setRevisionUserId((int) $this->currentUser()->id());
    $item->setRevisionCreationTime($this->time->getRequestTime());
    $item->save();
    $this->messenger()->addStatus($message);
  }

  /**
   * The revision log line and the message for an answer.
   *
   * @return string[]
   *   [log, message].
   */
  public static function outcome(string $verdict, ?float $price): array {
    $label = $verdict === 'typed' ? 'Typed a price' : self::VERDICTS[$verdict];
    if ($price !== NULL) {
      return [
        sprintf('Checked sold prices: %s; asking price set to %s.', $label, self::dollars($price)),
        sprintf('Saved. Asking price is now %s.', self::dollars($price)),
      ];
    }
    return [
      sprintf('Checked sold prices: %s.', $label),
      match ($verdict) {
        'about_right' => 'Saved: the price looks about right.',
        default => sprintf('Noted: %s. Type the new price here when you have decided.', strtolower($label)),
      },
    ];
  }

  /**
   * Dollars for display.
   */
  public static function dollars(float $value): string {
    return '$' . number_format($value, 2);
  }

}
