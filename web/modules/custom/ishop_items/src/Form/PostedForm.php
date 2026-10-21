<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\Cleared;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\node\NodeInterface;

/**
 * "I posted it on <Platform>" on the Post page (Liam, 2026-10-21).
 *
 * One tap: Posted, listed at the asking price, posted today, then on to the
 * next platform still to post, with Undo for a few seconds. The listing link
 * is optional and never asked for.
 */
final class PostedForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms get serialized).
   */
  public function __construct(
    protected ListingManager $listings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_posted';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $item = NULL, string $platform = ''): array {
    $form_state->set('item', $item);
    $form_state->set('platform', $platform);
    $name = Platforms::PLATFORMS[$platform];
    $form['#attributes']['class'][] = 'ishop-posted';
    $form['posted'] = [
      '#type' => 'submit',
      '#value' => $this->t('I posted it on @platform', ['@platform' => $name]),
      '#attributes' => ['class' => ['rm-button', 'rm-button--gold', 'ishop-posted__button']],
    ];
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Listing link (if you have it)'),
      '#size' => 30,
      '#attributes' => ['autocomplete' => 'off', 'inputmode' => 'url'],
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $platform = $form_state->get('platform');
    $url = trim((string) $form_state->getValue('url'));
    $from = $url === '' ? NULL : Platforms::fromUrl($url);
    if ($from !== NULL && $from !== $platform) {
      $form_state->setErrorByName('url', $this->t("That's a @other link, not @platform. Leave it empty if you don't have the @platform one.", [
        '@other' => Platforms::PLATFORMS[$from],
        '@platform' => Platforms::PLATFORMS[$platform],
      ]));
    }
    if (!Cleared::isCleared($form_state->get('item'))) {
      $form_state->setErrorByName('posted', $this->t("Check it's OK to sell first, then tick Cleared to sell on the item."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->get('item');
    $platform = $form_state->get('platform');
    $listing = $this->listings->forItem($item)[$platform] ?? NULL;
    if (!$listing) {
      $this->listings->createDefaults($item);
      $listing = $this->listings->forItem($item)[$platform];
    }
    $this->listings->markPosted($listing, (string) $form_state->getValue('url'));
    $next = $this->listings->nextToPost($item, $platform) ?? $platform;
    $form_state->setRedirectUrl(Url::fromRoute('ishop_items.post', ['node' => $item->id(), 'platform' => $next], [
      'query' => ['posted' => $platform, 'at' => \Drupal::time()->getRequestTime()],
    ]));
  }

}
