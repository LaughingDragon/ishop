<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Drush\Commands;

use Drupal\ishop_inbox\Inbox;
use Drupal\ishop_items\Platforms;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Marketplace inbox commands (Phase 10 B).
 */
final class InboxCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Inbox $inbox,
  ) {
    parent::__construct();
  }

  /**
   * Reads new marketplace mail (ishop-inbox.timer, every 10 minutes).
   */
  #[CLI\Command(name: 'ishop:inbox-check')]
  public function check(): int {
    $this->logger()->notice($this->inbox->check());
    return self::EXIT_SUCCESS;
  }

  /**
   * Shows what the inbox would do with recent mail. Stores and changes nothing.
   */
  #[CLI\Command(name: 'ishop:inbox-preview')]
  #[CLI\Option(name: 'days', description: 'How many days back.')]
  #[CLI\Option(name: 'subjects', description: 'Show subjects (terminal only; they can name buyers).')]
  public function preview(array $options = ['days' => 30, 'subjects' => FALSE]): int {
    $rows = $this->inbox->preview((int) $options['days']);
    if (isset($rows['error'])) {
      $this->logger()->error($rows['error']);
      return self::EXIT_FAILURE;
    }
    foreach ($rows as $row) {
      $this->output()->writeln(implode(' | ', array_filter([
        date('m-d H:i', $row['received']),
        Platforms::PLATFORMS[$row['platform']] ?? $row['platform'],
        $row['authentic'] ? 'auth ok' : 'NOT AUTH',
        $row['status'] === 'open' ? $row['kind'] : $row['kind'] . ' (' . $row['status'] . ')',
        $row['title'] !== NULL ? 'title: ' . $row['title'] : 'no title',
        $row['price'] !== NULL ? '$' . $row['price'] : NULL,
        $row['ship_by'] !== NULL ? 'ship by ' . $row['ship_by'] : NULL,
        $row['item'] !== NULL ? 'MATCH: ' . $row['item'] : 'no match',
        $row['pdfs'] ? $row['pdfs'] . ' pdf' : NULL,
        $options['subjects'] ? 'subject: ' . $row['subject'] : NULL,
      ])));
    }
    $this->logger()->notice(count($rows) . ' marketplace emails (nothing stored or changed).');
    return self::EXIT_SUCCESS;
  }

  /**
   * Switches the inbox on or off.
   */
  #[CLI\Command(name: 'ishop:inbox-switch')]
  #[CLI\Argument(name: 'state', description: 'on or off')]
  public function switch(string $state): int {
    if (!in_array($state, ['on', 'off'], TRUE)) {
      $this->logger()->error('Use on or off.');
      return self::EXIT_FAILURE;
    }
    $this->inbox->switch($state === 'on');
    $this->logger()->success('Marketplace inbox is ' . $state . '.');
    return self::EXIT_SUCCESS;
  }

}
