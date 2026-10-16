<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ishop_items\Controller\AddController;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\ReadyCheck;
use Drupal\ishop_office\DiskStatus;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\UserDataInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The "Add item" form on the phone: photos first, everything else optional.
 *
 * With JavaScript, each photo uploads as soon as it is taken or picked
 * (/add/photo), with "Take another" / "Done" after a camera shot, and
 * "Make cover", up/down and remove on each photo. Without it, the photos
 * picked in the plain file field upload when the form is saved.
 *
 * "Already selling it?" mode (/add?listed=1, Phase 2): paste the listing
 * links, tick where it's posted, type title and price, save; photos are
 * optional. The ticked and linked platforms become Posted.
 */
final class AddItemForm extends FormBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ListingManager $listings,
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * Whether the form is in "already selling it" mode.
   */
  private function listedMode(FormStateInterface $form_state): bool {
    if ($form_state->has('listed_mode')) {
      return (bool) $form_state->get('listed_mode');
    }
    $mode = (bool) $this->getRequest()->query->get('listed');
    $form_state->set('listed_mode', $mode);
    return $mode;
  }

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
    $listed = $this->listedMode($form_state);

    $form['mode'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="ia-mode">{% if listed %}{{ listed_intro }} <a href="{{ new_url }}">{{ new_label }}</a>{% else %}<a href="{{ listed_url }}">{{ listed_label }}</a>{% endif %}</p>',
      '#context' => [
        'listed' => $listed,
        'listed_intro' => $this->t('Already selling it: paste the listing links, check where it is posted, type the title and price, and save. Photos are optional.'),
        'new_url' => Url::fromRoute('ishop_items.add')->toString(),
        'new_label' => $this->t('Adding a new item instead?'),
        'listed_url' => Url::fromRoute('ishop_items.add', [], ['query' => ['listed' => 1]])->toString(),
        'listed_label' => $this->t('Already selling it somewhere? Add it in under a minute'),
      ],
      '#weight' => -100,
    ];

    if ($listed) {
      $remembered = $this->userData->get('ishop_items', (int) $this->currentUser()->id(), 'posted_on');
      $form['listed'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ia-listed']],
        '#weight' => -50,
        'links' => [
          '#type' => 'textarea',
          '#title' => $this->t('Listing links'),
          '#rows' => 3,
          '#description' => $this->t('Paste the links from Poshmark, Mercari, eBay, Depop, Grailed, Vinted or Facebook, one per line. Share links work too.'),
          '#attributes' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url'],
        ],
        'posted_on' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Posted on'),
          '#options' => Platforms::PLATFORMS,
          '#default_value' => is_array($remembered) ? $remembered : array_keys(Platforms::PLATFORMS),
          '#description' => $this->t("Uncheck where it isn't posted. A pasted link checks its platform for you."),
        ],
      ];
    }

    if (!$online) {
      $form['offline'] = ItemHooks::offlineNotice();
      if (!$listed) {
        return $form;
      }
    }

    $form['#attached']['library'][] = 'ishop_items/add';
    if (!$online) {
      // "Already selling it" mode works without photo storage.
      return $this->detailsAndActions($form, $listed);
    }
    $form['#attached']['drupalSettings']['ishopAdd'] = [
      'uploadUrl' => Url::fromRoute('ishop_items.add_photo')->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
    ];

    $form['photos'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ia-photos']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Photos')],
      'bulk' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ia-bulk-link']],
        'link' => Link::createFromRoute($this->t('Many items at once? Use the bulk photo drop'), 'ishop_items.bulk_add')->toRenderable(),
      ],
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

    if ($listed) {
      $form['photos']['heading']['#value'] = $this->t('Photos (optional)');
    }
    return $this->detailsAndActions($form, $listed);
  }

  /**
   * The details section (title, price, condition, category) and Save.
   */
  private function detailsAndActions(array $form, bool $listed): array {
    $form['details'] = [
      '#type' => 'details',
      '#title' => $listed ? $this->t('Details') : $this->t('Details (optional, add them later if you like)'),
      '#open' => $listed,
      '#weight' => $listed ? -40 : 0,
      '#attributes' => ['class' => ['ia-details']],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#required' => $listed,
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

    $form['actions'] = ['#type' => 'actions', '#weight' => 100];
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
    $listed = $this->listedMode($form_state);
    if ($listed) {
      $parsed = Platforms::parseLinks((string) $form_state->getValue('links'));
      if ($parsed['unknown']) {
        $form_state->setErrorByName('links', $this->t("These aren't listing links from Poshmark, Mercari, eBay, Depop, Grailed, Vinted or Facebook: @list", ['@list' => implode(' ', $parsed['unknown'])]));
      }
      if ($parsed['duplicates']) {
        $form_state->setErrorByName('links', $this->t('Paste one link per platform. More than one for: @list', [
          '@list' => implode(', ', array_map(fn ($p) => Platforms::PLATFORMS[$p], $parsed['duplicates'])),
        ]));
      }
      $ticked = array_keys(array_filter((array) $form_state->getValue('posted_on')));
      if (!$ticked && !$parsed['links']) {
        $form_state->setErrorByName('posted_on', $this->t('Check where it is posted, or paste a listing link.'));
      }
      $form_state->set('listing_links', $parsed['links']);
    }
    if (!DiskStatus::photosOnline()) {
      if ($listed) {
        return;
      }
      $form_state->setErrorByName('', $this->t("Photo storage is offline, so items with photos can't be added right now."));
      return;
    }
    $mids = $this->photoIds($form_state);
    foreach (Media::loadMultiple($mids) as $media) {
      if ($media->bundle() !== 'item_photo' || !$media->access('update')) {
        $form_state->setErrorByName('photo_ids', $this->t("One of the photos can't be used. Remove it and add it again."));
        return;
      }
    }
    $files = $this->getRequest()->files->get('files', [])['fallback'] ?? [];
    $files = array_filter(is_array($files) ? $files : [$files], fn ($f) => $f instanceof UploadedFile);
    if (!$mids && !$files && !$listed) {
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
    $listed = $this->listedMode($form_state);
    if (!$mids && !$listed) {
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
      'field_item_status' => $listed ? 'listed' : 'draft',
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
    $item->setRevisionLogMessage($listed ? 'Added as already selling (/add?listed=1).' : 'Added on the phone (/add).');
    $item->save();
    if ($listed) {
      $posted = $this->markPosted($item, $form_state, $price);
      $this->messenger()->addStatus($this->t('Saved as posted on @list.', ['@list' => implode(', ', $posted)]));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved with @n photo(s). Below is what it still needs before listing.', ['@n' => count($mids)]));
    }
    $form_state->setRedirectUrl($item->toUrl());
  }

  /**
   * Marks the checked and linked platforms Posted; remembers the checks.
   *
   * @return string[]
   *   Names of the platforms marked Posted.
   */
  private function markPosted(Node $item, FormStateInterface $form_state, mixed $price): array {
    $links = $form_state->get('listing_links') ?? [];
    $ticked = array_keys(array_filter((array) $form_state->getValue('posted_on')));
    $this->userData->set('ishop_items', (int) $this->currentUser()->id(), 'posted_on', $ticked);
    $platforms = array_unique(array_merge($ticked, array_keys($links)));
    $today = $this->dateFormatter->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d');
    $names = [];
    foreach ($this->listings->forItem($item) as $platform => $listing) {
      if (!in_array($platform, $platforms, TRUE)) {
        continue;
      }
      $listing->set('status', Platforms::POSTED);
      $listing->set('date_posted', $today);
      if (isset($links[$platform])) {
        $listing->set('url', $links[$platform]);
      }
      if ($price !== '' && $price !== NULL) {
        $listing->set('listed_price', $price);
      }
      $this->listings->saveRevision($listing, 'Posted (added as already selling).');
      $names[] = Platforms::PLATFORMS[$platform];
    }
    return $names;
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
    // Child terms (e.g. Holiday & Seasonal under Home & Kitchen) are
    // indented with "-", as on the item edit form.
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid) as $term) {
      $options[$term->tid] = str_repeat('-', (int) $term->depth) . $term->name;
    }
    return $options;
  }

}
