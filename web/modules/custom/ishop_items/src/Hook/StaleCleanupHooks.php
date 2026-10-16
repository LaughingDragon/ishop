<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_items\Stale;
use Drupal\ishop_items\StaleNudges;

/**
 * Cron: the daily clean-up of stale-nudge answers.
 */
final class StaleCleanupHooks {

  public function __construct(
    private readonly StaleNudges $nudges,
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    // Once a day: forget nudge answers for listings no longer live.
    $today = Stale::today();
    if ($this->state->get('ishop_items.stale_cleanup') !== $today) {
      $this->state->set('ishop_items.stale_cleanup', $today);
      $this->nudges->cleanUp();
    }
  }

}
