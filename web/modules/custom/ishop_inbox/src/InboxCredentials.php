<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

use Drupal\ishop_office\Form\MailSetupForm;

/**
 * The inbox's Gmail address and app password, kept like the mail ones.
 *
 * Files in the secrets folder (outside the web root, owner and group
 * only). Cody saves the app password on Mail setup; it is never shown
 * again, and "Turn off" deletes it. A separate app password from the one
 * that sends mail, so either can be revoked on its own.
 */
final class InboxCredentials {

  public const ADDRESS = 'inbox_address.txt';
  public const PASSWORD = 'inbox_app_password.txt';

  /**
   * The saved address ('' when none).
   */
  public static function address(): string {
    return self::read(self::ADDRESS);
  }

  /**
   * Whether an app password is saved.
   */
  public static function hasPassword(): bool {
    return self::read(self::PASSWORD) !== '';
  }

  /**
   * The app password ('' when none). Only for logging in.
   */
  public static function password(): string {
    return self::read(self::PASSWORD);
  }

  /**
   * Saves both. FALSE if a file couldn't be written.
   */
  public static function save(string $address, string $password): bool {
    return self::write(self::ADDRESS, $address) && self::write(self::PASSWORD, $password);
  }

  /**
   * Deletes the app password (the inbox stops).
   */
  public static function forget(): void {
    @unlink(MailSetupForm::DIR . '/' . self::PASSWORD);
  }

  /**
   * Reads one file, trimmed.
   */
  private static function read(string $name): string {
    $path = MailSetupForm::DIR . '/' . $name;
    return is_readable($path) ? trim((string) file_get_contents($path)) : '';
  }

  /**
   * Writes a secrets file atomically, readable by owner and group only.
   */
  private static function write(string $name, string $value): bool {
    $tmp = @tempnam(MailSetupForm::DIR, '.tmp-');
    if ($tmp === FALSE) {
      return FALSE;
    }
    $ok = file_put_contents($tmp, $value . "\n") !== FALSE && chmod($tmp, 0640) && rename($tmp, MailSetupForm::DIR . '/' . $name);
    if (!$ok) {
      @unlink($tmp);
    }
    return $ok;
  }

}
