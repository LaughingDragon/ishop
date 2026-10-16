<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_life\Calendar;
use Drupal\ishop_life\Form\EventForm;
use Drupal\ishop_life\LogBook;
use Drupal\ishop_matrix\Season;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Calendar wall (Phase 5 step 3): next 14 days, the month, add an event.
 *
 * Events and bills come from ishop_life's Calendar; game days from the
 * Season (the football accents), merged here.
 */
final class CalendarController extends ControllerBase {

  public function __construct(
    private readonly Calendar $calendar,
    private readonly Season $season,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Everything on the calendar between two dates, game days included.
   */
  public function entries(string $from, string $to): array {
    $rows = $this->calendar->entries($from, $to);
    foreach ($this->season->settings()['game_days'] as $game) {
      if ($game['date'] >= $from && $game['date'] <= $to) {
        $rows[] = [
          'date' => $game['date'],
          'time' => $game['kickoff'],
          'title' => (string) $this->t('Game day'),
          'kind' => 'game',
          'url' => Url::fromRoute('ishop_matrix.my_office')->toString(),
          'edit' => NULL,
          'delete' => NULL,
        ];
      }
    }
    return Calendar::sort($rows);
  }

  /**
   * The page.
   */
  public function page(): array {
    $today = LogBook::today();
    $month = (string) $this->requestStack->getCurrentRequest()->query->get('month', substr($today, 0, 7));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
      $month = substr($today, 0, 7);
    }
    $first = new \DateTimeImmutable($month . '-01');
    $last = $first->modify('last day of this month');

    // Next 14 days, grouped by day.
    $soon_to = (new \DateTimeImmutable($today))->modify('+13 days')->format('Y-m-d');
    $soon = $this->group($this->entries($today, $soon_to), $today);

    // The month grid: weeks from Sunday to Saturday.
    $month_rows = $this->entries($first->format('Y-m-d'), $last->format('Y-m-d'));
    $by_date = [];
    foreach ($month_rows as $row) {
      $by_date[$row['date']][] = $row;
    }
    $cells = [];
    $cursor = $first->modify('-' . $first->format('w') . ' days');
    // Whole weeks: from the Sunday before the 1st to the Saturday after the end.
    while ($cursor <= $last || $cursor->format('w') !== '0') {
      $date = $cursor->format('Y-m-d');
      $cells[] = [
        'day' => (int) $cursor->format('j'),
        'in_month' => $cursor->format('Y-m') === $month,
        'today' => $date === $today,
        'rows' => $by_date[$date] ?? [],
      ];
      $cursor = $cursor->modify('+1 day');
    }
    $weeks = array_chunk($cells, 7);

    $nav = fn (string $modify) => Url::fromRoute('ishop_matrix.calendar', [], ['query' => ['month' => $first->modify($modify)->format('Y-m')]])->toString();
    return [
      'wall' => [
        '#type' => 'inline_template',
        '#template' => '<section class="rm-cal__soon" aria-labelledby="rm-cal-soon"><h2 id="rm-cal-soon">{{ soon_title }}</h2>
{% for d in soon %}<div class="rm-cal__day{{ d.today ? \' is-today\' }}"><h3>{{ d.label }}</h3><ul>{% for r in d.rows %}<li class="rm-cal__entry rm-cal__entry--{{ r.kind }}"><span class="rm-cal__kind">{{ kinds[r.kind] }}</span> {% if r.time %}<strong>{{ r.time }}</strong> {% endif %}{% if r.url %}<a href="{{ r.url }}">{{ r.title }}</a>{% else %}{{ r.title }}{% endif %}{% if r.edit %} <a class="rm-cal__edit" href="{{ r.edit }}">{{ edit }}</a>{% endif %}</li>{% endfor %}</ul></div>{% else %}<p>{{ nothing_soon }}</p>{% endfor %}</section>
<section class="rm-cal__month" aria-labelledby="rm-cal-month"><div class="rm-cal__month-head"><a class="rm-button rm-button--ghost" href="{{ prev }}" aria-label="{{ prev_label }}">&larr;</a><h2 id="rm-cal-month">{{ month_label }}</h2><a class="rm-button rm-button--ghost" href="{{ next }}" aria-label="{{ next_label }}">&rarr;</a></div>
<table class="rm-cal__grid"><thead><tr>{% for d in weekdays %}<th scope="col"><abbr title="{{ d.long }}">{{ d.short }}</abbr></th>{% endfor %}</tr></thead><tbody>{% for w in weeks %}<tr>{% for c in w %}<td class="{{ c.in_month ? \'\' : \'is-other\' }}{{ c.today ? \' is-today\' }}"><span class="rm-cal__num">{{ c.day }}</span>{% for r in c.rows|slice(0, 2) %}<span class="rm-cal__chip rm-cal__chip--{{ r.kind }}">{{ r.title }}</span>{% endfor %}{% if c.rows|length > 2 %}<span class="rm-cal__more">+{{ c.rows|length - 2 }}</span>{% endif %}</td>{% endfor %}</tr>{% endfor %}</tbody></table>
{% if month_list %}<h3>{{ list_title }}</h3><ul class="rm-cal__list">{% for r in month_list %}<li class="rm-cal__entry rm-cal__entry--{{ r.kind }}"><strong>{{ r.day }}</strong> <span class="rm-cal__kind">{{ kinds[r.kind] }}</span> {% if r.time %}{{ r.time }} {% endif %}{{ r.title }}{% if r.edit %} <a class="rm-cal__edit" href="{{ r.edit }}">{{ edit }}</a> <a class="rm-cal__edit" href="{{ r.delete }}">{{ delete }}</a>{% endif %}</li>{% endfor %}</ul>{% else %}<p>{{ nothing_month }}</p>{% endif %}</section>',
        '#context' => [
          'soon_title' => $this->t('Next 14 days'),
          'soon' => $soon,
          'nothing_soon' => $this->t('Nothing in the next 14 days.'),
          'kinds' => [
            'event' => $this->t('Event'),
            'bill' => $this->t('Bill'),
            'game' => $this->t('Game'),
            'todo' => $this->t('To-do'),
          ],
          'edit' => $this->t('Edit'),
          'delete' => $this->t('Delete'),
          'month_label' => $first->format('F Y'),
          'prev' => $nav('-1 month'),
          'next' => $nav('+1 month'),
          'prev_label' => $this->t('Previous month'),
          'next_label' => $this->t('Next month'),
          'weekdays' => array_map(fn ($d) => [
            'short' => substr($d, 0, 1),
            'long' => $d,
          ], ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']),
          'weeks' => $weeks,
          'list_title' => $this->t('All of @month', ['@month' => $first->format('F')]),
          'month_list' => array_map(fn ($r) => $r + ['day' => (new \DateTimeImmutable($r['date']))->format('D j')], $month_rows),
          'nothing_month' => $this->t('Nothing this month yet.'),
        ],
      ],
      'form' => $this->formBuilder()->getForm(EventForm::class),
      '#attached' => ['library' => ['ishop_matrix/matrix', 'ishop_life/log']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Rows grouped by day, with labels such as "Today" and "Fri Oct 16".
   */
  private function group(array $rows, string $today): array {
    $days = [];
    $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    foreach ($rows as $row) {
      $label = match ($row['date']) {
        $today => (string) $this->t('Today'),
        $tomorrow => (string) $this->t('Tomorrow'),
        default => (new \DateTimeImmutable($row['date']))->format('l, M j'),
      };
      $days[$row['date']] ??= ['label' => $label, 'today' => $row['date'] === $today, 'rows' => []];
      $days[$row['date']]['rows'][] = $row;
    }
    return array_values($days);
  }

}
