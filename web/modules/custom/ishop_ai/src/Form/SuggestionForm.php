<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_ai\AiRuns;
use Drupal\ishop_ai\ListingWriter;
use Drupal\ishop_items\PlatformCategories;
use Drupal\ishop_items\ReadyCheck;
use Drupal\node\NodeInterface;

/**
 * The AI suggestion on an item page: Cody ticks what to use (Phase 7).
 *
 * Each suggested value sits beside the item's current one. Only ticked rows
 * are written, as a new revision; "Dismiss" changes nothing.
 */
final class SuggestionForm extends FormBase {

  /**
   * Suggested fields, in the order shown: key => label.
   */
  public const FIELDS = [
    'title' => 'Title',
    'description' => 'Description',
    'condition_notes' => 'Flaws',
    'brand' => 'Brand',
    'size' => 'Size',
    'color' => 'Color',
    'condition' => 'Condition',
    'category' => 'Category',
    'price' => 'Asking price',
  ];

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected AiRuns $runs,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_ai_suggestion';
  }

  /**
   * The current value of a suggested field, as text.
   */
  public static function current(NodeInterface $item, string $field): string {
    return match ($field) {
      'title' => (string) $item->label(),
      'description' => (string) $item->get('field_description')->value,
      'condition_notes' => (string) $item->get('field_flaws')->value,
      'brand' => (string) $item->get('field_brand')->entity?->label(),
      'size' => (string) $item->get('field_size')->value,
      'color' => (string) $item->get('field_color')->value,
      'condition' => (string) $item->get('field_condition')->entity?->label(),
      'category' => (string) $item->get('field_category')->entity?->label(),
      'price' => $item->get('field_asking_price')->value !== NULL && $item->get('field_asking_price')->value !== '' ? '$' . number_format((float) $item->get('field_asking_price')->value, 2) : '',
    };
  }

  /**
   * The suggested value as text (price includes the range).
   */
  public static function suggested(array $draft, string $field): string {
    return match ($field) {
      'price' => (float) ($draft['suggested_price'] ?? 0) > 0
        ? sprintf('$%s (range $%s to $%s)', number_format((float) $draft['suggested_price'], 2), number_format((float) ($draft['price_min'] ?? 0), 2), number_format((float) ($draft['price_max'] ?? 0), 2))
        : '',
      default => trim((string) ($draft[$field] ?? '')),
    };
  }

  /**
   * The site's category paths, keyed by term ID ("Parent > Child").
   *
   * @return string[]
   *   Paths by term ID.
   */
  private function categoryPaths(): array {
    $paths = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('category') as $term) {
      $parent = (int) ($term->parents[0] ?? 0);
      $paths[(int) $term->tid] = ($parent && isset($paths[$parent]) ? $paths[$parent] . ' > ' : '') . $term->name;
    }
    return $paths;
  }

  /**
   * The site's category term closest to the AI's category (never NULL).
   *
   * Newer drafts already name one of the site's categories; older ones have
   * free text such as "Home & Garden > Holiday & Seasonal Decor", which gets
   * the closest match (2026-10-16, Liam: "Use the suggested category" must
   * always work).
   */
  public function categoryTerm(string $path): ?int {
    $paths = $this->categoryPaths();
    $found = array_search(PlatformCategories::closest($path, $paths), $paths, TRUE);
    return $found === FALSE ? NULL : (int) $found;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $item = NULL, ?array $run = NULL): array {
    $item ??= $form_state->get('item');
    $run ??= $form_state->get('run');
    $form_state->set('item', $item);
    $form_state->set('run', $run);
    $draft = $run['draft'];
    $form['#attributes']['class'][] = 'ishop-ai-suggestion';

    $form['rows'] = [
      '#type' => 'table',
      '#header' => [$this->t('Use'), $this->t('Field'), $this->t('Now'), $this->t('AI suggestion')],
      '#attributes' => ['class' => ['ishop-ai-suggestion__table']],
    ];
    $paths = $this->categoryPaths();
    $category = PlatformCategories::closest((string) ($draft['category'] ?? ''), $paths);
    foreach (self::FIELDS as $field => $label) {
      $suggested = self::suggested($draft, $field);
      if ($suggested === '' && $field !== 'category') {
        continue;
      }
      // Size is for clothing and shoes only (Liam, 2026-10-16).
      if ($field === 'size' && !PlatformCategories::hasSize($category)) {
        continue;
      }
      $now = self::current($item, $field);
      $usable = TRUE;
      $note = [];
      if ($field === 'condition' && !in_array($suggested, ListingWriter::CONDITIONS, TRUE)) {
        [$usable, $note] = [FALSE, [$this->t('Not one of the site\'s conditions: pick one yourself.')]];
      }
      if ($field === 'price') {
        // The AI's price is an estimate; Cody checks real sold prices in the
        // Check sold prices panel on the same page (Phase 7.5).
        $note[] = $this->t('An estimate from the photos and current listings, not confirmed sales. <a href="#sold-prices">Check sold prices</a>');
      }
      if ($field === 'category') {
        if ($suggested !== '' && $suggested !== $category) {
          $note[] = $this->t('Closest site category. The AI said: @said', ['@said' => $suggested]);
        }
        // The AI sometimes "suggests" a category the site already has.
        if (!empty($draft['new_category_idea']) && PlatformCategories::closest($draft['new_category_idea'], $paths) !== $draft['new_category_idea']) {
          $note[] = $this->t('Nothing on the site fits well. Consider adding a category: @idea', ['@idea' => $draft['new_category_idea']]);
        }
        $suggested = $category;
      }
      $empty = $now === '' || ($field === 'title' && str_starts_with($now, ReadyCheck::DEFAULT_TITLE_PREFIX));
      $form['rows'][$field] = [
        'use' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Use the suggested @field', ['@field' => mb_strtolower($label)]),
          '#title_display' => 'invisible',
          '#default_value' => $usable && $empty,
          '#disabled' => !$usable,
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Use')],
        ],
        'field' => [
          '#markup' => '<strong>' . $this->t($label) . '</strong>',
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Field')],
        ],
        'now' => [
          '#plain_text' => $now === '' ? '-' : $now,
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Now')],
        ],
        'ai' => [
          '#type' => 'inline_template',
          '#template' => '<span class="ishop-ai-suggestion__value">{{ value|nl2br }}</span>{% for n in note %}<span class="ishop-ai-suggestion__note">{{ n }}</span>{% endfor %}',
          '#context' => ['value' => $suggested, 'note' => $note],
          '#wrapper_attributes' => ['data-label' => (string) $this->t('AI suggestion')],
        ],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['use'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use the ticked suggestions'),
      '#button_type' => 'primary',
    ];
    $form['actions']['dismiss'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dismiss'),
      '#submit' => ['::dismiss'],
      '#attributes' => ['class' => ['button--ghost']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $item */
    $item = $this->entityTypeManager->getStorage('node')->load($form_state->get('item')->id());
    $run = $form_state->get('run');
    $draft = $run['draft'];
    $used = [];
    foreach (self::FIELDS as $field => $label) {
      if (!$form_state->getValue(['rows', $field, 'use'])) {
        continue;
      }
      $value = trim((string) ($draft[$field] ?? ''));
      switch ($field) {
        case 'title':
          $item->setTitle(mb_substr($value, 0, 255));
          break;

        case 'description':
          $item->set('field_description', $value);
          break;

        case 'condition_notes':
          $item->set('field_flaws', $value);
          break;

        case 'size':
        case 'color':
          $item->set('field_' . $field, mb_substr($value, 0, 255));
          break;

        case 'brand':
          $item->set('field_brand', $this->termFor('brand', $value, TRUE));
          break;

        case 'condition':
          $item->set('field_condition', $this->termFor('condition', $value, FALSE));
          break;

        case 'category':
          $item->set('field_category', $this->categoryTerm($value));
          break;

        case 'price':
          $item->set('field_asking_price', round((float) $draft['suggested_price'], 2));
          $item->set('field_ai_price_min', round((float) ($draft['price_min'] ?? 0), 2) ?: NULL);
          $item->set('field_ai_price_max', round((float) ($draft['price_max'] ?? 0), 2) ?: NULL);
          break;
      }
      $used[] = $this->t($label);
    }
    if (!$used) {
      $this->messenger()->addWarning($this->t('Nothing was ticked, so nothing changed.'));
      return;
    }
    $item->setNewRevision(TRUE);
    $item->setRevisionUserId((int) $this->currentUser()->id());
    $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $item->setRevisionLogMessage('AI suggestion used: ' . implode(', ', array_map('strval', $used)) . '.');
    $item->save();
    $this->runs->markReviewed((int) $run['id']);
    $this->messenger()->addStatus($this->t('Used: @list. Check them over; you can still edit anything.', ['@list' => implode(', ', array_map('strval', $used))]));
  }

  /**
   * Dismiss: nothing changes; the suggestion is put away.
   */
  public function dismiss(array &$form, FormStateInterface $form_state): void {
    $this->runs->markReviewed((int) $form_state->get('run')['id']);
    $this->messenger()->addStatus($this->t('Suggestion dismissed. Nothing on the item changed.'));
  }

  /**
   * A term ID by name in a vocabulary (created for brands when missing).
   */
  private function termFor(string $vocabulary, string $name, bool $create): ?int {
    if ($name === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($storage->loadByProperties(['vid' => $vocabulary]) as $term) {
      if (mb_strtolower($term->label()) === mb_strtolower($name)) {
        return (int) $term->id();
      }
    }
    if (!$create) {
      return NULL;
    }
    $term = $storage->create(['vid' => $vocabulary, 'name' => mb_substr($name, 0, 255)]);
    $term->save();
    return (int) $term->id();
  }

}
