<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
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
      'hello' => $this->t('Happy birthday, boss!'),
      'line' => $this->t("The whole building is celebrating. Every room, every shelf, every listing. Take the day. You've earned it."),
      'start' => $this->t('Start the party'),
    ] : NULL;
    // On the birthday, birthday mode replaces the football accents.
    $game = $party ? NULL : $this->season->todaysGame();
    return [
      '#type' => 'inline_template',
      '#template' => '{% if party %}<section class="rm-greeting rm-party-sign"><div class="rm-confetti" aria-hidden="true"></div><div class="rm-greeting__text"><span class="rm-badge rm-badge--party">{{ party.badge }}</span><h1>{{ party.hello }}</h1><p>{{ party.line }}</p><button type="button" class="rm-button rm-button--gold rm-party-toggle" hidden>{{ party.start }}</button></div></section>
{% else %}<section class="rm-greeting"><div class="rm-greeting__text"><h1>{{ hello }}</h1><p>{{ date }}</p></div>{% if game %}<span class="rm-badge rm-badge--game">{% include "@reseller_matrix/includes/room-icon.html.twig" with { room: "football", size: 22 } only %}{{ game.badge }}</span>{% else %}<span class="rm-badge">{{ badge }}</span>{% endif %}</section>
{% if score %}<section class="rm-scoreboard" aria-labelledby="rm-season"><h2 id="rm-season">{{ score.title }} <span class="rm-scoreboard__since">{{ score.since }}</span></h2><dl class="rm-scoreboard__cells">{% for c in score.cells %}<div class="rm-scoreboard__cell"><dt>{{ c.label }}</dt><dd>{{ c.value }}</dd></div>{% endfor %}</dl></section>{% endif %}{% endif %}
<section class="rm-needs" id="needs" aria-labelledby="rm-now"><h2 id="rm-now">{{ needs_title }}</h2>
{% for n in needs %}<div class="rm-needs__row"><span>{{ n.text }}</span><a class="rm-button{% if n.ghost %} rm-button--ghost{% endif %}" href="{{ n.url }}">{{ n.action }}</a></div>
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
      '#cache' => [
        'tags' => Cache::mergeTags(['node_list:item', 'platform_listing_list', Season::CACHE_TAG], $birthday_cache['tags']),
        'contexts' => Cache::mergeContexts(['user.permissions'], $birthday_cache['contexts']),
        // The date line and "closes in N days" change daily.
        'max-age' => min(3600, $birthday_cache['max-age']),
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

    $month_start = strtotime($this->dateFormatter->format($now, 'custom', 'Y-m-01 00:00:00'));
    $listings = $this->entityTypeManager()->getStorage('platform_listing');
    $sold_ids = $listings->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->condition('changed', $month_start, '>=')
      ->execute();
    $total = 0.0;
    foreach ($listings->loadMultiple($sold_ids) as $listing) {
      $total += (float) $listing->get('sold_price')->value;
    }

    $not_built = (string) $this->t('Not built yet');
    return [
      'front-desk' => Rooms::count(count($needs), '%d thing needs you', '%d things need you', 'Nothing needs you right now'),
      'stockroom' => Rooms::count($on_hand, '%d item on the game room table', '%d items on the game room table', 'No items yet'),
      'shipping-room' => $not_built,
      'my-office' => $sold_ids ? sprintf('Sold this month: $%s', number_format($total, 2)) : 'Nothing sold yet this month',
      'mailroom' => $not_built,
      'calendar-wall' => $not_built,
      'break-room' => $not_built,
    ];
  }

  /**
   * The "This season" scoreboard: sold, listed, gold struck since the start.
   *
   * Real data only; zeros when there is nothing yet. Sold and gold struck
   * count "Sold here" listings by when they were last changed (there is no
   * separate sale date); listed counts listings by "Date posted".
   */
  private function scoreboard(): array {
    $start = $this->season->startOfSeason();
    $listings = $this->entityTypeManager()->getStorage('platform_listing');
    $sold_ids = $listings->getQuery()->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE)
      ->condition('changed', $start->getTimestamp(), '>=')
      ->execute();
    $sold_items = [];
    $gold = 0.0;
    foreach ($listings->loadMultiple($sold_ids) as $listing) {
      $sold_items[$listing->get('item')->target_id] = TRUE;
      $gold += (float) $listing->get('sold_price')->value;
    }
    $listed = (int) $listings->getQuery()->accessCheck(FALSE)
      ->condition('date_posted', $start->format('Y-m-d'), '>=')
      ->count()->execute();
    return [
      'title' => $this->t('This season'),
      'since' => $this->t('since @date', ['@date' => $start->format('M j')]),
      'cells' => [
        ['label' => $this->t('Sold'), 'value' => number_format(count($sold_items))],
        ['label' => $this->t('Listed'), 'value' => number_format($listed)],
        ['label' => $this->t('Gold struck'), 'value' => '$' . number_format($gold)],
      ],
    ];
  }

  /**
   * My office: the season settings and game days (the rest is coming).
   */
  public function myOffice(): array {
    $build = $this->room('my-office');
    return [
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
