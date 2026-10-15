<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Stale;
use Drupal\ishop_items\StaleNudges;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Stale listings: posted 30+ days and still not sold (Phase 3).
 */
final class StaleController extends ControllerBase {

  public function __construct(
    private readonly ListingManager $listings,
    private readonly StaleNudges $nudges,
  ) {}

  /**
   * The sentence for one nudge, as "Needs you now" and this page show it.
   */
  public static function text(array $row): string {
    $nudge = $row['nudge'];
    $args = [
      '@item' => $row['item']->label(),
      '@platform' => Platforms::PLATFORMS[$row['listing']->getPlatform()],
      '@days' => $nudge['days'],
    ];
    if ($nudge['donate']) {
      return (string) t('@item (@platform): up @days days without selling. Consider donating it.', $args);
    }
    if ($nudge['suggested'] !== NULL) {
      return (string) t('@item (@platform): up @days days without selling. Try $@price (about 10% off).', $args + ['@price' => $nudge['suggested']]);
    }
    return (string) t('@item (@platform): up @days days without selling. Try about 10% off.', $args);
  }

  /**
   * The Done / Snooze 2 weeks / Keep as is buttons for one nudge.
   *
   * Links with a CSRF token; they come back to $return.
   */
  public static function buttons(PlatformListing $listing, string $return): array {
    $buttons = [];
    foreach (StaleNudges::ACTIONS as $answer => $label) {
      $buttons[] = [
        'label' => t($label),
        'url' => Url::fromRoute('ishop_items.stale_answer', ['platform_listing' => $listing->id(), 'answer' => $answer], ['query' => ['destination' => $return]])->toString(),
        'ghost' => $answer !== StaleNudges::DONE,
      ];
    }
    return $buttons;
  }

  /**
   * The full list: everything that needs Cody, then already-lowered ones.
   */
  public function page(): array {
    $return = Url::fromRoute('ishop_items.stale')->toString();
    $todo = [];
    foreach ($this->nudges->due() as $row) {
      $todo[] = [
        'text' => self::text($row),
        'url' => $row['item']->toUrl('canonical', ['fragment' => 'listings'])->toString(),
        'posted' => (string) $row['listing']->get('date_posted')->value,
        'donate' => $row['nudge']['donate'],
        'buttons' => self::buttons($row['listing'], $return),
      ];
    }
    $lowered = [];
    foreach ($this->listings->staleListings() as $row) {
      if ($row['nudge']['lowered']) {
        $lowered[] = [
          'title' => $row['item']->label(),
          'url' => $row['item']->toUrl('canonical', ['fragment' => 'listings'])->toString(),
          'line' => Stale::line($row['nudge']),
          'platform' => Platforms::PLATFORMS[$row['listing']->getPlatform()],
        ];
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="ishop-stale-page"><p>{{ intro }}</p>
{% for e in todo %}<div class="rm-card ishop-stale-card{{ e.donate ? \' ishop-stale-card--donate\' }}"><p class="ishop-stale-card__line"><a href="{{ e.url }}">{{ e.text }}</a></p><p class="ishop-stale-card__meta">{{ posted_label }} {{ e.posted }}</p><p class="ishop-stale-card__buttons">{% for b in e.buttons %}<a class="rm-button{{ b.ghost ? \' rm-button--ghost\' }}" href="{{ b.url }}">{{ b.label }}</a> {% endfor %}</p></div>
{% else %}<p><strong>{{ nothing }}</strong></p>{% endfor %}
{% if lowered %}<h2>{{ lowered_title }}</h2><ul>{% for e in lowered %}<li><a href="{{ e.url }}">{{ e.title }}</a> ({{ e.platform }}): {{ e.line }}</li>{% endfor %}</ul>{% endif %}
<p class="ishop-bulk__note">{{ how }}</p></div>',
      '#context' => [
        'intro' => $this->t('Listings up @days days or more without selling, longest first.', ['@days' => Stale::FIRST_DAYS]),
        'todo' => $todo,
        'posted_label' => $this->t('Posted'),
        'nothing' => $this->t('Nothing needs a nudge right now.'),
        'lowered_title' => $this->t('Already lowered'),
        'lowered' => $lowered,
        'how' => $this->t('"Done" records the new price on that listing (change it on the platform too), or for "consider donating" marks the item Donated and asks for its listings to come down. "Snooze 2 weeks" hides it for 14 days. "Keep as is" hides it until the next step.'),
      ],
      '#attached' => ['library' => ['ishop_items/post']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Done, Snooze 2 weeks or Keep as is.
   */
  public function answer(PlatformListing $platform_listing, string $answer): RedirectResponse {
    $back = $this->redirect('ishop_items.stale');
    $nudge = $this->listings->nudge($platform_listing);
    $item = $platform_listing->getItem();
    if (!$nudge || !$item) {
      $this->messenger()->addStatus($this->t('That listing no longer needs a nudge.'));
      return $back;
    }
    $args = [
      '@item' => $item->label(),
      '@platform' => Platforms::PLATFORMS[$platform_listing->getPlatform()],
      '@price' => $nudge['suggested'],
    ];
    if ($answer === StaleNudges::DONE && $nudge['donate']) {
      $down = $this->listings->finishItem($item, 'donated', 'Donated (stale-listing nudge).');
      $args['@list'] = implode(', ', $down);
      $this->messenger()->addStatus($down
        ? $this->t('@item is marked Donated. Take it down from @list: it is in "Needs you now".', $args)
        : $this->t('@item is marked Donated.', $args));
    }
    elseif ($answer === StaleNudges::DONE && $nudge['suggested'] !== NULL) {
      $platform_listing->set('listed_price', $nudge['suggested']);
      $this->listings->saveRevision($platform_listing, sprintf('Price lowered to $%d (stale-listing nudge).', $nudge['suggested']));
      $this->messenger()->addStatus($this->t('Recorded: @item on @platform is now $@price.', $args));
    }
    elseif ($answer === StaleNudges::SNOOZE) {
      $this->messenger()->addStatus($this->t('Snoozed for 2 weeks: @item on @platform.', $args));
    }
    else {
      $this->messenger()->addStatus($nudge['donate']
        ? $this->t('Kept as is: no more nudges for @item on @platform.', $args)
        : $this->t('Kept as is: @item on @platform gets one more nudge, at 90 days.', $args));
    }
    $this->nudges->answer($platform_listing, $answer, $nudge);
    return $back;
  }

}
