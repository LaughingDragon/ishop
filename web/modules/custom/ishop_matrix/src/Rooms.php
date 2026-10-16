<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix;

/**
 * The seven rooms of the Reseller Matrix (Cody's questionnaire answers).
 */
final class Rooms {

  /**
   * Slug => [name, icon key (room-icon.html.twig), one-word label, purpose].
   */
  public const ROOMS = [
    'front-desk' => ['Front desk', 'front_desk', 'Desk', 'What needs doing today.'],
    'stockroom' => ['Stockroom', 'stockroom', 'Stock', 'Everything on the game room table.'],
    'shipping-room' => ['Shipping room', 'shipping', 'Ship', 'What to pack and send.'],
    'my-office' => ['My office', 'my_office', 'Office', 'Money, records and decisions.'],
    'mailroom' => ['Mailroom', 'mailroom', 'Mail', 'Messages and reminders.'],
    'calendar-wall' => ['Calendar wall', 'calendar', 'Dates', 'Appointments and bills.'],
    'break-room' => ['Break room', 'break_room', 'Break', 'Personal stuff, not business.'],
  ];

  /**
   * Item statuses that mean "still on the game room table".
   */
  public const ON_HAND = ['draft', 'ready', 'listed'];

  /**
   * Text for a count: one, many, or plainly none.
   */
  public static function count(int $n, string $one, string $many, string $none): string {
    return match (TRUE) {
      $n === 0 => $none,
      $n === 1 => sprintf($one, $n),
      default => sprintf($many, $n),
    };
  }

  /**
   * Joins names: "A", "A and B", "A, B and C".
   */
  public static function andList(array $names): string {
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' and ' . $last : (string) $last;
  }

}
