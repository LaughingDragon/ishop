<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Drush\Commands;

use Drupal\ishop_ai\AiKeyManager;
use Drupal\ishop_ai\AiStatus;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * AI key commands (the reminder runs daily at 07:05 Pacific).
 */
final class AiCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly AiKeyManager $keys,
    private readonly AiStatus $status,
  ) {
    parent::__construct();
  }

  /**
   * Sends the AI key expiry reminder to Cody if one is due (7, 2, 0 days).
   */
  #[CLI\Command(name: 'ishop:ai-reminders')]
  #[CLI\Option(name: 'check', description: 'Only say what is due; send nothing.')]
  public function reminders(array $options = ['check' => FALSE]): int {
    $status = $this->status->get();
    if ($options['check']) {
      $due = AiStatus::reminderDue($status, AiStatus::today());
      $this->logger()->notice(json_encode(AiStatus::summary($status, AiStatus::today()) + ['reminder_due' => $due]));
      return self::EXIT_SUCCESS;
    }
    $this->logger()->notice($this->keys->sendReminderIfDue());
    return self::EXIT_SUCCESS;
  }

}
