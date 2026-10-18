<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\BulkActions;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Stale;
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
   * Short labels for the bottom bar's action buttons.
   */
  private const SHORT_ACTIONS = [
    BulkActions::POSTED => 'Mark posted',
    BulkActions::PRICE => 'Price',
    BulkActions::LOCATION => 'Move',
    BulkActions::STATUS => 'Status',
  ];

  /**
   * Status chip look per item status.
   */
  private const STATUS_CHIPS = [
    'listed' => 'rm-chip--live',
    'draft' => 'rm-chip--todo',
    'sold' => 'rm-chip--sold',
  ];

  /**
   * Step 1: the list (whole rows open the item), with select mode.
   *
   * Redesign, punch 622: the list comes first. "Select" (stockroom.js)
   * shows the checkboxes and makes a tap tick the row instead of opening
   * it; the bottom bar with the actions appears once something is ticked.
   * The checkbox sits next to the row link, never inside it. Without
   * JavaScript the checkboxes and the bar are simply always there.
   */
  private function chooseStep(array $form, FormStateInterface $form_state): array {
    $saved = $form_state->get('choice') ?? [];
    $active = StockroomFilters::parse($this->getRequest()->query->all());
    $items = $this->items(NULL, $active);
    $form['#attributes']['class'][] = 'rm-stock--list';

    if (!$items) {
      $form['empty'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="rm-stock__empty"><p><strong>{{ text }}</strong></p><p><a class="rm-button rm-button--gold" href="{{ url }}">{{ link }}</a></p></div>',
        '#context' => $active || $this->total() ? [
          'text' => $this->t('No matches.'),
          'url' => Url::fromRoute('ishop_matrix.stockroom')->toString(),
          'link' => $this->t('Clear filters'),
        ] : [
          'text' => $this->t('No items yet.'),
          'url' => Url::fromRoute('ishop_items.add')->toString(),
          'link' => $this->t('Add item'),
        ],
      ];
      return $form;
    }

    $live = $this->liveListings(array_keys($items));
    $sort = $active['sort'] ?? 'new';
    if ($sort === 'longest') {
      // Earliest posting first; ties (the import posted everything on one
      // day) oldest item first; items not posted anywhere go last.
      $first = fn (int $nid) => $live[$nid] ? (min(array_column($live[$nid], 'posted')) ?: '9999') : '9999';
      uksort($items, fn ($a, $b) => [$first($a), $a] <=> [$first($b), $b]);
    }
    elseif (str_starts_with($sort, 'price_')) {
      // Items with no price go last either way (SQL puts NULL first).
      $priced = array_filter($items, fn ($item) => (string) $item->get('field_asking_price')->value !== '');
      $items = $priced + $items;
    }

    $form['items'] = [
      '#tree' => TRUE,
      '#prefix' => '<ul class="rm-rows" role="list">',
      '#suffix' => '</ul>',
    ];
    $thumb = ImageStyle::load('thumbnail');
    $no_photo = '<span class="rm-row__thumb rm-row__thumb--none"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M9 3 7.2 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.2L15 3H9Zm3 5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Zm0 2a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z"/></svg><span>' . Html::escape((string) $this->t('No photo')) . '</span></span>';
    $chevron = '<svg class="rm-row__chevron" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    foreach ($items as $nid => $item) {
      $photo = $item->get('field_photos')->entity?->get('field_media_image_1')->entity;
      $status = (string) $item->get('field_item_status')->value;
      $price = $item->get('field_asking_price')->value;
      $title = Html::escape($item->label());
      $pills = '';
      foreach (array_unique(array_column($live[$nid], 'platform')) as $platform) {
        $pills .= '<abbr class="rm-row__pill" title="' . Html::escape(Platforms::PLATFORMS[$platform]) . '">' . Html::escape(Platforms::SHORT[$platform]) . '</abbr>';
      }
      $where = $this->bulk->locationPath((int) $item->get('field_storage_location')->target_id);
      $form['items'][$nid] = [
        '#type' => 'checkbox',
        '#default_value' => in_array($nid, $saved['nids'] ?? [], FALSE),
        '#theme_wrappers' => [],
        '#attributes' => [
          'class' => ['rm-row__check'],
          'aria-label' => (string) $this->t('Select @title', ['@title' => $item->label()]),
        ],
        '#prefix' => Markup::create('<li class="rm-row" data-title="' . Html::escape(mb_strtolower($item->label())) . '">'),
        '#suffix' => Markup::create('<a class="rm-row__link" href="' . Html::escape($item->toUrl()->toString()) . '" aria-labelledby="rm-t-' . $nid . '" aria-describedby="rm-m-' . $nid . '">'
          . ($photo ? '<img class="rm-row__thumb" src="' . Html::escape($thumb->buildUrl($photo->getFileUri())) . '" alt="" width="64" height="64" loading="lazy" decoding="async">' : $no_photo)
          . '<span class="rm-row__body"><span class="rm-row__title" id="rm-t-' . $nid . '">' . $title . '</span>'
          . '<span class="rm-row__meta" id="rm-m-' . $nid . '">'
          . '<span class="rm-row__price">' . ($price !== NULL && $price !== '' ? '$' . number_format((float) $price, 2) : Html::escape((string) $this->t('No price'))) . '</span>'
          . ($status !== '' ? ' <span class="rm-chip rm-row__status ' . (self::STATUS_CHIPS[$status] ?? '') . '">' . Html::escape((string) $this->t($item->get('field_item_status')->getSetting('allowed_values')[$status] ?? $status)) . '</span>' : '')
          . ($pills ? ' <span class="rm-row__pills"><span class="visually-hidden">' . Html::escape((string) $this->t('Posted on')) . ' </span>' . $pills . '</span>' : '')
          . ($where !== '' ? ' <span class="rm-row__where">' . Html::escape($where) . '</span>' : '')
          . '</span></span>' . $chevron . '</a></li>'),
      ];
    }
    $form['more'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Show more'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['rm-button', 'rm-button--ghost', 'rm-stock__more'],
        'hidden' => 'hidden',
      ],
    ];
    $form['nomatch'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="rm-stock__empty rm-stock__nomatch" hidden><p><strong>{{ text }}</strong></p><p><button type="button" class="rm-button rm-button--gold rm-stock__clear">{{ clear }}</button></p></div>',
      '#context' => ['text' => $this->t('No matches.'), 'clear' => $this->t('Clear search')],
    ];

    $form['bar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rm-stock__bar']],
    ];
    $form['bar']['top'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rm-stock__bartop']],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => ['class' => ['rm-stock__count'], 'aria-live' => 'polite'],
      ],
      'all' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Select all'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['rm-button', 'rm-button--ghost', 'rm-stock__all'],
          'hidden' => 'hidden',
          'data-none' => (string) $this->t('Select none'),
        ],
      ],
    ];
    $form['bar']['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('What to do'),
      '#title_display' => 'invisible',
      '#options' => array_map(fn ($label) => $this->t($label), self::SHORT_ACTIONS),
      '#default_value' => $saved['action'] ?? NULL,
      '#attributes' => ['class' => ['rm-stock__action']],
    ];
    $visible = fn (string $action) => ['visible' => [':input[name="action"]' => ['value' => $action]]];
    $form['bar']['platform'] = [
      '#type' => 'select',
      '#title' => $this->t('Platform'),
      '#options' => Platforms::PLATFORMS,
      '#default_value' => $saved['platform'] ?? NULL,
      '#states' => $visible(BulkActions::POSTED),
    ];
    $form['bar']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('New price'),
      '#options' => ['set' => $this->t('Set it to a price'), 'minus' => $this->t('Lower it by a percentage')],
      '#default_value' => $saved['mode'] ?? 'minus',
      '#states' => $visible(BulkActions::PRICE),
    ];
    $form['bar']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Price ($) or percentage (%)'),
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $saved['amount'] ?? NULL,
      '#attributes' => ['inputmode' => 'decimal'],
      '#states' => $visible(BulkActions::PRICE),
    ];
    $form['bar']['location'] = [
      '#type' => 'select',
      '#title' => $this->t('Storage location'),
      '#options' => $this->bulk->locationOptions(),
      '#default_value' => $saved['location'] ?? NULL,
      '#states' => $visible(BulkActions::LOCATION),
    ];
    $form['bar']['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#options' => array_map(fn ($label) => $this->t($label), BulkActions::STATUSES),
      '#default_value' => $saved['status'] ?? NULL,
      '#description' => $this->t('Kept and Donated also ask for any live listings to be taken down.'),
      '#states' => $visible(BulkActions::STATUS),
    ];
    $form['bar']['review'] = [
      '#type' => 'submit',
      '#value' => $this->t('Review changes'),
      '#submit' => ['::review'],
      '#validate' => ['::validateChoice'],
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Live listings per item: platform and date posted.
   *
   * @return array<int, array<int, array{platform: string, posted: string}>>
   *   Keyed by node ID (every given ID, maybe empty), in platform order.
   */
  private function liveListings(array $nids): array {
    $live = array_fill_keys($nids, []);
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('item', $nids, 'IN')
      ->condition('status', Stale::LIVE, 'IN')
      ->execute();
    $order = array_flip(array_keys(Platforms::PLATFORMS));
    foreach ($storage->loadMultiple($ids) as $listing) {
      $live[(int) $listing->get('item')->target_id][] = [
        'platform' => (string) $listing->get('platform')->value,
        'posted' => (string) $listing->get('date_posted')->value,
      ];
    }
    foreach ($live as &$rows) {
      usort($rows, fn ($a, $b) => ($order[$a['platform']] ?? 99) <=> ($order[$b['platform']] ?? 99));
    }
    return $live;
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
   * Items the user can see: only these IDs, or the filtered list.
   *
   * The filtered list gets the default statuses and the chosen sort (ties,
   * and everything else, newest first).
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Keyed by node ID.
   */
  private function items(?array $nids = NULL, array $filters = []): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'item');
    if ($nids !== NULL) {
      if (!$nids) {
        return [];
      }
      $query->condition('nid', $nids, 'IN');
    }
    else {
      if (!$this->filters->apply($query, $filters)) {
        return [];
      }
      match ($filters['sort'] ?? 'new') {
        'price_high' => $query->sort('field_asking_price', 'DESC'),
        'price_low' => $query->sort('field_asking_price', 'ASC'),
        // Longest listed is sorted after loading (needs the listings).
        default => NULL,
      };
    }
    return $storage->loadMultiple($query->sort('created', 'DESC')->sort('nid', 'DESC')->execute());
  }

  /**
   * How many items there are in all.
   */
  private function total(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'item')->count()->execute();
  }

}
