<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ishop_items\OffsiteReminder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks the 10-item off-server backup reminder after items are added.
 */
final class OffsiteReminderHooks {

  public function __construct(
    private readonly OffsiteReminder $reminder,
    #[Autowire(service: 'logger.channel.ishop_items')]
    private readonly LoggerInterface $logger,
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
