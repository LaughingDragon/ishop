<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Reads and writes the crew-on-site beacon (see Crew for the rules).
 */
final class CrewState {

  use StringTranslationTrait;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Crew arrives (or switches task). The text is cleaned by Crew::cleanTask().
   */
  public function on(string $text): array {
    $now = $this->time->getCurrentTime();
    $old = $this->raw();
    $was = Crew::view($old, $now);
    $task = Crew::cleanTask($text);
    $new = [
      'active' => TRUE,
      'task' => $task,
      'since' => $was['active'] ? $was['since'] : $now,
      'heartbeat' => $now,
    ];
    $this->state->set(Crew::STATE_KEY, $new);
    if (!$was['active'] || $was['task'] !== $task) {
      $this->invalidator->invalidateTags([Crew::CACHE_TAG]);
    }
    return Crew::view($new, $now);
  }

  /**
   * Crew still working. Does nothing when the crew is off.
   */
  public function heartbeat(): array {
    $now = $this->time->getCurrentTime();
    $old = $this->raw();
    if (empty($old['active'])) {
      return Crew::view($old, $now);
    }
    $was = Crew::view($old, $now);
    $new = ['heartbeat' => $now] + $old;
    if (!$was['active']) {
      // Back after going stale: a new visit.
      $new['since'] = $now;
    }
    $this->state->set(Crew::STATE_KEY, $new);
    if (!$was['active']) {
      $this->invalidator->invalidateTags([Crew::CACHE_TAG]);
    }
    return Crew::view($new, $now);
  }

  /**
   * Crew leaves. The task and times stay, for "last crew activity".
   */
  public function off(): array {
    $now = $this->time->getCurrentTime();
    $old = $this->raw();
    if (!empty($old['active'])) {
      $old['active'] = FALSE;
      $this->state->set(Crew::STATE_KEY, $old);
      $this->invalidator->invalidateTags([Crew::CACHE_TAG]);
    }
    return Crew::view($old, $now);
  }

  /**
   * The current state as shown.
   */
  public function get(): array {
    return Crew::view($this->raw(), $this->time->getCurrentTime());
  }

  /**
   * Whether answers are locked right now.
   */
  public function isActive(): bool {
    return $this->get()['active'];
  }

  /**
   * Cacheability for anything that shows the beacon.
   */
  public function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->setCacheTags([Crew::CACHE_TAG])
      ->setCacheMaxAge(Crew::maxAge($this->raw(), $this->time->getCurrentTime()));
  }

  /**
   * The header beacon: a link to the crew page with a text label.
   */
  public function beacon(): array {
    $crew = $this->get();
    $build = [
      '#type' => 'inline_template',
      '#template' => '<a class="crew-beacon crew-beacon--{{ state }}" href="{{ url }}" title="{{ hint }}"><span class="crew-beacon__tile" aria-hidden="true"><svg class="crew-beacon__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M7 18v-5a5 5 0 0 1 10 0v5"/><path d="M5 18h14v3H5z"/><path d="M12 2v2M4.9 5.4l1.4 1.4M19.1 5.4l-1.4 1.4M2 11.5h2M20 11.5h2"/></svg></span><span class="crew-beacon__label">{{ label }}</span></a>',
      '#context' => [
        'state' => $crew['active'] ? 'on' : 'off',
        'url' => Url::fromRoute('ishop_office.crew')->toString(),
        'label' => $crew['active'] ? $this->t('Crew on site') : $this->t('Site quiet'),
        'hint' => $crew['active']
          ? $this->t('Working on: @task (since @since)', ['@task' => $crew['task'], '@since' => $this->format($crew['since'])])
          : $this->quietText($crew),
      ],
      '#attached' => ['library' => ['ishop_office/crew']],
    ];
    $this->cacheability()->applyTo($build);
    return $build;
  }

  /**
   * "Site quiet, last crew activity <time>".
   */
  public function quietText(array $crew): string {
    return $crew['heartbeat']
      ? (string) $this->t('Site quiet, last crew activity @time', ['@time' => $this->format($crew['heartbeat'])])
      : (string) $this->t('Site quiet');
  }

  /**
   * A Unix time as "2026-10-15 14:05" in the site's timezone.
   */
  public function format(?int $time): string {
    return $time ? $this->dateFormatter->format($time, 'custom', 'Y-m-d H:i') : '-';
  }

  /**
   * The stored state, or NULL.
   */
  private function raw(): ?array {
    $value = $this->state->get(Crew::STATE_KEY);
    return is_array($value) ? $value : NULL;
  }

}
