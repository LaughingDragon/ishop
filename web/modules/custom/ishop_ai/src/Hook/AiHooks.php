<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ishop_ai\AiStatus;

/**
 * Hooks for the AI key: its reminder email and its status.json section.
 */
final class AiHooks {

  public function __construct(
    private readonly AiStatus $status,
  ) {}

  /**
   * Implements hook_mail(): the key expiry reminder.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key === 'key_reminder') {
      $message['subject'] = $params['subject'];
      $message['body'][] = $params['body'];
    }
  }

  /**
   * Implements hook_ishop_office_status_alter(): the "ai" section.
   *
   * Configured, expiry, days left, last success, paused: no key material.
   */
  #[Hook('ishop_office_status_alter')]
  public function statusAlter(array &$data): void {
    $data['ai'] = AiStatus::summary($this->status->get(), AiStatus::today());
  }

}
