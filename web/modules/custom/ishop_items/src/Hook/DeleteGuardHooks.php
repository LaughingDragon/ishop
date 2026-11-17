<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\node\NodeInterface;

/**
 * Asks "Did it sell?" before deleting an item that is still posted.
 *
 * Against double-selling (Liam, 2026-11-17): Cody deleted sold items, so
 * the other sites never got their take-down reminders. Deleting an item
 * still Posted (or Posted - at risk) anywhere asks first:
 * "Yes, mark sold" -> which site did it sell on -> Sold here with the usual
 * cascade (the other listings become Take down); "No, just delete" deletes.
 * Items not posted anywhere delete without asking.
 */
final class DeleteGuardHooks {

  use StringTranslationTrait;

  /**
   * Listing statuses that still need a take-down if the item sold.
   */
  public const LIVE = [Platforms::POSTED, Platforms::AT_RISK];

  /**
   * Statuses a sale can be marked on (step 2's choices).
   */
  public const SELLABLE = [Platforms::POSTED, Platforms::AT_RISK, Platforms::TO_POST];

  /**
   * Implements hook_form_FORM_ID_alter() for the item delete form.
   */
  #[Hook('form_node_item_delete_form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->getFormObject()->getEntity();
    if (!$item instanceof NodeInterface) {
      return;
    }
    $listings = \Drupal::service(ListingManager::class)->forItem($item);
    $live = array_filter($listings, fn (PlatformListing $l) => in_array($l->getStatus(), self::LIVE, TRUE));
    if (!$live) {
      return;
    }
    $names = implode(', ', array_map(fn (PlatformListing $l) => Platforms::PLATFORMS[$l->getPlatform()], $live));

    if ($form_state->get('ishop_sold_step')) {
      $options = [];
      foreach ($listings as $listing) {
        if (in_array($listing->getStatus(), self::SELLABLE, TRUE)) {
          $options[$listing->id()] = Platforms::PLATFORMS[$listing->getPlatform()];
        }
      }
      $form['description']['#markup'] = $this->t('Which site did it sell on? The others will show as Take down in "Needs you now".');
      $form['sold_on'] = [
        '#type' => 'radios',
        '#title' => $this->t('Sold on'),
        '#options' => $options,
        '#required' => TRUE,
        '#weight' => -5,
      ];
      $form['actions']['submit']['#access'] = FALSE;
      $form['actions']['mark_sold'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark sold'),
        '#button_type' => 'primary',
        '#submit' => [[self::class, 'markSold']],
        '#weight' => -10,
      ];
      return;
    }

    $form['description']['#markup'] = $this->t('Did it sell? Mark it sold so the site reminds you to take it down from @platforms.', ['@platforms' => $names]);
    $form['actions']['ask_sold'] = [
      '#type' => 'submit',
      '#value' => $this->t('Yes, mark sold'),
      '#button_type' => 'primary',
      '#submit' => [[self::class, 'askWhere']],
      '#limit_validation_errors' => [],
      '#weight' => -10,
    ];
    $form['actions']['submit']['#value'] = $this->t('No, just delete');
    $form['actions']['submit']['#button_type'] = 'danger';
  }

  /**
   * "Yes, mark sold": show step 2 (which site).
   */
  public static function askWhere(array &$form, FormStateInterface $form_state): void {
    $form_state->set('ishop_sold_step', TRUE);
    $form_state->setRebuild();
  }

  /**
   * Step 2: Sold here on the chosen listing, with the usual cascade.
   */
  public static function markSold(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->getFormObject()->getEntity();
    $listing = \Drupal::entityTypeManager()->getStorage('platform_listing')->load((int) $form_state->getValue('sold_on'));
    if (!$listing instanceof PlatformListing || (int) $listing->getItem()?->id() !== (int) $item->id() || !in_array($listing->getStatus(), self::SELLABLE, TRUE)) {
      \Drupal::messenger()->addError(t('That listing changed in the meantime. Nothing was marked sold or deleted.'));
      $form_state->setRedirectUrl($item->toUrl());
      return;
    }
    $manager = \Drupal::service(ListingManager::class);
    $platform = Platforms::PLATFORMS[$listing->getPlatform()];
    $plan = $manager->planSale($listing);
    $manager->applySale($listing, sprintf('Sold on %s (Cody, from the delete page: "Did it sell?").', $platform));
    $takedown = array_keys(array_filter($plan, fn (array $change) => $change['to'] === Platforms::TAKE_DOWN));
    $takedown = array_map(fn (string $p) => Platforms::PLATFORMS[$p], $takedown);
    \Drupal::messenger()->addStatus($takedown
      ? t('Marked sold on @platform, not deleted. Take it down from @list: it is in "Needs you now".', ['@platform' => $platform, '@list' => implode(', ', $takedown)])
      : t('Marked sold on @platform, not deleted.', ['@platform' => $platform]));
    $form_state->setRedirectUrl($item->toUrl());
  }

}
