<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\BulkActions;
use Drupal\ishop_items\Form\StockroomForm;
use Drupal\ishop_items\StockroomFilters;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Stockroom page: search and filters, then the list with bulk actions.
 *
 * The filters are a separate GET form above the list (forms can't nest), so
 * a filtered view has its own link and survives a reload.
 */
final class StockroomController extends ControllerBase {

  public function __construct(
    private readonly StockroomFilters $filters,
    private readonly BulkActions $bulk,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * The page.
   */
  public function page(): array {
    $active = StockroomFilters::parse($this->requestStack->getCurrentRequest()->query->all());
    $options = $this->filters->options($this->bulk->locationOptions());
    $select = function (string $key, string $label, string $any) use ($options, $active) {
      return [
        'key' => $key,
        'label' => $this->t($label),
        'any' => $this->t($any),
        'options' => $options[$key],
        'value' => (string) ($active[$key] ?? ''),
      ];
    };
    return [
      'filters' => [
        '#type' => 'inline_template',
        '#template' => '<details class="rm-card rm-stock-filters"{% if active %} open{% endif %}><summary>{{ title }}{% if active %} <span class="rm-chip rm-chip--todo">{{ active_label }}</span>{% endif %}</summary>
<form method="get" action="{{ action }}" class="rm-stock-filters__form" role="search">
<div class="form-item"><label for="rm-f-q">{{ q_label }}</label><input type="search" id="rm-f-q" name="q" value="{{ q }}" enterkeyhint="search" autocomplete="off"></div>
{% for s in selects %}<div class="form-item"><label for="rm-f-{{ s.key }}">{{ s.label }}</label><select id="rm-f-{{ s.key }}" name="{{ s.key }}"><option value="">{{ s.any }}</option>{% for v, l in s.options %}<option value="{{ v }}"{% if v ~ \'\' == s.value %} selected{% endif %}>{{ l }}</option>{% endfor %}</select></div>{% endfor %}
<div class="rm-stock-filters__price"><div class="form-item"><label for="rm-f-min">{{ min_label }}</label><input type="number" id="rm-f-min" name="min" min="0" step="0.01" inputmode="decimal" value="{{ min }}"></div><div class="form-item"><label for="rm-f-max">{{ max_label }}</label><input type="number" id="rm-f-max" name="max" min="0" step="0.01" inputmode="decimal" value="{{ max }}"></div></div>
<div class="rm-stock-filters__actions"><button type="submit" class="rm-button rm-button--gold">{{ apply }}</button>{% if active %} <a class="rm-button rm-button--ghost" href="{{ action }}">{{ clear }}</a>{% endif %}</div>
</form></details>',
        '#context' => [
          'title' => $this->t('Search and filters'),
          'active' => (bool) $active,
          'active_label' => $this->formatPlural(count($active), '1 on', '@count on'),
          'action' => Url::fromRoute('ishop_matrix.stockroom')->toString(),
          'q_label' => $this->t('Search titles'),
          'q' => $active['q'] ?? '',
          'selects' => [
            $select('brand', 'Brand', 'Any brand'),
            $select('size', 'Size', 'Any size'),
            $select('status', 'Status', 'Any status'),
            $select('platform', 'Posted on', 'Any platform'),
            $select('location', 'Location', 'Anywhere'),
          ],
          'min_label' => $this->t('Min $'),
          'max_label' => $this->t('Max $'),
          'min' => $active['min'] ?? '',
          'max' => $active['max'] ?? '',
          'apply' => $this->t('Show items'),
          'clear' => $this->t('Clear filters'),
        ],
      ],
      'list' => $this->formBuilder()->getForm(StockroomForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

}
