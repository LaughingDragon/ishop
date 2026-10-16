<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Drush\Commands;

use Drupal\ishop_matrix\Digest;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The daily digest command (run at 07:00 Pacific by ishop-digest.timer).
 */
final class DigestCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Digest $digest,
  ) {
    parent::__construct();
  }

  /**
   * Sends Cody's morning digest if it's due, or a test, or shows it.
   */
  #[CLI\Command(name: 'ishop:digest')]
  #[CLI\Option(name: 'test', description: 'Send it to webmaster only, marked [TEST].')]
  #[CLI\Option(name: 'show', description: 'Print today\'s digest without sending it.')]
  #[CLI\Option(name: 'check', description: 'Only say whether Cody\'s would send now.')]
  #[CLI\Option(name: 'enable-cody', description: 'Switch Cody\'s daily digest on (only after Liam\'s OK).')]
  #[CLI\Option(name: 'disable-cody', description: 'Switch Cody\'s daily digest off.')]
  public function digest(array $options = ['test' => FALSE, 'show' => FALSE, 'check' => FALSE, 'enable-cody' => FALSE, 'disable-cody' => FALSE]): int {
    if ($options['enable-cody'] || $options['disable-cody']) {
      $this->digest->setCody((bool) $options['enable-cody']);
      $this->logger()->success($options['enable-cody'] ? 'Cody\'s daily digest is on.' : 'Cody\'s daily digest is off.');
      return self::EXIT_SUCCESS;
    }
    if ($options['show']) {
      $mail = $this->digest->buildFor(Digest::OWNER_UID);
      $this->output()->writeln($mail['subject'] . "\n\n" . $mail['body']);
      return self::EXIT_SUCCESS;
    }
    if ($options['test']) {
      $ok = $this->digest->sendTest();
      $ok ? $this->logger()->success('Test sent to webmaster only.') : $this->logger()->error('The test could not be sent.');
      return $ok ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }
    if ($options['check']) {
      $why = $this->digest->blocker();
      $this->logger()->notice($why ? 'Would not send: ' . $why . '.' : 'Would send now.');
      return self::EXIT_SUCCESS;
    }
    $this->logger()->notice($this->digest->sendIfDue());
    return self::EXIT_SUCCESS;
  }

}
