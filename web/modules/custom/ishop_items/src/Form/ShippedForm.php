<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Shipping;
use Drupal\ishop_items\Tracking;

/**
 * Shipped (Phase 10 A): the tracking number, the carrier, the postage.
 *
 * The carrier is guessed from the number when it can be; otherwise Cody
 * picks it. No number is fine for a hand-over, with a tick to say so.
 */
final class ShippedForm extends FormBase {

  public function __construct(
    protected Shipping $shipping,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_shipped';
  }

  /**
   * Title: "Shipped: <item>".
   */
  public static function title(PlatformListing $platform_listing): string {
    return 'Shipped: ' . ($platform_listing->getItem()?->label() ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlatformListing $platform_listing = NULL): array {
    $form_state->set('listing', (int) $platform_listing->id());
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-ship-form';
    $form['#attached']['library'][] = 'ishop_items/shipping';
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Sold on @platform. Type or paste the tracking number from the label.', [
        '@platform' => Platforms::PLATFORMS[$platform_listing->getPlatform()],
      ]),
    ];
    $form['number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tracking number'),
      '#maxlength' => 64,
      '#attributes' => ['autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false'],
    ];
    $form['carrier'] = [
      '#type' => 'select',
      '#title' => $this->t('Carrier'),
      '#options' => ['' => $this->t('Work it out from the number')] + Tracking::CARRIERS,
      '#default_value' => '',
    ];
    $form['postage'] = [
      '#type' => 'number',
      '#title' => $this->t('Postage you paid (leave blank if the buyer paid)'),
      '#field_prefix' => '$',
      '#min' => 0,
      '#step' => '0.01',
      '#size' => 8,
      '#default_value' => $platform_listing->get('shipping_paid')->value,
    ];
    $form['no_number'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('No tracking number (handed over in person)'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Shipped'),
      '#attributes' => ['class' => ['rm-button', 'rm-button--gold']],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('ishop_items.shipping_room', [], ['fragment' => 'sale-' . $platform_listing->id()]),
      '#attributes' => ['class' => ['rm-button', 'rm-button--ghost']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $number = Tracking::normalize((string) $form_state->getValue('number'));
    if ($number === '' && !$form_state->getValue('no_number')) {
      $form_state->setErrorByName('number', $this->t('Type the tracking number, or tick "No tracking number".'));
      return;
    }
    if ($number !== '' && $form_state->getValue('carrier') === '') {
      $guess = Tracking::guess($number);
      if ($guess === NULL) {
        $form_state->setErrorByName('carrier', $this->t("Couldn't tell the carrier from that number: choose it."));
        return;
      }
      $form_state->setValue('carrier', $guess);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sale = $this->listing($form_state);
    if (!$sale) {
      $this->messenger()->addWarning($this->t('That listing is no longer a sale.'));
      $form_state->setRedirect('ishop_items.shipping_room');
      return;
    }
    if (!$sale->get('date_shipped')->isEmpty()) {
      $this->messenger()->addStatus($this->t('Already marked Shipped.'));
      $form_state->setRedirect('ishop_items.shipping_room', [], ['fragment' => 'on-the-way']);
      return;
    }
    $number = (string) $form_state->getValue('number');
    $postage = $form_state->getValue('postage');
    $carrier = Tracking::normalize($number) === '' ? '' : (string) $form_state->getValue('carrier');
    $this->shipping->markShipped($sale, $number, $carrier, $postage === '' || $postage === NULL ? NULL : round((float) $postage, 2));
    $this->messenger()->addStatus($this->t('Shipped: @item. It is under On the way until you tap Delivered.', ['@item' => $sale->getItem()->label()]));
    $form_state->setRedirect('ishop_items.shipping_room', [], ['fragment' => 'sale-' . $sale->id()]);
  }

  /**
   * The sale being shipped, or NULL if it is no longer a sale.
   */
  private function listing(FormStateInterface $form_state): ?PlatformListing {
    $sale = $this->entityTypeManager->getStorage('platform_listing')->load($form_state->get('listing'));
    return $sale instanceof PlatformListing && $sale->getStatus() === Platforms::SOLD_HERE && $sale->getItem() ? $sale : NULL;
  }

}
