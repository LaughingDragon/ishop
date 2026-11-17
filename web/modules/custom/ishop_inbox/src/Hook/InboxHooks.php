<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\ishop_inbox\Inbox;

/**
 * Hook implementations for the marketplace inbox.
 */
final class InboxHooks {

  public function __construct(
    private readonly Inbox $inbox,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_ishop_needs(): the open marketplace emails.
   */
  #[Hook('ishop_needs')]
  public function needs(string $return): array {
    // The digest runs as Cody from Drush; the front desk as whoever looks.
    if (!$this->currentUser->hasPermission('manage platform listings')) {
      return [];
    }
    return $this->inbox->needs($return);
  }

  /**
   * Implements hook_ishop_office_status_alter(): Gmail delivery failures.
   */
  #[Hook('ishop_office_status_alter')]
  public function statusAlter(array &$data): void {
    $data['mail_bounces'] = $this->inbox->bounceSummary();
  }


  /**
   * Implements hook_ishop_digest_away(): the inbox's automatic actions.
   */
  #[Hook('ishop_digest_away')]
  public function digestAway(int $since): array {
    return \Drupal::service(Inbox::class)->awayLines($since);
  }

}
