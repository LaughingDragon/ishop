<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_items\Eligibility;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Stale;
use Drupal\node\NodeInterface;

/**
 * The listings grid on an item page: one row per platform.
 *
 * Marking a listing "Sold here" first shows what the cascade will change on
 * the other platforms and asks for confirmation. Un-marking a sale restores
 * what the cascade changed. With a quantity above 1, there is no cascade.
 */
final class ListingsGridForm extends FormBase {

  /**
   * Editable money columns: field => header.
   */
  private const MONEY = ['listed_price', 'sold_price', 'fees', 'shipping_paid'];

  /**
   * Column headers for the money fields.
   */
  private function moneyHeaders(): array {
    return [
      'listed_price' => $this->t('Listed'),
      'sold_price' => $this->t('Sold for'),
      'fees' => $this->t('Fees'),
      'shipping_paid' => $this->t('Shipping paid'),
    ];
  }

  public function __construct(
    private readonly ListingManager $listings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_listings_grid';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $item = NULL): array {
    $item ??= $form_state->get('item');
    $form_state->set('item', $item);
    $form['#attributes']['class'][] = 'ishop-listings';

    if ($pending = $form_state->get('pending_sale')) {
      return $this->confirmStep($form, $form_state, $pending);
    }

    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Listings')];
    $form['rows'] = [
      '#type' => 'table',
      '#header' => array_merge([$this->t('Platform'), $this->t('Status'), $this->t('Listing URL')], array_values($this->moneyHeaders())),
      '#empty' => $this->t('No listings yet.'),
      '#attributes' => ['class' => ['ishop-listings__table']],
    ];
    $facts = self::eligibilityFacts($item);
    foreach ($this->listings->forItem($item) as $platform => $listing) {
      $name = Platforms::PLATFORMS[$platform];
      $eligibility = Eligibility::evaluate($facts + [
        'override' => $listing->get('eligibility_override')->value,
        'override_note' => $listing->get('eligibility_note')->value,
      ], $platform);
      $row = [];
      $row['platform'] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ name }}</strong> <details class="ishop-chip ishop-chip--{{ e.verdict }}"><summary>{{ e.label }}{% if e.overridden %} ({{ by_hand }}){% endif %}</summary><p>{{ e.reason }}</p></details>{% if stale %}<p class="ishop-stale">{{ stale }}</p>{% endif %}',
        '#context' => [
          'name' => $name,
          'e' => $eligibility,
          'by_hand' => $this->t('set by hand'),
          'stale' => ($nudge = $this->listings->nudge($listing)) ? Stale::line($nudge) : NULL,
        ],
        '#wrapper_attributes' => ['data-label' => (string) $this->t('Platform')],
      ];
      $row['status'] = [
        '#type' => 'select',
        '#title' => $this->t('@platform status', ['@platform' => $name]),
        '#title_display' => 'invisible',
        '#options' => Platforms::STATUSES,
        '#default_value' => $listing->getStatus(),
        '#wrapper_attributes' => ['data-label' => (string) $this->t('Status')],
        '#attributes' => ['class' => ['ishop-listings__status', 'ishop-status--' . $listing->getStatus()]],
      ];
      $row['url'] = [
        '#type' => 'url',
        '#title' => $this->t('@platform listing URL', ['@platform' => $name]),
        '#title_display' => 'invisible',
        '#default_value' => (string) $listing->get('url')->value,
        '#size' => 28,
        '#wrapper_attributes' => ['data-label' => (string) $this->t('Listing URL')],
      ];
      foreach ($this->moneyHeaders() as $field => $header) {
        $row[$field] = [
          '#type' => 'number',
          '#title' => $this->t('@platform @what', ['@platform' => $name, '@what' => mb_strtolower((string) $header)]),
          '#title_display' => 'invisible',
          '#min' => 0,
          '#step' => 0.01,
          '#field_prefix' => '$',
          '#size' => 7,
          '#default_value' => $listing->get($field)->value,
          '#wrapper_attributes' => ['data-label' => (string) $header],
        ];
      }
      $form['rows'][$platform] = $row;
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save listings'),
      '#button_type' => 'primary',
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * What the eligibility rules look at: category path, title, description.
   */
  public static function eligibilityFacts(NodeInterface $item): array {
    $path = '';
    if ($term = $item->get('field_category')->entity) {
      $parents = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadAllParents($term->id());
      $path = implode(' > ', array_map(fn ($t) => $t->label(), array_reverse($parents)));
    }
    return [
      'category' => $path,
      'title' => (string) $item->label(),
      'description' => (string) ($item->get('field_description')->value ?? ''),
    ];
  }

  /**
   * The confirmation step for a sale.
   */
  private function confirmStep(array $form, FormStateInterface $form_state, array $pending): array {
    $item = $form_state->get('item');
    $listings = $this->listings->forItem($item);
    $sold = $listings[$pending['platform']];
    $quantity = (int) ($item->get('field_quantity')->value ?? 1);
    $lines = [];
    if ($quantity > 1) {
      $note = $this->t('Quantity is @n, so the other listings stay as they are. Lower the quantity as they sell; when the last one sells, set quantity to 1 first to take the others down.', ['@n' => $quantity]);
    }
    else {
      foreach ($this->listings->planSale($sold) as $platform => $change) {
        $lines[] = $this->t('@platform: @from → @to', [
          '@platform' => Platforms::PLATFORMS[$platform],
          '@from' => Platforms::STATUSES[$change['from']],
          '@to' => Platforms::STATUSES[$change['to']],
        ]);
      }
      $note = $lines ? $this->t('This also changes the other listings:') : $this->t('No other listings need to change.');
    }
    $form['confirm'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout so-callout--warn" aria-labelledby="ishop-sale-title"><div class="so-callout__body"><h2 id="ishop-sale-title">{{ title }}</h2><p>{{ note }}</p>{% if lines %}<ul>{% for l in lines %}<li>{{ l }}</li>{% endfor %}</ul>{% endif %}<p>{{ undo }}</p></div></section>',
      '#context' => [
        'title' => $this->t('Mark sold on @platform?', ['@platform' => Platforms::PLATFORMS[$pending['platform']]]),
        'note' => $note,
        'lines' => $lines,
        'undo' => $this->t('The item becomes Sold. You can undo this later by changing the status back; the other listings are put back as they were.'),
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Yes, mark sold'),
      '#button_type' => 'primary',
      '#submit' => ['::confirmSale'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelSale'],
      '#limit_validation_errors' => [],
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('pending_sale') || !$form_state->getValue('rows')) {
      return;
    }
    $listings = $this->listings->forItem($form_state->get('item'));
    $new_sales = [];
    $already_sold = [];
    foreach ($form_state->getValue('rows') as $platform => $row) {
      $was = $listings[$platform]->getStatus();
      if ($row['status'] === Platforms::SOLD_HERE && $was !== Platforms::SOLD_HERE) {
        $new_sales[] = $platform;
      }
      if ($was === Platforms::SOLD_HERE && $row['status'] === Platforms::SOLD_HERE) {
        $already_sold[] = $platform;
      }
    }
    if (count($new_sales) > 1) {
      $form_state->setErrorByName('rows', $this->t('Mark one sale at a time.'));
    }
    $quantity = (int) ($form_state->get('item')->get('field_quantity')->value ?? 1);
    if ($new_sales && $already_sold && $quantity <= 1) {
      $form_state->setErrorByName('rows][' . $new_sales[0] . '][status', $this->t('This item already sold on @platform. Change that first, or raise the quantity.', [
        '@platform' => Platforms::PLATFORMS[$already_sold[0]],
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->get('item');
    $listings = $this->listings->forItem($item);
    $rows = $form_state->getValue('rows') ?? [];
    $sale = NULL;
    $changed = 0;
    $restored = [];
    // Statuses as they were when the form was sent. Un-marking a sale changes
    // other listings mid-loop; those rows must not be written back from the
    // (now stale) submitted values unless the user changed them.
    $original = array_map(fn (PlatformListing $l) => $l->getStatus(), $listings);
    foreach ($rows as $platform => $row) {
      $listing = $listings[$platform];
      $was = $original[$platform];
      $dirty = $this->copyFields($listing, $row);
      if ($row['status'] === Platforms::SOLD_HERE && $was !== Platforms::SOLD_HERE) {
        // Save the other edits now; the sale waits for confirmation.
        $sale = $platform;
      }
      elseif ($was === Platforms::SOLD_HERE && $row['status'] !== Platforms::SOLD_HERE) {
        $restored += $this->listings->undoSale($listing, $row['status'], 'Sale un-marked in the listings grid.');
        $changed++;
        continue;
      }
      elseif ($row['status'] !== $was) {
        // Changed by the user in this form: their choice wins, even over a
        // listing the un-marked sale just restored.
        $listing->set('status', $row['status']);
        $dirty = TRUE;
      }
      if ($dirty) {
        $this->listings->saveRevision($listing, 'Edited in the listings grid.');
        $changed++;
      }
    }
    if ($restored) {
      $this->messenger()->addStatus($this->t('Sale un-marked. Put back: @list.', [
        '@list' => implode(', ', array_map(fn ($p, $s) => Platforms::PLATFORMS[$p] . ' → ' . Platforms::STATUSES[$s], array_keys($restored), $restored)),
      ]));
    }
    if ($sale) {
      $form_state->set('pending_sale', ['platform' => $sale]);
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($changed ? $this->t('Listings saved.') : $this->t('No changes.'));
  }

  /**
   * Applies a confirmed sale.
   */
  public function confirmSale(array &$form, FormStateInterface $form_state): void {
    $item = $form_state->get('item');
    $pending = $form_state->get('pending_sale');
    $listing = $this->listings->forItem($item)[$pending['platform']];
    $quantity = (int) ($item->get('field_quantity')->value ?? 1);
    if ($quantity > 1) {
      $listing->set('status', Platforms::SOLD_HERE);
      $this->listings->saveRevision($listing, sprintf('Sold one (quantity %d); no cascade.', $quantity));
      $this->messenger()->addStatus($this->t('Marked sold on @platform. Quantity is @n, so the other listings were left as they are.', [
        '@platform' => Platforms::PLATFORMS[$pending['platform']],
        '@n' => $quantity,
      ]));
    }
    else {
      $changes = $this->listings->planSale($listing);
      $this->listings->applySale($listing, 'Marked "Sold here" in the listings grid (confirmed).');
      $this->messenger()->addStatus($this->t('Marked sold on @platform. @count other listings updated; the item is now Sold.', [
        '@platform' => Platforms::PLATFORMS[$pending['platform']],
        '@count' => count($changes),
      ]));
    }
    $form_state->set('pending_sale', NULL);
    $form_state->setRebuild(FALSE);
  }

  /**
   * Cancels a sale; other edits already saved stay.
   */
  public function cancelSale(array &$form, FormStateInterface $form_state): void {
    $form_state->set('pending_sale', NULL);
    $this->messenger()->addStatus($this->t('Not marked sold. Any other changes were saved.'));
  }

  /**
   * Copies URL and money fields from a row; returns whether anything changed.
   */
  private function copyFields(PlatformListing $listing, array $row): bool {
    $dirty = FALSE;
    $url = trim((string) ($row['url'] ?? ''));
    if ($url !== (string) $listing->get('url')->value) {
      $listing->set('url', $url === '' ? NULL : $url);
      $dirty = TRUE;
    }
    foreach (self::MONEY as $field) {
      $new = ($row[$field] ?? '') === '' ? NULL : number_format((float) $row[$field], 2, '.', '');
      $old = $listing->get($field)->value;
      $old = $old === NULL ? NULL : number_format((float) $old, 2, '.', '');
      if ($new !== $old) {
        $listing->set($field, $new);
        $dirty = TRUE;
      }
    }
    return $dirty;
  }

}
