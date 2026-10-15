<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
use Drupal\ishop_items\Controller\StaleController;
use Drupal\ishop_items\Money;
use Drupal\ishop_life\Bills;
use Drupal\ishop_life\Controller\BillsController;
use Drupal\ishop_items\TaxExport;
use Drupal\ishop_items\StaleNudges;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_matrix\Birthday;
use Drupal\ishop_matrix\Form\SeasonForm;
use Drupal\ishop_matrix\Rooms;
use Drupal\ishop_matrix\Season;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Reseller Matrix dashboard (front page) and the rooms.
 *
 * Follows the Reseller Matrix theme's markup contract (README and
 * preview/dashboard.html). Glance lines and "Needs you now" use real data
 * only, and say so plainly when there is nothing.
 */
final class MatrixController extends ControllerBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly Birthday $birthday,
    private readonly Season $season,
    private readonly StaleNudges $nudges,
    private readonly Money $money,
    private readonly TaxExport $taxExport,
    private readonly Bills $bills,
  ) {}

  /**
   * The front page: greeting, "Needs you now", the building.
   */
  public function dashboard(): array {
    $now = $this->time->getRequestTime();
    $needs = $this->needs($now);
    $glances = $this->glances($needs, $now);
    $rooms = [];
    foreach (Rooms::ROOMS as $slug => [$name, $icon, $word]) {
      $rooms[] = [
        'url' => $slug === 'front-desk' ? Url::fromRoute('ishop_matrix.dashboard')->toString() . '#needs' : Url::fromRoute('ishop_matrix.room', ['room' => $slug])->toString(),
        'name' => $name,
        'icon' => $icon,
        'word' => $word,
        'glance' => $glances[$slug],
      ];
    }
    // TEMPORARY (birthday mode): see TEMPORARY.md.
    $birthday_cache = $this->birthday->cacheability();
    $party = $this->birthday->isActive($this->currentUser()) ? [
      'badge' => $this->t('Today is a very big deal'),
      // Two lines on the sign: "Happy birthday," / "boss!".
      'hello_1' => $this->t('Happy birthday,'),
      'hello_2' => $this->t('boss!'),
      'line' => $this->t("The whole building is celebrating. Every room, every shelf, every listing. Take the day. You've earned it."),
      'start' => $this->t('Start the party'),
      'video_title' => $this->t('Your requested wake-up call'),
      'video_line' => $this->t('You asked, the crew delivers.'),
      'video_url' => Birthday::VIDEO_URL,
      'video_button' => $this->t('Play it on YouTube'),
    ] : NULL;
    // On the birthday, birthday mode replaces the football accents.
    $game = $party ? NULL : $this->season->todaysGame();
    // Stale-listing nudges: only the few most overdue, each with Done /
    // Snooze 2 weeks / Keep as is. They can wait on the birthday.
    $due = $party ? [] : $this->nudges->due();
    $return = Url::fromRoute('ishop_matrix.dashboard')->toString();
    foreach (array_slice($due, 0, StaleNudges::DASHBOARD_LIMIT) as $row) {
      $needs[] = [
        'text' => StaleController::text($row),
        'buttons' => StaleController::buttons($row['listing'], $return),
      ];
    }
    if (count($due) > StaleNudges::DASHBOARD_LIMIT) {
      $needs[] = [
        'text' => $this->formatPlural(count($due) - StaleNudges::DASHBOARD_LIMIT, '1 more listing has been up 30+ days.', '@count more listings have been up 30+ days.'),
        'url' => Url::fromRoute('ishop_items.stale')->toString(),
        'action' => $this->t('See them all'),
        'ghost' => TRUE,
      ];
    }
    // Bills due within 3 days (or overdue), each with a Paid button.
    foreach ($this->bills->due() as $bill) {
      $needs[] = [
        'text' => $this->bills->lineFor($bill),
        'buttons' => [[
          'label' => $this->t('Paid'),
          'url' => BillsController::paidUrl($bill, $return),
          'ghost' => FALSE,
        ],
        ],
      ];
      $due[] = $bill;
    }
    if ($due) {
      foreach ($rooms as &$room) {
        if ($room['icon'] === 'front_desk') {
          $room['glance'] = $this->glances($needs, $now)['front-desk'];
        }
      }
      unset($room);
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{% if party %}<section class="rm-greeting rm-party-sign"><div class="rm-confetti" aria-hidden="true"></div><div class="rm-greeting__text"><span class="rm-badge rm-badge--party">{{ party.badge }}</span><h1><span>{{ party.hello_1 }}</span> <span>{{ party.hello_2 }}</span></h1><p>{{ party.line }}</p><button type="button" class="rm-button rm-button--gold rm-party-toggle" hidden>{{ party.start }}</button></div></section>
<section class="rm-card rm-party-video" aria-labelledby="rm-party-video-title"><h2 id="rm-party-video-title">{{ party.video_title }}</h2><p>{{ party.video_line }}</p><a class="rm-button rm-button--gold" href="{{ party.video_url }}" target="_blank" rel="noopener noreferrer">{{ party.video_button }}</a></section>
{% else %}<section class="rm-greeting"><div class="rm-greeting__text"><h1>{{ hello }}</h1><p>{{ date }}</p></div>{% if game %}<span class="rm-badge rm-badge--game">{% include "@reseller_matrix/includes/room-icon.html.twig" with { room: "football", size: 22 } only %}{{ game.badge }}</span>{% else %}<span class="rm-badge">{{ badge }}</span>{% endif %}</section>
{% if score %}<section class="rm-scoreboard" aria-labelledby="rm-season"><h2 id="rm-season">{{ score.title }} <span class="rm-scoreboard__since">{{ score.since }}</span></h2><dl class="rm-scoreboard__cells">{% for c in score.cells %}<div class="rm-scoreboard__cell"><dt>{{ c.label }}</dt><dd>{{ c.value }}</dd></div>{% endfor %}</dl></section>{% endif %}{% endif %}
<section class="rm-needs" id="needs" aria-labelledby="rm-now"><h2 id="rm-now">{{ needs_title }}</h2>
{% for n in needs %}<div class="rm-needs__row"><span>{{ n.text }}</span>{% if n.buttons %}<span class="rm-needs__buttons">{% for b in n.buttons %}<a class="rm-button{% if b.ghost %} rm-button--ghost{% endif %}" href="{{ b.url }}">{{ b.label }}</a>{% endfor %}</span>{% else %}<a class="rm-button{% if n.ghost %} rm-button--ghost{% endif %}" href="{{ n.url }}">{{ n.action }}</a>{% endif %}</div>
{% else %}<p class="rm-needs__empty">{{ nothing }}</p>{% endfor %}</section>
<h2 class="rm-section-title">{{ building }}</h2><ul class="rm-rooms">
{% for r in rooms %}<li><a class="rm-room" href="{{ r.url }}"><span class="rm-room__icon">{% include "@reseller_matrix/includes/room-icon.html.twig" with { room: r.icon, size: 30 } only %}<span class="rm-room__word">{{ r.word }}</span></span><span class="rm-room__text"><span class="rm-room__name">{{ r.name }}</span><span class="rm-room__glance">{{ r.glance }}</span></span></a></li>
{% endfor %}</ul>',
      '#context' => [
        'hello' => $this->t('Welcome back, boss!'),
        'date' => $game && $game['kickoff'] !== ''
          ? $this->t("@date. Kickoff at @time. Here's the building at a glance.", [
            '@date' => $this->dateFormatter->format($now, 'custom', 'l, F j'),
            '@time' => date('g:i A', strtotime('2000-01-01 ' . $game['kickoff'])),
          ])
          : $this->t("@date. Here's the building at a glance.", ['@date' => $this->dateFormatter->format($now, 'custom', 'l, F j')]),
        'game' => $game ? ['badge' => $this->t('Game day')] : NULL,
        'score' => $party ? NULL : $this->scoreboard(),
        'badge' => $this->t('Open for business'),
        'needs_title' => $this->t('Needs you now'),
        'needs' => $needs,
        'nothing' => $party ? $this->t("Nothing that can't wait until tomorrow. It's your birthday.") : $this->t('Nothing needs you right now.'),
        'building' => $this->t('The building'),
        'rooms' => $rooms,
        'party' => $party,
      ],
      '#attached' => ['library' => ['ishop_matrix/matrix']],
      '#cache' => [
        'tags' => Cache::mergeTags(['node_list:item', 'platform_listing_list', Season::CACHE_TAG], $birthday_cache['tags']),
        'contexts' => Cache::mergeContexts(['user.permissions'], $birthday_cache['contexts']),
        // The date line and "closes in N days" change daily. Nudge buttons
        // carry a per-session token: never cache those.
        'max-age' => $due ? 0 : min(3600, $birthday_cache['max-age']),
      ],
    ];
  }

  /**
   * The "Needs you now" rows: take-downs after a sale, return windows.
   */
  private function needs(int $now): array {
    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('platform_listing');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', Platforms::TAKE_DOWN)->sort('item')->execute();
    $by_item = [];
    foreach ($storage->loadMultiple($ids) as $listing) {
      if ($item = $listing->getItem()) {
        $by_item[$item->id()]['item'] = $item;
        $by_item[$item->id()]['platforms'][] = Platforms::PLATFORMS[$listing->getPlatform()];
      }
    }
    foreach ($by_item as $row) {
      $sold = $storage->loadByProperties(['item' => $row['item']->id(), 'status' => Platforms::SOLD_HERE]);
      $sold = $sold ? Platforms::PLATFORMS[reset($sold)->getPlatform()] : NULL;
      $rows[] = [
        'text' => $sold
          ? $this->t('Touchdown! @item sold on @sold. Take it down from @list.', [
            '@item' => $row['item']->label(),
            '@sold' => $sold,
            '@list' => Rooms::andList($row['platforms']),
          ])
          : $this->t('@item: take it down from @list.', [
            '@item' => $row['item']->label(),
            '@list' => Rooms::andList($row['platforms']),
          ]),
        'url' => $row['item']->toUrl('canonical', ['fragment' => 'listings'])->toString(),
        'action' => $this->t('Take care of it'),
        'ghost' => FALSE,
      ];
    }

    $today = $this->dateFormatter->format($now, 'custom', 'Y-m-d');
    $last = $this->dateFormatter->format($now + Rooms::RETURN_WARN_DAYS * 86400, 'custom', 'Y-m-d');
    $nodes = $this->entityTypeManager()->getStorage('node');
    $ids = $nodes->getQuery()->accessCheck(TRUE)
      ->condition('type', 'item')
      ->condition('field_return_by', $today, '>=')
      ->condition('field_return_by', $last, '<=')
      ->condition('field_item_status', Rooms::DONE, 'NOT IN')
      ->sort('field_return_by')
      ->execute();
    foreach ($nodes->loadMultiple($ids) as $item) {
      $days = Rooms::daysUntil($today, (string) $item->get('field_return_by')->value);
      $rows[] = [
        'text' => $days === 0
          ? $this->t("@item's return window closes today. Return it or list it?", ['@item' => $item->label()])
          : $this->formatPlural($days, "@item's return window closes tomorrow. Return it or list it?", "@item's return window closes in @count days. Return it or list it?", ['@item' => $item->label()]),
        'url' => $item->toUrl()->toString(),
        'action' => $this->t('Decide'),
        'ghost' => TRUE,
      ];
    }
    return $rows;
  }

  /**
   * One real-data line per room; plain words when there's nothing.
   */
  private function glances(array $needs, int $now): array {
    $nodes = $this->entityTypeManager()->getStorage('node');
    $on_hand = (int) $nodes->getQuery()->accessCheck(TRUE)
      ->condition('type', 'item')
      ->condition('field_item_status', Rooms::ON_HAND, 'IN')
      ->count()->execute();

    // Same numbers as the Money page: sales by Date sold this month.
    $month = Money::summarize($this->money->sales($this->dateFormatter->format($now, 'custom', 'Y-m-01'), NULL));

    $not_built = (string) $this->t('Not built yet');
    return [
      'front-desk' => Rooms::count(count($needs), '%d thing needs you', '%d things need you', 'Nothing needs you right now'),
      'stockroom' => Rooms::count($on_hand, '%d item on the game room table', '%d items on the game room table', 'No items yet'),
      'shipping-room' => $not_built,
      'my-office' => $month['count'] ? sprintf('Sold this month: $%s', number_format($month['revenue'], 2)) : 'Nothing sold yet this month',
      'mailroom' => $not_built,
      'calendar-wall' => $not_built,
      'break-room' => $not_built,
    ];
  }

  /**
   * The "This season" scoreboard: sold, listed, gold struck since the start.
   *
   * Real data only; zeros when there is nothing yet. Sold and gold struck
   * count "Sold here" listings by Date sold; listed counts listings by
   * "Date posted".
   */
  private function scoreboard(): array {
    // The same numbers as the Money page (Money::summarize).
    $start = $this->season->startOfSeason()->format('Y-m-d');
    $sales = Money::summarize($this->money->sales($start, NULL));
    $listed = $this->money->listedCount($start, NULL);
    $start = $this->season->startOfSeason();
    return [
      'title' => $this->t('This season'),
      'since' => $this->t('since @date', ['@date' => $start->format('M j')]),
      'cells' => [
        ['label' => $this->t('Sold'), 'value' => number_format($sales['count'])],
        ['label' => $this->t('Listed'), 'value' => number_format($listed)],
        ['label' => $this->t('Gold struck'), 'value' => '$' . number_format($sales['revenue'])],
      ],
    ];
  }

  /**
   * Periods for the Money page: key => [label, from, to] (Y-m-d or NULL).
   */
  private function periods(): array {
    $today = new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));
    $year = (int) $today->format('Y');
    return [
      'season' => [$this->t('This season'), $this->season->startOfSeason()->format('Y-m-d'), NULL],
      'month' => [$this->t('This month'), $today->format('Y-m-01'), NULL],
      'year' => [$this->t('This year'), "$year-01-01", NULL],
      'last_year' => [$this->t('Last year'), ($year - 1) . '-01-01', ($year - 1) . '-12-31'],
      'all' => [$this->t('All time'), NULL, NULL],
    ];
  }

  /**
   * The Money page section: sold by platform, profit, days to sell.
   */
  private function moneySection(): array {
    $periods = $this->periods();
    $key = (string) \Drupal::request()->query->get('period', 'season');
    $key = isset($periods[$key]) ? $key : 'season';
    [$label, $from, $to] = $periods[$key];
    $sum = Money::summarize($this->money->sales($from, $to));
    $dollars = fn (float $v) => ($v < 0 ? '-$' : '$') . number_format(abs($v), 2);
    $max = max([1.0, ...array_map(fn ($p) => $p['revenue'], $sum['by_platform'])]);
    $platforms = [];
    foreach ($sum['by_platform'] as $platform => $p) {
      $platforms[] = [
        'name' => Platforms::PLATFORMS[$platform],
        'count' => $p['count'],
        'revenue' => $dollars($p['revenue']),
        'profit' => $dollars($p['profit']),
        'width' => max(2, (int) round($p['revenue'] / $max * 100)),
      ];
    }
    $sitting = [];
    foreach ($this->money->longestSitting(5) as $row) {
      $sitting[] = [
        'title' => $row['item']->label(),
        'url' => $row['item']->toUrl('canonical', ['fragment' => 'listings'])->toString(),
        'platform' => Platforms::PLATFORMS[$row['platform']],
        'days' => $row['days'],
      ];
    }
    $tabs = [];
    foreach ($periods as $k => [$tab_label]) {
      $tabs[] = [
        'label' => $tab_label,
        'url' => Url::fromRoute('ishop_matrix.my_office', [], ['query' => ['period' => $k]])->toString(),
        'current' => $k === $key,
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="rm-money" aria-labelledby="rm-money-title"><h2 id="rm-money-title">{{ title }}</h2>
<nav class="rm-money__periods" aria-label="{{ periods_label }}">{% for t in tabs %}<a href="{{ t.url }}" class="rm-money__period{{ t.current ? \' is-current\' }}"{% if t.current %} aria-current="page"{% endif %}>{{ t.label }}</a>{% endfor %}</nav>
<dl class="rm-scoreboard__cells rm-money__totals">{% for c in cells %}<div class="rm-scoreboard__cell"><dt>{{ c.label }}</dt><dd>{{ c.value }}</dd></div>{% endfor %}</dl>
{% if notes %}<ul class="rm-money__notes">{% for n in notes %}<li>{{ n }}</li>{% endfor %}</ul>{% endif %}
<h3>{{ by_title }}</h3>{% if platforms %}<ul class="rm-money__bars">{% for p in platforms %}<li><span class="rm-money__bar-label"><strong>{{ p.name }}</strong> {{ p.count }} {{ sold_word }} · {{ p.revenue }} · {{ profit_word }} {{ p.profit }}</span><span class="rm-money__bar" style="width: {{ p.width }}%"></span></li>{% endfor %}</ul>{% else %}<p>{{ no_sales }}</p>{% endif %}
<h3>{{ sitting_title }}</h3>{% if sitting %}<ol class="rm-money__sitting">{% for s in sitting %}<li><a href="{{ s.url }}">{{ s.title }}</a> · {{ s.platform }} · {{ s.days }} {{ days_word }}</li>{% endfor %}</ol>{% else %}<p>{{ nothing_live }}</p>{% endif %}
</section>',
      '#context' => [
        'title' => $this->t('Money'),
        'periods_label' => $this->t('Period'),
        'tabs' => $tabs,
        'cells' => [
          ['label' => $this->t('Sold'), 'value' => number_format($sum['count'])],
          ['label' => $this->t('Sales'), 'value' => '$' . number_format($sum['revenue'])],
          [
            'label' => $this->t('Profit'),
            'value' => ($sum['profit'] < 0 ? '-$' : '$') . number_format(abs($sum['profit'])),
          ],
          [
            'label' => $this->t('Avg days to sell'),
            'value' => $sum['avg_days'] === NULL ? '-' : number_format($sum['avg_days'], 0),
          ],
        ],
        'notes' => array_filter([
          $sum['count'] ? $this->t('@label: @revenue in sales, minus @fees fees, @shipping shipping and @cost item cost.', [
            '@label' => $label,
            '@revenue' => $dollars($sum['revenue']),
            '@fees' => $dollars($sum['fees']),
            '@shipping' => $dollars($sum['shipping']),
            '@cost' => $dollars($sum['cost']),
          ]) : NULL,
          $sum['missing_price'] ? $this->formatPlural($sum['missing_price'], '1 sale has no sale price entered, so it adds nothing to sales.', '@count sales have no sale price entered, so they add nothing to sales.') : NULL,
          $sum['missing_cost'] ? $this->formatPlural($sum['missing_cost'], "1 sale's item has no cost entered, so its profit may be too high.", "@count sales' items have no cost entered, so their profit may be too high.") : NULL,
        ]),
        'by_title' => $this->t('Sold by platform'),
        'platforms' => $platforms,
        'sold_word' => $this->t('sold'),
        'profit_word' => $this->t('profit'),
        'no_sales' => $this->t('No sales in this period yet.'),
        'sitting_title' => $this->t('Longest-sitting live listings'),
        'sitting' => $sitting,
        'days_word' => $this->t('days'),
        'nothing_live' => $this->t('Nothing is live on a platform yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The year-end tax export: sales and donations CSV per year.
   */
  private function taxSection(): array {
    $years = [];
    foreach ($this->taxExport->years() as $year) {
      $years[] = [
        'year' => $year,
        'sales' => Url::fromRoute('ishop_items.tax_export', ['year' => $year, 'kind' => 'sales'])->toString(),
        'donations' => Url::fromRoute('ishop_items.tax_export', ['year' => $year, 'kind' => 'donations'])->toString(),
        'mileage' => Url::fromRoute('ishop_life.tax_export', ['year' => $year, 'kind' => 'mileage'])->toString(),
        'expenses' => Url::fromRoute('ishop_life.tax_export', ['year' => $year, 'kind' => 'expenses'])->toString(),
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="rm-card rm-tax" aria-labelledby="rm-tax-title"><h2 id="rm-tax-title">{{ title }}</h2><p>{{ intro }}</p><ul class="rm-tax__years">{% for y in years %}<li><strong>{{ y.year }}</strong> <a class="rm-button rm-button--gold" href="{{ y.sales }}">{{ sales }}</a> <a class="rm-button rm-button--ghost" href="{{ y.donations }}">{{ donations }}</a> <a class="rm-button rm-button--ghost" href="{{ y.mileage }}">{{ mileage }}</a> <a class="rm-button rm-button--ghost" href="{{ y.expenses }}">{{ expenses }}</a></li>{% endfor %}</ul><p class="rm-tax__note">{{ note }}</p></section>',
      '#context' => [
        'title' => $this->t('Year-end tax export'),
        'intro' => $this->t('Spreadsheet files (CSV) for your tax records: every sale with its price, cost, fees, shipping and profit, the donation log, mileage and expenses. Anything missing is left blank and marked in the "Check" column, never guessed.'),
        'years' => $years,
        'sales' => $this->t('Sales (CSV)'),
        'donations' => $this->t('Donations (CSV)'),
        'mileage' => $this->t('Mileage (CSV)'),
        'expenses' => $this->t('Expenses (CSV)'),
        'note' => $this->t('Donation values: enter an "Estimated donation value" on each donated item (Edit the item). Mileage and expenses come from "Log a trip or an expense" above.'),
      ],
    ];
  }

  /**
   * My office: the season settings and game days (the rest is coming).
   */
  public function myOffice(): array {
    $build = $this->room('my-office');
    return [
      'log' => [
        '#type' => 'inline_template',
        '#template' => '<p class="rm-office-links"><a class="rm-button rm-button--gold" href="{{ url }}">{{ label }}</a> <a class="rm-button rm-button--ghost" href="{{ bills }}">{{ bills_label }}</a></p>',
        '#context' => [
          'url' => Url::fromRoute('ishop_life.log')->toString(),
          'label' => $this->t('Log a trip or an expense'),
          'bills' => Url::fromRoute('ishop_life.bills')->toString(),
          'bills_label' => $this->t('Bills and subscriptions'),
        ],
      ],
      'money' => $this->moneySection(),
      'tax' => $this->taxSection(),
      'season' => $this->formBuilder()->getForm(SeasonForm::class),
      'rest' => $build,
      '#attached' => ['library' => ['ishop_matrix/matrix']],
    ];
  }

  /**
   * A room. None are built yet, so each says what it will be for.
   */
  public function room(string $room): array {
    if (!isset(Rooms::ROOMS[$room])) {
      throw new NotFoundHttpException();
    }
    [$name, $icon, $word, $purpose] = Rooms::ROOMS[$room];
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="rm-card rm-coming-soon"><span class="rm-room__icon">{% include "@reseller_matrix/includes/room-icon.html.twig" with { room: icon, size: 30 } only %}<span class="rm-room__word">{{ word }}</span></span><h2>{{ soon }}</h2><p>{{ purpose }}</p><p><a class="rm-button rm-button--ghost" href="{{ back }}">{{ back_label }}</a></p></div>',
      '#context' => [
        'icon' => $icon,
        'word' => $word,
        'soon' => $this->t('Coming soon'),
        'purpose' => $this->t('@name: @purpose This room is being built.', ['@name' => $name, '@purpose' => $purpose]),
        'back' => Url::fromRoute('ishop_matrix.dashboard')->toString(),
        'back_label' => $this->t('Back to the front desk'),
      ],
      '#attached' => ['library' => ['ishop_matrix/matrix']],
    ];
  }

  /**
   * Title for a room page.
   */
  public function roomTitle(string $room): string {
    return Rooms::ROOMS[$room][0] ?? 'Room';
  }

}
