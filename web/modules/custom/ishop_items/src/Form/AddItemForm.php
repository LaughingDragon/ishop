<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\Controller\AddController;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ReadyCheck;
use Drupal\ishop_office\DiskStatus;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Add item" on the phone: photos first, everything else optional.
 *
 * With JavaScript, each photo uploads as soon as it is taken or picked
 * (/add/photo), with "Take another" / "Done" after a camera shot, and
 * "Make cover", up/down and remove on each photo. Without it, the photos
 * picked in the plain file field upload when the form is saved.
 */
final class AddItemForm extends FormBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_add';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'ia-form';
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['#cache']['max-age'] = 0;
    $online = DiskStatus::photosOnline();

    if (!$online) {
      $form['offline'] = ItemHooks::offlineNotice();
      return $form;
    }

    $form['#attached']['library'][] = 'ishop_items/add';
    $form['#attached']['drupalSettings']['ishopAdd'] = [
      'uploadUrl' => Url::fromRoute('ishop_items.add_photo')->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
    ];

    $form['photos'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ia-photos']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Photos')],
      // Shown by add.js; hidden when JavaScript doesn't run.
      'controls' => [
        '#type' => 'inline_template',
        '#template' => '<div class="ia-js" hidden>
  <input type="file" id="ia-camera" class="ia-input" accept="image/*" capture="environment">
  <input type="file" id="ia-library" class="ia-input" accept="image/*" multiple>
  <div class="ia-pick">
    <label for="ia-camera" class="ia-big ia-big--camera"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="4"/></svg>{{ camera }}</label>
    <label for="ia-library" class="ia-big ia-big--library"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/></svg>{{ library }}</label>
  </div>
  <div class="ia-after" hidden role="status">
    <p class="ia-after__text">{{ got_it }}</p>
    <button type="button" class="ia-big ia-another">{{ another }}</button>
    <button type="button" class="ia-big ia-done">{{ done }}</button>
  </div>
  <ol class="ia-strip" aria-label="{{ strip }}"></ol>
  <p class="ia-hint">{{ hint }}</p>
</div>',
        '#context' => [
          'camera' => $this->t('Take photo'),
          'library' => $this->t('Choose photos'),
          'got_it' => $this->t('Got it.'),
          'another' => $this->t('Take another'),
          'done' => $this->t('Done'),
          'strip' => $this->t('Photos for this item, cover first'),
          'hint' => $this->t('The first photo is the cover. Photos upload as you go.'),
        ],
      ],
      'photo_ids' => [
        '#type' => 'hidden',
        '#default_value' => '',
        '#attributes' => ['class' => ['ia-photo-ids']],
      ],
      'fallback' => [
        '#type' => 'file',
        '#title' => $this->t('Photos'),
        '#multiple' => TRUE,
        '#attributes' => ['accept' => 'image/*'],
        '#wrapper_attributes' => ['class' => ['ia-fallback']],
        '#description' => $this->t('Pick one or more photos. They upload when you save.'),
      ],
    ];

    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Details (optional, add them later if you like)'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['ia-details']],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#maxlength' => ItemHooks::TITLE_MAX,
        '#attributes' => ['data-ishop-counter' => '60,70,80', 'autocomplete' => 'off'],
      ],
      'price' => [
        '#type' => 'number',
        '#title' => $this->t('Asking price'),
        '#min' => 0,
        '#step' => 0.01,
        '#field_prefix' => '$',
        '#attributes' => ['inputmode' => 'decimal'],
      ],
      'condition' => [
        '#type' => 'radios',
        '#title' => $this->t('Condition'),
        '#options' => $this->terms('condition'),
      ],
      'category' => [
        '#type' => 'select',
        '#title' => $this->t('Category'),
        '#options' => $this->terms('category'),
        '#empty_option' => $this->t('- Pick later -'),
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save item'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['ia-save']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!DiskStatus::photosOnline()) {
      $form_state->setErrorByName('', $this->t('Photo storage is offline, so items with photos can\'t be added right now.'));
      return;
    }
    $mids = $this->photoIds($form_state);
    foreach (Media::loadMultiple($mids) as $media) {
      if ($media->bundle() !== 'item_photo' || !$media->access('update')) {
        $form_state->setErrorByName('photo_ids', $this->t('One of the photos can\'t be used. Remove it and add it again.'));
        return;
      }
    }
    $files = $this->getRequest()->files->get('files', [])['fallback'] ?? [];
    $files = array_filter(is_array($files) ? $files : [$files], fn ($f) => $f instanceof UploadedFile);
    if (!$mids && !$files) {
      $form_state->setErrorByName('photo_ids', $this->t('Add at least one photo. Everything else can wait.'));
    }
    $form_state->set('fallback_files', $files);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mids = $this->photoIds($form_state);
    // Without JavaScript: photos from the plain file field.
    $controller = \Drupal::classResolver(AddController::class);
    foreach ($form_state->get('fallback_files') ?? [] as $upload) {
      $media = $controller->saveUpload($upload);
      if (is_string($media)) {
        $this->messenger()->addWarning($this->t('One photo was skipped: @why', ['@why' => $media]));
        continue;
      }
      $mids[] = (int) $media->id();
    }
    if (!$mids) {
      $this->messenger()->addError($this->t('No photo could be saved, so the item was not created.'));
      $form_state->setRebuild();
      return;
    }

    $title = trim((string) $form_state->getValue('title'));
    $values = [
      'type' => 'item',
      'title' => $title !== '' ? $title : ReadyCheck::DEFAULT_TITLE_PREFIX . $this->dateFormatter->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d H:i'),
      'uid' => $this->currentUser()->id(),
      'status' => 1,
      'field_photos' => array_map(fn ($mid) => ['target_id' => $mid], $mids),
    ];
    if (($price = $form_state->getValue('price')) !== '' && $price !== NULL) {
      $values['field_asking_price'] = $price;
    }
    foreach (['condition' => 'field_condition', 'category' => 'field_category'] as $key => $field) {
      if ($tid = $form_state->getValue($key)) {
        $values[$field] = ['target_id' => $tid];
      }
    }
    $item = Node::create($values);
    $item->setRevisionLogMessage('Added on the phone (/add).');
    $item->save();
    $this->messenger()->addStatus($this->t('Saved with @n photo(s). Below is what it still needs before listing.', ['@n' => count($mids)]));
    $form_state->setRedirectUrl($item->toUrl());
  }

  /**
   * Media IDs from the hidden field, in order, without duplicates.
   */
  private function photoIds(FormStateInterface $form_state): array {
    $ids = array_filter(array_map('intval', explode(',', (string) $form_state->getValue('photo_ids'))));
    return array_values(array_unique($ids));
  }

  /**
   * Term options for a vocabulary, in weight order (top level only).
   */
  private function terms(string $vid): array {
    $options = [];
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid, 0, 1) as $term) {
      $options[$term->tid] = $term->name;
    }
    return $options;
  }

}
