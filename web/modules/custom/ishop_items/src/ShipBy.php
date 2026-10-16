<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Ship-by dates (Phase 10 A), as pure rules.
 *
 * A sale gets an estimated ship-by date from the platform's usual handling
 * time (config ishop_items.shipping:handling_days) until a real one is known:
 * typed by Cody, or read from the marketplace's email (Phase 10 B) or eBay
 * (Phase 10 C).
 */
final class ShipBy {

  /**
   * Days to ship when a platform has no setting.
   */
  public const FALLBACK_DAYS = 3;

  /**
   * The estimated ship-by date (Y-m-d) for a sale on $date_sold.
   */
  public static function estimate(string $date_sold, int $days): string {
    return (new \DateTimeImmutable($date_sold))->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
  }

  /**
   * How urgent a ship-by date is today.
   *
   * @return array{key: string, days: int}
   *   key is late, today, tomorrow or later; days from today (negative when
   *   late).
   */
  public static function status(string $ship_by, string $today): array {
    $days = Money::daysBetween($today, $ship_by);
    return [
      'key' => match (TRUE) {
        $days < 0 => 'late',
        $days === 0 => 'today',
        $days === 1 => 'tomorrow',
        default => 'later',
      },
      'days' => $days,
    ];
  }

  /**
   * Plain words: "Ship today", "2 days late", "Ship by Mon Oct 19".
   */
  public static function words(string $ship_by, string $today): string {
    $status = self::status($ship_by, $today);
    return match ($status['key']) {
      'late' => $status['days'] === -1 ? '1 day late' : sprintf('%d days late', -$status['days']),
      'today' => 'Ship today',
      'tomorrow' => 'Ship by tomorrow',
      default => 'Ship by ' . (new \DateTimeImmutable($ship_by))->format('D M j'),
    };
  }

}
