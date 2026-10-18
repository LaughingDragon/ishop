<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\Controller\AddController;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ItemPhotos;
use Drupal\ishop_office\DiskStatus;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Add photos to an item that already exists (Phase 10).
 *
 * The same picker as Add item: choose several photos at once from the
 * phone (or take them), they upload one by one, then reorder, pick the
 * cover, remove. Up to ItemPhotos::MAX. "Save and next" goes straight to
 * the next item still without photos.
 */
final class ItemPhotosForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected ItemPhotos $photos,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_item_photos';
  }

  /**
   * Title: "Photos: <item>".
   */
  public static function title(NodeInterface $node): string {
    return (string) t('Photos: @item', ['@item' => $node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('nid', (int) $node->id());
    $form['#attributes']['class'][] = 'ia-form';
    $form['#attributes']['class'][] = 'ia-form--photos';
    if (!DiskStatus::photosOnline()) {
      $form['offline'] = ItemHooks::offlineNotice();
      return $form;
    }
    $existing = [];
    $thumb = ImageStyle::load('medium');
    foreach ($node->get('field_photos') as $reference) {
      $file = $reference->entity?->get('field_media_image_1')->entity;
      if ($file) {
        $existing[] = ['mid' => (int) $reference->target_id, 'thumb' => $thumb->buildUrl($file->getFileUri())];
      }
    }
    $form['#attached']['library'][] = 'ishop_items/add';
    $form['#attached']['drupalSettings']['ishopAdd'] = [
      'uploadUrl' => Url::fromRoute('ishop_items.add_photo')->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
      'existing' => $existing,
      'max' => ItemPhotos::MAX,
    ];
    $left = count($this->photos->withoutPhotos());
    $form['intro'] = [
      '#markup' => '<p class="ia-mode">' . $this->t('Choose up to @max photos (3 to 5 is usually right). Tap <strong>Choose photos</strong> and select several at once. The first one is the cover.', ['@max' => ItemPhotos::MAX])
      . ($left ? ' ' . $this->formatPlural($left, '1 item still has no photos.', '@count items still have no photos.') : '') . '</p>',
    ];
    $form['photos'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ia-photos']],
      // Shown by add.js; hidden when JavaScript doesn't run.
      'controls' => [
        '#type' => 'inline_template',
        '#template' => '<div class="ia-js" hidden>
  <input type="file" id="ia-camera" class="ia-input" accept="image/*" capture="environment">
  <input type="file" id="ia-library" class="ia-input" accept="image/*" multiple>
  <div class="ia-pick">
    <label for="ia-library" class="ia-big ia-big--library"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/></svg>{{ library }}</label>
    <label for="ia-camera" class="ia-big ia-big--camera"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="4"/></svg>{{ camera }}</label>
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
          'hint' => $this->t('The first photo is the cover. Photos upload as you go; tap Save when they are all in.'),
        ],
      ],
      'photo_ids' => [
        '#type' => 'hidden',
        '#default_value' => implode(',', array_column($existing, 'mid')),
        '#attributes' => ['class' => ['ia-photo-ids']],
      ],
      'fallback' => [
        '#type' => 'file',
        '#title' => $this->t('Photos'),
        '#multiple' => TRUE,
        '#attributes' => ['accept' => 'image/*'],
        '#wrapper_attributes' => ['class' => ['ia-fallback']],
        '#description' => $this->t('Pick one or more photos. They are added when you save.'),
      ],
    ];
    $form['actions'] = ['#type' => 'actions', '#attributes' => ['class' => ['ia-actions']]];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and go to the next item without photos'),
      '#name' => 'next',
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['ia-save']],
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save photos'),
      '#name' => 'save',
      '#attributes' => ['class' => ['ia-save', 'rm-button--ghost']],
    ];
    $skip = $this->photos->nextWithoutPhotos((int) $node->id());
    $form['actions']['links'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="ia-links">{% if skip %}<a href="{{ skip }}">{{ skip_label }}</a> · {% endif %}<a href="{{ item }}">{{ item_label }}</a></p>',
      '#context' => [
        'skip' => $skip && $skip !== (int) $node->id() ? Url::fromRoute('ishop_items.item_photos', ['node' => $skip])->toString() : NULL,
        'skip_label' => $this->t('Skip this item'),
        'item' => $node->toUrl()->toString(),
        'item_label' => $this->t('Back to the item'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!DiskStatus::photosOnline()) {
      $form_state->setErrorByName('', $this->t("Photo storage is offline right now, so photos can't be added. Tell site-foreman."));
      return;
    }
    $mids = $this->photoIds($form_state);
    $files = $this->getRequest()->files->get('files', [])['fallback'] ?? [];
    $files = array_filter(is_array($files) ? $files : [$files], fn ($f) => $f instanceof UploadedFile);
    if (count($mids) + count($files) > ItemPhotos::MAX) {
      $form_state->setErrorByName('photo_ids', $this->t('Up to @max photos per item. Remove some and save again.', ['@max' => ItemPhotos::MAX]));
      return;
    }
    foreach (Media::loadMultiple($mids) as $media) {
      if ($media->bundle() !== 'item_photo' || !$media->access('update')) {
        $form_state->setErrorByName('photo_ids', $this->t("One of the photos can't be used. Remove it and add it again."));
        return;
      }
    }
    // A photo belongs to one item only.
    if ($mids) {
      $elsewhere = $this->database->select('node__field_photos', 'p')->fields('p', ['entity_id'])
        ->condition('field_photos_target_id', $mids, 'IN')
        ->condition('entity_id', $form_state->get('nid'), '<>')
        ->execute()->fetchCol();
      if ($elsewhere) {
        $form_state->setErrorByName('photo_ids', $this->t('One of the photos already belongs to another item. Remove it and add it again.'));
        return;
      }
    }
    $form_state->set('fallback_files', $files);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mids = $this->photoIds($form_state);
    $controller = \Drupal::classResolver(AddController::class);
    foreach ($form_state->get('fallback_files') ?? [] as $upload) {
      $media = $controller->saveUpload($upload);
      if (is_string($media)) {
        $this->messenger()->addWarning($this->t('One photo was skipped: @why', ['@why' => $media]));
        continue;
      }
      $mids[] = (int) $media->id();
    }
    $item = Node::load($form_state->get('nid'));
    $before = array_map(fn ($r) => (int) $r->target_id, iterator_to_array($item->get('field_photos')));
    if ($before !== $mids) {
      $item->set('field_photos', array_map(fn ($mid) => ['target_id' => $mid], $mids));
      $item->setNewRevision(TRUE);
      $item->setRevisionLogMessage(sprintf('Photos updated on the Add photos page (%d photos).', count($mids)));
      $item->setRevisionUserId((int) $this->currentUser()->id());
      $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      // Saving pins the files to the item (ItemPhotos::pin, PhotoHooks).
      $item->save();
      $this->messenger()->addStatus($this->formatPlural(count($mids), '@item: 1 photo.', '@item: @count photos.', ['@item' => $item->label()]));
    }
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'next') {
      $next = $this->photos->nextWithoutPhotos((int) $item->id());
      if ($next !== NULL && $next !== (int) $item->id()) {
        $form_state->setRedirect('ishop_items.item_photos', ['node' => $next]);
        return;
      }
      $this->messenger()->addStatus($this->t('Every item has photos now.'));
      $form_state->setRedirect('ishop_matrix.stockroom');
      return;
    }
    $form_state->setRedirectUrl($item->toUrl());
  }

  /**
   * Media IDs from the hidden field, in order, without duplicates.
   */
  private function photoIds(FormStateInterface $form_state): array {
    $ids = array_filter(array_map('intval', explode(',', (string) $form_state->getValue('photo_ids'))));
    return array_values(array_unique($ids));
  }

}
