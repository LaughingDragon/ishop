<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\ishop_items\Entity\PlatformListing;

/**
 * Cody's answers to stale-listing nudges: Done, Snooze 2 weeks, Keep as is.
 *
 * Kept per listing in a key/value collection (data, not config):
 * listing ID => [action, step (the nudge's days threshold), until (Y-m-d)].
 * Done and Keep as is hide the nudge until its next step (the donate nudge
 * at 90 days); after the last step they hide it for good. Snooze hides it
 * for 14 days.
 */
final class StaleNudges {

  public const COLLECTION = 'ishop_items.stale_nudges';

  public const DONE = 'done';
  public const SNOOZE = 'snooze';
  public const KEEP = 'keep';

  /**
   * Button labels, in the order shown.
   */
  public const ACTIONS = [
    self::DONE => 'Done',
    self::SNOOZE => 'Snooze 2 weeks',
    self::KEEP => 'Keep as is',
  ];

  public const SNOOZE_DAYS = 14;

  /**
   * How many "Needs you now" shows, most overdue first.
   */
  public const DASHBOARD_LIMIT = 3;

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly ListingManager $listings,
  ) {}

  /**
   * The answers store.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValue->get(self::COLLECTION);
  }

  /**
   * Whether an answer hides this nudge today.
   */
  public static function hides(?array $answer, array $nudge, string $today): bool {
    if (!$answer) {
      return FALSE;
    }
    if ($answer['action'] === self::SNOOZE) {
      return $today < $answer['until'];
    }
    // Done or Keep as is: until the nudge moves on to a later step.
    return $nudge['step'] <= $answer['step'];
  }

  /**
   * Stale listings that still need Cody, longest-waiting first.
   *
   * Leaves out answered ones (until the answer runs out) and ones whose
   * price is already at or below the suggestion.
   */
  public function due(?int $limit = NULL): array {
    $today = Stale::today();
    $rows = array_values(array_filter(
      $this->listings->staleListings(),
      fn ($row) => !$row['nudge']['lowered'] && !self::hides($this->store()->get($row['listing']->id()), $row['nudge'], $today),
    ));
    return $limit === NULL ? $rows : array_slice($rows, 0, $limit);
  }

  /**
   * Records an answer for the listing's current nudge.
   */
  public function answer(PlatformListing $listing, string $action, array $nudge): void {
    $this->store()->set($listing->id(), [
      'action' => $action,
      'step' => $nudge['step'],
      'until' => (new \DateTimeImmutable(Stale::today()))->modify('+' . self::SNOOZE_DAYS . ' days')->format('Y-m-d'),
    ]);
  }

  /**
   * Daily clean-up: forget answers for listings that are gone or no longer live.
   *
   * @return int
   *   How many answers were forgotten.
   */
  public function cleanUp(): int {
    $store = $this->store();
    $ids = array_keys($store->getAll());
    if (!$ids) {
      return 0;
    }
    $live = $this->listings->liveListingIds($ids);
    $gone = array_values(array_diff($ids, $live));
    if ($gone) {
      $store->deleteMultiple($gone);
    }
    return count($gone);
  }

}
