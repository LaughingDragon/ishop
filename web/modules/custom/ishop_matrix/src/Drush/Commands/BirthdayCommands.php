<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Drush\Commands;

use Drupal\ishop_matrix\BirthdayMail;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The birthday email command (TEMPORARY: see TEMPORARY.md).
 */
final class BirthdayCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly BirthdayMail $birthdayMail,
  ) {
    parent::__construct();
  }

  /**
   * Sends Cody's birthday email if today is the day (run at 07:00 Pacific).
   */
  #[CLI\Command(name: 'ishop:birthday-mail')]
  #[CLI\Option(name: 'check', description: 'Only say whether it would send now.')]
  public function mail(array $options = ['check' => FALSE]): int {
    if ($options['check']) {
      $why = $this->birthdayMail->blocker();
      $this->logger()->notice($why ? 'Would not send: ' . $why . '.' : 'Would send now.');
      return self::EXIT_SUCCESS;
    }
    $this->logger()->notice($this->birthdayMail->sendIfDue());
    return self::EXIT_SUCCESS;
  }

}
