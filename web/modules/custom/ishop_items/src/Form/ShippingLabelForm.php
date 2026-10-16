<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Shipping;

/**
 * A sale's label PDF and ship-by date (Phase 10 A).
 *
 * The label is a private file (it holds the buyer's address), deleted some
 * days after delivery. A ship-by date typed here replaces the estimate.
 */
final class ShippingLabelForm extends FormBase {

  public function __construct(
    protected Shipping $shipping,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_shipping_label';
  }

  /**
   * Title: "Label and ship-by: <item>".
   */
  public static function title(PlatformListing $platform_listing): string {
    return 'Label and ship-by: ' . ($platform_listing->getItem()?->label() ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlatformListing $platform_listing = NULL): array {
    $form_state->set('listing', (int) $platform_listing->id());
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-ship-form';
    $form['#attached']['library'][] = 'ishop_items/shipping';
    $label = $platform_listing->get('shipping_label')->entity;
    $form['label'] = [
      '#type' => 'managed_file',
      '#title' => $label instanceof FileInterface ? $this->t('Replace the label (PDF)') : $this->t('Label (PDF)'),
      '#description' => $this->t('The label from @platform: download it there, then choose it here. It is kept private and deleted @days days after delivery.', [
        '@platform' => Platforms::PLATFORMS[$platform_listing->getPlatform()],
        '@days' => (int) ($this->config('ishop_items.shipping')->get('label_keep_days') ?? 30),
      ]),
      '#upload_location' => 'private://shipping-labels/' . date('Y-m'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf'],
        'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
      ],
      '#accept' => 'application/pdf,.pdf',
    ];
    if ($label instanceof FileInterface) {
      $form['current'] = [
        '#type' => 'link',
        '#title' => $this->t('Open the current label (PDF)'),
        '#url' => Url::fromUri($label->createFileUrl(FALSE)),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        '#weight' => -1,
      ];
    }
    $form['ship_by'] = [
      '#type' => 'date',
      '#title' => $this->t('Ship by'),
      '#default_value' => $platform_listing->get('ship_by')->value,
      '#description' => $platform_listing->get('ship_by_estimated')->value
        ? $this->t("This date is an estimate. Change it to the date @platform shows for this sale.", ['@platform' => Platforms::PLATFORMS[$platform_listing->getPlatform()]])
        : $this->t('The date @platform gave for this sale.', ['@platform' => Platforms::PLATFORMS[$platform_listing->getPlatform()]]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sale = $this->entityTypeManager->getStorage('platform_listing')->load($form_state->get('listing'));
    $form_state->setRedirect('ishop_items.shipping_room', [], ['fragment' => 'sale-' . $form_state->get('listing')]);
    if (!$sale instanceof PlatformListing || $sale->getStatus() !== Platforms::SOLD_HERE || !$sale->getItem()) {
      $this->messenger()->addWarning($this->t('That listing is no longer a sale.'));
      return;
    }
    $saved = [];
    $fid = $form_state->getValue(['label', 0]);
    $file = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
    if ($file instanceof FileInterface && (string) $file->id() !== (string) $sale->get('shipping_label')->target_id) {
      $this->shipping->setLabel($sale, $file, 'Shipping label added.');
      $saved[] = $this->t('label saved');
    }
    $ship_by = (string) $form_state->getValue('ship_by');
    if ($ship_by !== '' && ($ship_by !== (string) $sale->get('ship_by')->value || $sale->get('ship_by_estimated')->value)) {
      $this->shipping->setShipBy($sale, $ship_by, sprintf('Ship-by date set to %s.', $ship_by));
      $saved[] = $this->t('ship-by date saved');
    }
    $this->messenger()->addStatus($saved ? ucfirst(implode(', ', array_map('strval', $saved))) . '.' : $this->t('Nothing changed.'));
  }

}
