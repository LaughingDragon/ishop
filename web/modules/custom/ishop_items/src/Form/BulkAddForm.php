<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\BulkGrouping;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ReadyCheck;
use Drupal\ishop_office\DiskStatus;

/**
 * Bulk photo drop (Phase 7): many photos at once, split into items.
 *
 * Photos upload one by one (the same endpoint as /add); the page groups them
 * by time taken (45-minute gap by default, adjustable), Cody merges or splits
 * groups, then one draft item is created per group. The AI module adds
 * "Write listings for all" (it queues one run per new item).
 */
final class BulkAddForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_bulk_add';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!DiskStatus::photosOnline()) {
      $form['offline'] = ItemHooks::offlineNotice();
      return $form;
    }
    $form['#attributes']['class'][] = 'bulk-form';
    $form['#attached']['library'][] = 'ishop_items/bulk';
    $form['#attached']['drupalSettings']['ishopBulk'] = [
      'uploadUrl' => Url::fromRoute('ishop_items.add_photo')->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
      'gap' => BulkGrouping::DEFAULT_GAP_MINUTES,
    ];
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose all the photos at once. The site sorts them by when they were taken and starts a new item wherever there is a gap of @gap minutes or more. Check the groups, merge or split them, then create the items.', ['@gap' => BulkGrouping::DEFAULT_GAP_MINUTES]) . '</p><noscript><p><strong>' . $this->t('This page needs JavaScript. Use "Add item" for one item at a time.') . '</strong></p></noscript>',
    ];
    $form['ui'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="bulk-ui" hidden>
<p><label class="rm-button rm-button--gold bulk-pick"><input type="file" accept="image/*" multiple class="visually-hidden bulk-input">{{ pick }}</label></p>
<p class="bulk-gap"><label for="bulk-gap">{{ gap }}</label> <input type="number" id="bulk-gap" min="1" max="1440" step="1" inputmode="numeric"> {{ minutes }}</p>
<p class="bulk-status" aria-live="polite"></p>
<ol class="bulk-groups"></ol>
</div>',
      '#context' => [
        'pick' => $this->t('Choose photos'),
        'gap' => $this->t('New item after a gap of'),
        'minutes' => $this->t('minutes'),
      ],
    ];
    $form['groups'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['class' => ['bulk-groups-value']],
    ];
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['bulk-actions']],
      '#weight' => 100,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create the items'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['bulk-create'], 'disabled' => 'disabled'],
    ];
    return $form;
  }

  /**
   * The submitted groups, checked: photos must be Cody's own, unused, once.
   *
   * @return array<int, int[]>|string
   *   Groups of media IDs, or why they can't be used.
   */
  private function groups(FormStateInterface $form_state): array|string {
    $groups = json_decode((string) $form_state->getValue('groups'), TRUE);
    if (!is_array($groups) || !$groups) {
      return (string) $this->t('No photos were uploaded yet.');
    }
    $all = [];
    foreach ($groups as $i => $group) {
      if (!is_array($group) || !$group) {
        return (string) $this->t('An empty group was sent. Reload the page and try again.');
      }
      $groups[$i] = array_map('intval', $group);
      $all = array_merge($all, $groups[$i]);
    }
    if (count($all) !== count(array_unique($all))) {
      return (string) $this->t('A photo appears twice. Reload the page and try again.');
    }
    $media = $this->entityTypeManager->getStorage('media')->loadMultiple($all);
    foreach ($all as $mid) {
      $m = $media[$mid] ?? NULL;
      if (!$m || $m->bundle() !== 'item_photo' || ((int) $m->getOwnerId() !== (int) $this->currentUser()->id() && !$this->currentUser()->hasPermission('administer media'))) {
        return (string) $this->t('One of the photos could not be found. Reload the page and try again.');
      }
    }
    $used = $this->database->select('node__field_photos', 'p')->fields('p', ['field_photos_target_id'])
      ->condition('field_photos_target_id', $all, 'IN')->execute()->fetchCol();
    if ($used) {
      return (string) $this->t('Some photos already belong to an item. Reload the page and try again.');
    }
    return array_values($groups);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $groups = $this->groups($form_state);
    if (is_string($groups)) {
      $form_state->setErrorByName('groups', $groups);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $groups = $this->groups($form_state);
    $storage = $this->entityTypeManager->getStorage('node');
    $media = $this->entityTypeManager->getStorage('media');
    $created = [];
    foreach ($groups as $group) {
      $first = $media->load($group[0])?->get('field_taken_at')->value;
      $when = $first ? strtotime($first . ' UTC') : \Drupal::time()->getRequestTime();
      $item = $storage->create([
        'type' => 'item',
        'title' => ReadyCheck::DEFAULT_TITLE_PREFIX . $this->dateFormatter->format($when, 'custom', 'Y-m-d H:i'),
        'uid' => $this->currentUser()->id(),
        'status' => 1,
        'field_photos' => array_map(fn ($mid) => ['target_id' => $mid], $group),
        'field_item_status' => 'draft',
      ]);
      $item->setRevisionLogMessage(sprintf('Added with the bulk photo drop (%d photos).', count($group)));
      $item->save();
      $created[] = (int) $item->id();
    }
    $form_state->set('created_nids', $created);
    $this->messenger()->addStatus($this->formatPlural(count($created), 'Created 1 item. It is in the Stockroom as a draft.', 'Created @count items. They are in the Stockroom as drafts.'));
    $form_state->setRedirect('ishop_matrix.stockroom', [], ['query' => ['status' => 'draft']]);
  }

}
