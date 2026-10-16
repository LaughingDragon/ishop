<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\BulkActions;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\StockroomFilters;

/**
 * The Stockroom: every item, with bulk actions (Phase 3).
 *
 * Three steps on one page: choose items and an action, confirm the planned
 * changes (with anything skipped and why), then see what changed.
 */
final class StockroomForm extends FormBase {

  /**
   * Services are protected, not private readonly.
   *
   * Multi-step forms are serialized between steps, and Drupal can't restore
   * private readonly properties afterwards.
   */
  public function __construct(
    protected BulkActions $bulk,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StockroomFilters $filters,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_stockroom';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-stock';
    $form['#attached']['library'][] = 'ishop_items/stockroom';
    $form['#cache']['max-age'] = 0;
    return match ($form_state->get('step') ?? 'choose') {
      'confirm' => $this->confirmStep($form, $form_state),
      'done' => $this->doneStep($form, $form_state),
      default => $this->chooseStep($form, $form_state),
    };
  }

  /**
   * Step 1: what to do, and to which items.
   */
  private function chooseStep(array $form, FormStateInterface $form_state): array {
    $saved = $form_state->get('choice') ?? [];
    $active = StockroomFilters::parse($this->getRequest()->query->all());
    $items = $this->items(NULL, $active);

    $form['what'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('1. What to do'),
      '#attributes' => ['class' => ['rm-card', 'rm-stock__what']],
    ];
    $form['what']['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Action'),
      '#title_display' => 'invisible',
      '#options' => array_map(fn ($label) => $this->t($label), BulkActions::ACTIONS),
      '#default_value' => $saved['action'] ?? NULL,
    ];
    $visible = fn (string $action) => ['visible' => [':input[name="action"]' => ['value' => $action]]];
    $form['what']['platform'] = [
      '#type' => 'select',
      '#title' => $this->t('Platform'),
      '#options' => Platforms::PLATFORMS,
      '#default_value' => $saved['platform'] ?? NULL,
      '#states' => $visible(BulkActions::POSTED),
    ];
    $form['what']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('New price'),
      '#options' => ['set' => $this->t('Set it to a price'), 'minus' => $this->t('Lower it by a percentage')],
      '#default_value' => $saved['mode'] ?? 'minus',
      '#states' => $visible(BulkActions::PRICE),
    ];
    $form['what']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Price ($) or percentage (%)'),
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $saved['amount'] ?? NULL,
      '#attributes' => ['inputmode' => 'decimal'],
      '#states' => $visible(BulkActions::PRICE),
    ];
    $form['what']['location'] = [
      '#type' => 'select',
      '#title' => $this->t('Storage location'),
      '#options' => $this->bulk->locationOptions(),
      '#default_value' => $saved['location'] ?? NULL,
      '#states' => $visible(BulkActions::LOCATION),
    ];
    $form['what']['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#options' => array_map(fn ($label) => $this->t($label), BulkActions::STATUSES),
      '#default_value' => $saved['status'] ?? NULL,
      '#description' => $this->t('Kept and Donated also ask for any live listings to be taken down.'),
      '#states' => $visible(BulkActions::STATUS),
    ];

    $form['which'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('2. Which items'),
      '#attributes' => ['class' => ['rm-stock__which']],
    ];
    if ($active) {
      $form['which']['showing'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Showing @shown of @total items (filtered).', [
          '@shown' => count($items),
          '@total' => $this->total(),
        ]),
        '#attributes' => ['class' => ['rm-stock__showing']],
      ];
    }
    if (!$items) {
      $form['which']['empty'] = ['#markup' => '<p>' . ($active ? $this->t('No items match these filters.') : $this->t('No items yet.')) . '</p>'];
      return $form;
    }
    $form['which']['select_all'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Select all'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['rm-button', 'rm-button--ghost', 'rm-stock__all'],
        'hidden' => 'hidden',
        'data-none' => (string) $this->t('Select none'),
      ],
    ];
    $form['which']['items'] = ['#tree' => TRUE];
    $thumb = ImageStyle::load('thumbnail');
    foreach ($items as $nid => $item) {
      $photo = $item->get('field_photos')->entity?->get('field_media_image_1')->entity;
      $price = $item->get('field_asking_price')->value;
      $meta = array_filter([
        $item->get('field_item_status')->getSetting('allowed_values')[$item->get('field_item_status')->value] ?? '',
        $price !== NULL && $price !== '' ? '$' . number_format((float) $price, 2) : (string) $this->t('no price'),
        $this->bulk->locationPath((int) $item->get('field_storage_location')->target_id),
      ]);
      $form['which']['items'][$nid] = [
        '#type' => 'checkbox',
        '#title' => Markup::create(
          ($photo ? '<img src="' . Html::escape($thumb->buildUrl($photo->getFileUri())) . '" alt="" width="56" height="56" loading="lazy">' : '<span class="rm-stock__nophoto" aria-hidden="true"></span>')
          . '<span class="rm-stock__text"><span class="rm-stock__title">' . Html::escape($item->label()) . '</span>'
          . '<span class="rm-stock__meta">' . Html::escape(implode(' · ', $meta)) . '</span></span>'
        ),
        '#default_value' => in_array($nid, $saved['nids'] ?? [], FALSE),
        '#wrapper_attributes' => ['class' => ['rm-stock__item']],
        // Tapping the row ticks it for a bulk change; Open goes to the item
        // (its page has Check sold prices, Listings and Post this item).
        '#field_suffix' => Markup::create('<a class="rm-button rm-button--ghost rm-stock__open" href="' . Html::escape($item->toUrl()->toString()) . '">' . Html::escape((string) $this->t('Open')) . '<span class="visually-hidden"> ' . Html::escape($item->label()) . '</span></a>'),
      ];
    }
    $form['bar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rm-stock__bar']],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => ['class' => ['rm-stock__count'], 'aria-live' => 'polite'],
      ],
      'review' => [
        '#type' => 'submit',
        '#value' => $this->t('Review changes'),
        '#submit' => ['::review'],
        '#validate' => ['::validateChoice'],
        '#button_type' => 'primary',
      ],
    ];
    return $form;
  }

  /**
   * Step 2: the planned changes, to confirm.
   */
  private function confirmStep(array $form, FormStateInterface $form_state): array {
    $choice = $form_state->get('choice');
    $plan = $this->bulk->plan($this->items($choice['nids']), $choice['action'], $choice);
    $changes = array_filter($plan, fn ($row) => $row['skip'] === NULL);
    $skipped = array_filter($plan, fn ($row) => $row['skip'] !== NULL);
    $form['summary'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="rm-card rm-stock__confirm"><h2>{{ title }}</h2><p><strong>{{ what }}</strong></p>
{% if changes %}<ul class="rm-stock__changes">{% for r in changes %}<li><span class="rm-stock__title">{{ r.item.label }}</span> <span>{{ r.before }} &rarr; <strong>{{ r.after }}</strong></span></li>{% endfor %}</ul>{% else %}<p>{{ none }}</p>{% endif %}
{% if skipped %}<h3>{{ skipped_title }}</h3><ul>{% for r in skipped %}<li>{{ r.item.label }}: {{ r.skip }}</li>{% endfor %}</ul>{% endif %}</div>',
      '#context' => [
        'title' => $this->t('Check before applying'),
        'what' => $this->describe($choice),
        'changes' => array_values($changes),
        'skipped' => array_values($skipped),
        'none' => $this->t('Nothing would change.'),
        'skipped_title' => $this->t('Left as they are'),
      ],
    ];
    $form['bar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rm-stock__bar']],
      'back' => [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::back'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['rm-button--ghost']],
      ],
    ];
    if ($changes) {
      $form['bar']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural(count($changes), 'Apply 1 change', 'Apply @count changes'),
        '#button_type' => 'primary',
      ];
    }
    return $form;
  }

  /**
   * Step 3: what changed.
   */
  private function doneStep(array $form, FormStateInterface $form_state): array {
    $done = $form_state->get('done') ?? [];
    $form['result'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="rm-card rm-stock__confirm"><h2>{{ title }}</h2><p><strong>{{ what }}</strong></p>{% if done %}<ul class="rm-stock__changes">{% for r in done %}<li><a href="{{ r.url }}">{{ r.title }}</a> <span>{{ r.before }} &rarr; <strong>{{ r.after }}</strong></span></li>{% endfor %}</ul>{% else %}<p>{{ none }}</p>{% endif %}<p><a class="rm-button rm-button--gold" href="{{ back }}">{{ back_label }}</a></p></div>',
      '#context' => [
        'title' => $this->t('What changed'),
        'what' => $this->describe($form_state->get('choice')),
        'done' => $done,
        'none' => $this->t('Nothing was changed.'),
        'back' => $this->getRequest()->getPathInfo(),
        'back_label' => $this->t('Back to the Stockroom'),
      ],
    ];
    return $form;
  }

  /**
   * Validates step 1.
   */
  public function validateChoice(array &$form, FormStateInterface $form_state): void {
    $nids = array_keys(array_filter($form_state->getValue('items') ?? []));
    if (!$nids) {
      $form_state->setErrorByName('items', $this->t('Tick at least one item.'));
    }
    $action = $form_state->getValue('action');
    if (!isset(BulkActions::ACTIONS[$action])) {
      $form_state->setErrorByName('action', $this->t('Choose what to do.'));
      return;
    }
    $amount = (float) $form_state->getValue('amount');
    switch ($action) {
      case BulkActions::POSTED:
        if (!isset(Platforms::PLATFORMS[$form_state->getValue('platform')])) {
          $form_state->setErrorByName('platform', $this->t('Choose a platform.'));
        }
        break;

      case BulkActions::PRICE:
        if ($form_state->getValue('mode') === 'minus' && ($amount <= 0 || $amount >= 100)) {
          $form_state->setErrorByName('amount', $this->t('Enter a percentage between 1 and 99.'));
        }
        elseif ($form_state->getValue('mode') === 'set' && $amount <= 0) {
          $form_state->setErrorByName('amount', $this->t('Enter the new price.'));
        }
        break;

      case BulkActions::LOCATION:
        if (!$form_state->getValue('location')) {
          $form_state->setErrorByName('location', $this->t('Choose a location.'));
        }
        break;

      case BulkActions::STATUS:
        if (!isset(BulkActions::STATUSES[$form_state->getValue('status')])) {
          $form_state->setErrorByName('status', $this->t('Choose a status.'));
        }
        break;
    }
  }

  /**
   * Step 1 to 2.
   */
  public function review(array &$form, FormStateInterface $form_state): void {
    $form_state->set('choice', [
      'nids' => array_map('intval', array_keys(array_filter($form_state->getValue('items') ?? []))),
      'action' => $form_state->getValue('action'),
      'platform' => $form_state->getValue('platform'),
      'mode' => $form_state->getValue('mode'),
      'amount' => (float) $form_state->getValue('amount'),
      'location' => (int) $form_state->getValue('location'),
      'status' => $form_state->getValue('status'),
    ]);
    $form_state->set('step', 'confirm');
    $form_state->setRebuild();
  }

  /**
   * Step 2 back to 1, choices kept.
   */
  public function back(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'choose');
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   *
   * Applies the confirmed action (step 2 to 3).
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $choice = $form_state->get('choice');
    $done = $this->bulk->apply($this->items($choice['nids']), $choice['action'], $choice);
    $form_state->set('done', array_map(fn ($row) => [
      'title' => $row['item']->label(),
      'url' => $row['item']->toUrl()->toString(),
      'before' => $row['before'],
      'after' => $row['after'],
    ], $done));
    $this->messenger()->addStatus($this->formatPlural(count($done), '1 item changed.', '@count items changed.'));
    $form_state->set('step', 'done');
    $form_state->setRebuild();
  }

  /**
   * The chosen action in words.
   */
  private function describe(array $choice): TranslatableMarkup {
    return match ($choice['action']) {
      BulkActions::POSTED => $this->t('Mark posted on @platform', ['@platform' => Platforms::PLATFORMS[$choice['platform']]]),
      BulkActions::PRICE => $choice['mode'] === 'set'
        ? $this->t('Set the asking price to $@amount', ['@amount' => number_format($choice['amount'], 2)])
        : $this->t('Lower the asking price by @amount%', ['@amount' => rtrim(rtrim(number_format($choice['amount'], 2), '0'), '.')]),
      BulkActions::LOCATION => $this->t('Move to @location', ['@location' => $this->bulk->locationPath($choice['location'])]),
      BulkActions::STATUS => $this->t('Set the status to @status', ['@status' => BulkActions::STATUSES[$choice['status']]]),
    };
  }

  /**
   * Items the user can see, newest first (optionally only these IDs).
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Keyed by node ID.
   */
  private function items(?array $nids = NULL, array $filters = []): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'item')->sort('created', 'DESC')->sort('nid', 'DESC');
    if ($nids !== NULL) {
      if (!$nids) {
        return [];
      }
      $query->condition('nid', $nids, 'IN');
    }
    if ($filters && !$this->filters->apply($query, $filters)) {
      return [];
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * How many items there are in all.
   */
  private function total(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'item')->count()->execute();
  }

}
