<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\BulkActions;
use Drupal\ishop_items\Form\StockroomForm;
use Drupal\ishop_items\ItemPhotos;
use Drupal\ishop_items\StockroomFilters;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Stockroom page: the count, search and filter chips, then the list.
 *
 * Redesign, punch 622 (Liam): list first, like iOS Mail. The search box and
 * chips are a separate GET form above the list (forms can't nest), pinned
 * while the list scrolls, so a filtered view has its own link and survives a
 * reload. Typing filters the rows already on the page (stockroom.js); Enter
 * searches every status on the server.
 */
final class StockroomController extends ControllerBase {

  public function __construct(
    private readonly StockroomFilters $filters,
    private readonly BulkActions $bulk,
    private readonly ItemPhotos $photos,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * The page.
   */
  public function page(): array {
    $active = StockroomFilters::parse($this->requestStack->getCurrentRequest()->query->all());
    $options = $this->filters->options($this->bulk->locationOptions());
    $status = (string) ($active['status'] ?? '');

    // Sold has its own chip, so the Status chip lists it only while it's on.
    $statuses = ['' => (string) $this->t('Selling')];
    foreach ($options['status'] as $value => $label) {
      if ($value !== 'sold' || $status === 'sold') {
        $statuses[$value] = (string) $this->t($label);
      }
    }
    $statuses[StockroomFilters::ALL] = (string) $this->t('Every status');
    $chip = fn (string $key, string $label, array $choices) => [
      'key' => $key,
      'label' => $this->t($label),
      'options' => $choices,
      'value' => (string) ($active[$key] ?? ''),
    ];
    $sorts = array_map(fn ($label) => (string) $this->t($label), StockroomFilters::SORTS);
    $chips = [
      $chip('status', 'Status', $statuses),
      $chip('platform', 'Posted on', ['' => (string) $this->t('All platforms')] + $options['platform']),
      $chip('category', 'Category', ['' => (string) $this->t('All categories')] + $options['category']),
      $chip('sort', 'Sort', ['' => array_shift($sorts)] + $sorts),
    ];
    $select = function (string $key, string $label, string $any) use ($options, $active) {
      return [
        'key' => $key,
        'label' => $this->t($label),
        'any' => $this->t($any),
        'options' => $options[$key],
        'value' => (string) ($active[$key] ?? ''),
      ];
    };
    $more = array_intersect_key($active, array_flip(['brand', 'size', 'location', 'min', 'max']));

    // The Sold chip turns Sold on, or back off to the default.
    $sold = $active;
    unset($sold['status']);
    if ($status !== 'sold') {
      $sold['status'] = 'sold';
    }
    $on_table = (int) $this->entityTypeManager()->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'item')
      ->condition('field_item_status', StockroomFilters::SELLING, 'IN')
      ->count()->execute();
    $need_photos = $this->photos->withoutPhotos();

    return [
      'top' => [
        '#type' => 'inline_template',
        '#template' => '<div class="rm-stock-head">
<p class="rm-stock-head__count"><strong>{{ count }}</strong>{% if photos %} <a class="rm-stock-head__photos" href="{{ photos_url }}">{{ photos }}</a>{% endif %}</p>
<button type="button" class="rm-button rm-button--ghost rm-stock-head__select" hidden data-done="{{ done }}">{{ select }}</button>
</div>
<form method="get" action="{{ action }}" class="rm-stock-tools" role="search">
<div class="rm-stock-tools__search"><label for="rm-f-q" class="visually-hidden">{{ q_label }}</label><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="m20 20-4.5-4.5M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/></svg><input type="search" id="rm-f-q" name="q" value="{{ q }}" placeholder="{{ q_label }}" enterkeyhint="search" autocomplete="off"></div>
<div class="rm-stock-tools__row">
<div class="rm-stock-tools__chips">
{% for c in chips %}<label class="rm-chipsel{% if c.value != \'\' %} is-on{% endif %}"><span class="visually-hidden">{{ c.label }}</span><select name="{{ c.key }}">{% for v, l in c.options %}<option value="{{ v }}"{% if v ~ \'\' == c.value %} selected{% endif %}>{{ l }}</option>{% endfor %}</select></label>{% if loop.first %}<a class="rm-chiplink{% if sold_on %} is-on{% endif %}" href="{{ sold_url }}"{% if sold_on %} aria-current="true"{% endif %}>{{ sold }}{% if sold_on %} <span aria-hidden="true">&times;</span>{% endif %}</a>{% endif %}{% endfor %}
{% if active %}<a class="rm-chiplink rm-chiplink--clear" href="{{ action }}">{{ clear }}</a>{% endif %}
</div>
<details class="rm-stock-more"><summary class="rm-chiplink{% if more_on %} is-on{% endif %}" aria-label="{{ more_label }}">{{ more }}</summary>
<div class="rm-stock-more__panel">
{% for s in selects %}<div class="form-item"><label for="rm-f-{{ s.key }}">{{ s.label }}</label><select id="rm-f-{{ s.key }}" name="{{ s.key }}"><option value="">{{ s.any }}</option>{% for v, l in s.options %}<option value="{{ v }}"{% if v ~ \'\' == s.value %} selected{% endif %}>{{ l }}</option>{% endfor %}</select></div>{% endfor %}
<div class="rm-stock-more__price"><div class="form-item"><label for="rm-f-min">{{ min_label }}</label><input type="number" id="rm-f-min" name="min" min="0" step="0.01" inputmode="decimal" value="{{ min }}"></div><div class="form-item"><label for="rm-f-max">{{ max_label }}</label><input type="number" id="rm-f-max" name="max" min="0" step="0.01" inputmode="decimal" value="{{ max }}"></div></div>
<button type="submit" class="rm-button rm-button--gold">{{ apply }}</button>
</div></details>
</div>
<button type="submit" class="rm-button rm-button--gold rm-stock-tools__go">{{ apply }}</button>
</form>',
        '#context' => [
          'count' => $this->formatPlural($on_table, '1 on the table', '@count on the table'),
          'photos' => $need_photos ? $this->formatPlural(count($need_photos), '1 needs photos', '@count need photos') : '',
          'photos_url' => $need_photos ? Url::fromRoute('ishop_items.item_photos', ['node' => $need_photos[0]])->toString() : '',
          'select' => $this->t('Select'),
          'done' => $this->t('Done'),
          'action' => Url::fromRoute('ishop_matrix.stockroom')->toString(),
          'q_label' => $this->t('Search your items'),
          'q' => $active['q'] ?? '',
          'chips' => $chips,
          'sold' => $this->t('Sold'),
          'sold_on' => $status === 'sold',
          'sold_url' => Url::fromRoute('ishop_matrix.stockroom', [], ['query' => $sold])->toString(),
          'active' => (bool) $active,
          'clear' => $this->t('Clear'),
          'more' => $more ? $this->formatPlural(count($more), 'More (1 on)', 'More (@count on)') : $this->t('More'),
          'more_on' => (bool) $more,
          'more_label' => $this->t('More filters: brand, size, location, price'),
          'selects' => [
            $select('brand', 'Brand', 'Any brand'),
            $select('size', 'Size', 'Any size'),
            $select('location', 'Location', 'Anywhere'),
          ],
          'min_label' => $this->t('Min $'),
          'max_label' => $this->t('Max $'),
          'min' => $active['min'] ?? '',
          'max' => $active['max'] ?? '',
          'apply' => $this->t('Show items'),
        ],
      ],
      'list' => $this->formBuilder()->getForm(StockroomForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

}
