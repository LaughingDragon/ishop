<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\node\NodeInterface;

/**
 * "Posted on <Platform>" with Undo, shown for a few seconds after posting.
 */
final class UndoPostedForm extends FormBase {

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
    return 'ishop_items_undo_posted';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $item = NULL, string $platform = '', bool $all_done = FALSE): array {
    $form_state->set('item', $item);
    $form_state->set('platform', $platform);
    $form['#attributes'] = [
      'class' => ['rm-card', 'ishop-undo'],
      'role' => 'status',
      'data-ishop-undo' => 10,
    ];
    $form['text'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $all_done
        ? $this->t('Posted on @platform. That was the last one: it\'s posted everywhere it fits.', ['@platform' => Platforms::PLATFORMS[$platform]])
        : $this->t('Posted on @platform.', ['@platform' => Platforms::PLATFORMS[$platform]]),
      '#attributes' => ['class' => ['ishop-undo__text']],
    ];
    $form['undo'] = [
      '#type' => 'submit',
      '#value' => $this->t('Undo'),
      '#attributes' => ['class' => ['rm-button', 'rm-button--ghost', 'ishop-undo__button']],
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->get('item');
    $platform = $form_state->get('platform');
    $listing = $this->listings->forItem($item)[$platform] ?? NULL;
    $name = Platforms::PLATFORMS[$platform];
    if ($listing && $this->listings->undoPosted($listing)) {
      $this->messenger()->addStatus($this->t('Undone: @platform is back to @status.', [
        '@platform' => $name,
        '@status' => Platforms::STATUSES[$listing->getStatus()],
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('@platform was changed since, so nothing was undone. Change it on the item page if needed.', ['@platform' => $name]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('ishop_items.post', ['node' => $item->id(), 'platform' => $platform]));
  }

}
