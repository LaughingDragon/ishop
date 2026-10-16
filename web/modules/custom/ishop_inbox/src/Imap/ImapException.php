<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Imap;

/**
 * An IMAP failure. Messages never contain the app password.
 */
final class ImapException extends \RuntimeException {

  public function __construct(
    string $message,
    private readonly bool $authFailed = FALSE,
  ) {
    parent::__construct($message);
  }

  /**
   * Whether Gmail refused the login (the app password needs renewing).
   */
  public function authFailed(): bool {
    return $this->authFailed;
  }

}
