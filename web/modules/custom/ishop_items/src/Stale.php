<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Stale-listing nudges (Phase 3): posted a while and still not sold.
 *
 * Worked out from each listing's "Date posted" whenever a page is shown, so
 * no cron job or ECA rule is needed (like the sold-elsewhere alarm). The
 * steps are site defaults (2026-10-15), not platform rules: change them here.
 */
final class Stale {

  /**
   * Nudges, most urgent first: [days posted, price cut or NULL, label].
   *
   * NULL means no price cut: suggest donating instead (Liam, 2026-10-15:
   * about 10% off at 30 days, "consider donating" after 90).
   */
  public const STEPS = [
    [90, NULL, 'Up 90+ days: consider donating it'],
    [30, 0.10, 'Up 30+ days: try about 10% off'],
  ];

  /**
   * The first step's days: anything newer is not stale.
   */
  public const FIRST_DAYS = 30;

  /**
   * Listing statuses that count as live on the platform.
   */
  public const LIVE = [Platforms::POSTED, Platforms::AT_RISK];

  /**
   * Item statuses that are finished: no nudges (same as the dashboard's).
   */
  public const ITEM_DONE = ['sold', 'donated', 'kept'];

  /**
   * Today's date in the site's (Pacific) time zone, Y-m-d.
   */
  public static function today(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
  }

  /**
   * The nudge for one listing, or NULL if it's not stale.
   *
   * @param string $posted
   *   Date posted, Y-m-d.
   * @param string $today
   *   Today, Y-m-d (Pacific).
   * @param float|null $base
   *   The price cuts are worked out from: the item's asking price (else the
   *   listing's price), so cuts don't compound.
   * @param float|null $current
   *   The listing's price now, if known.
   *
   * @return array{days: int, step: int, label: string, cut: ?float, suggested: ?int, donate: bool, lowered: bool}|null
   *   Days posted, the nudge, the suggested whole-dollar price, and whether
   *   the price is already at or below it (nothing to do until the next step).
   */
  public static function nudge(string $posted, string $today, ?float $base, ?float $current = NULL): ?array {
    $days = (int) (new \DateTimeImmutable($posted))->diff(new \DateTimeImmutable($today))->format('%r%a');
    foreach (self::STEPS as [$after, $cut, $label]) {
      if ($days >= $after) {
        $suggested = NULL;
        if ($cut !== NULL && $base !== NULL && $base > 0) {
          $suggested = max(1, (int) floor($base * (1 - $cut)));
        }
        return [
          'days' => $days,
          'step' => $after,
          'label' => $label,
          'cut' => $cut,
          'suggested' => $suggested,
          'donate' => $cut === NULL,
          'lowered' => $suggested !== NULL && $current !== NULL && $current <= $suggested,
        ];
      }
    }
    return NULL;
  }

  /**
   * One line for people: "Up 42 days: try 10% off ($27)".
   */
  public static function line(array $nudge): string {
    if ($nudge['lowered']) {
      return sprintf('Up %d days: price already lowered, nothing to do for now', $nudge['days']);
    }
    $text = preg_replace('/^Up \d+\+ days/', 'Up ' . $nudge['days'] . ' days', $nudge['label']);
    return $nudge['suggested'] !== NULL ? sprintf('%s ($%d)', $text, $nudge['suggested']) : $text;
  }

}
