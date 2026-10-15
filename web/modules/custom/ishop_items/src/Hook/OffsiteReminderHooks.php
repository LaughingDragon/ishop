<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_items\OffsiteReminder;
use Drupal\ishop_items\Stale;
use Drupal\ishop_items\StaleNudges;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron and item hooks: the 10-item backup reminder, daily nudge clean-up.
 */
final class OffsiteReminderHooks {

  public function __construct(
    private readonly OffsiteReminder $reminder,
    #[Autowire(service: 'logger.channel.ishop_items')]
    private readonly LoggerInterface $logger,
    private readonly StaleNudges $nudges,
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for nodes.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if ($node->bundle() === 'item') {
      $this->check();
    }
  }

  /**
   * Implements hook_cron(): a second chance if the insert check failed.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->check();
    // Once a day: forget nudge answers for listings no longer live.
    $today = Stale::today();
    if ($this->state->get('ishop_items.stale_cleanup') !== $today) {
      $this->state->set('ishop_items.stale_cleanup', $today);
      $this->nudges->cleanUp();
    }
  }

  /**
   * Runs the check; never lets a problem here break saving an item.
   */
  private function check(): void {
    try {
      if ($nid = $this->reminder->check()) {
        $this->logger->notice('Opened punch item @nid: turn on off-server backups (10 items reached).', ['@nid' => $nid]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Off-server backup reminder failed: @message', ['@message' => $e->getMessage()]);
    }
  }

}
