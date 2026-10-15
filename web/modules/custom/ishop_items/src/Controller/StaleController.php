<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Stale;

/**
 * Stale listings: posted 30+ days and still not sold (Phase 3).
 */
final class StaleController extends ControllerBase {

  public function __construct(
    private readonly ListingManager $listings,
  ) {}

  /**
   * The list, longest-waiting first; already-lowered ones at the end.
   */
  public function page(): array {
    $todo = $done = [];
    foreach ($this->listings->staleListings() as $row) {
      $entry = [
        'title' => $row['item']->label(),
        'url' => $row['item']->toUrl('canonical', ['fragment' => 'listings'])->toString(),
        'platform' => Platforms::PLATFORMS[$row['listing']->getPlatform()],
        'posted' => (string) $row['listing']->get('date_posted')->value,
        'line' => Stale::line($row['nudge']),
        'donate' => $row['nudge']['donate'],
      ];
      if ($row['nudge']['lowered']) {
        $done[] = $entry;
      }
      else {
        $todo[] = $entry;
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="ishop-stale-page"><p>{{ intro }}</p>
{% for e in todo %}<div class="rm-card ishop-stale-card{{ e.donate ? \' ishop-stale-card--donate\' }}"><h2><a href="{{ e.url }}">{{ e.title }}</a></h2><p class="ishop-stale-card__meta">{{ e.platform }} · {{ posted_label }} {{ e.posted }}</p><p class="ishop-stale-card__line"><strong>{{ e.line }}</strong></p></div>
{% else %}<p><strong>{{ nothing }}</strong></p>{% endfor %}
{% if done %}<h2>{{ done_title }}</h2><ul>{% for e in done %}<li><a href="{{ e.url }}">{{ e.title }}</a> ({{ e.platform }}): {{ e.line }}</li>{% endfor %}</ul>{% endif %}
<p class="ishop-bulk__note">{{ how }}</p></div>',
      '#context' => [
        'intro' => $this->t('Listings that have been up @days days or more without selling, longest first. Cuts are worked out from the asking price.', ['@days' => Stale::FIRST_DAYS]),
        'todo' => $todo,
        'done' => $done,
        'posted_label' => $this->t('posted'),
        'nothing' => $this->t('Nothing has been up @days days without selling.', ['@days' => Stale::FIRST_DAYS]),
        'done_title' => $this->t('Already lowered'),
        'how' => $this->t('After changing a price on the platform, put the new price in the item\'s Listings ("Listed price") so the site knows. To donate an item, set its status to Donated and take its listings down.'),
      ],
      '#attached' => ['library' => ['ishop_items/post']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
