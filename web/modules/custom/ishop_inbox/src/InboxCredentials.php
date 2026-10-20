<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

use Drupal\ishop_office\Form\MailSetupForm;

/**
 * The inbox's Gmail address and app password.
 *
 * The business Gmail both sends and receives the site's mail, and one
 * Google app password works for both, so the inbox uses the sending
 * address and app password (Mail setup, top part). An inbox-only app
 * password saved on Mail setup's inbox part takes over if there is one.
 * Files in the secrets folder (outside the web root, owner and group
 * only); never shown again.
 */
final class InboxCredentials {

  public const ADDRESS = 'inbox_address.txt';
  public const PASSWORD = 'inbox_app_password.txt';

  /**
   * The saved address ('' when none).
   */
  public static function address(): string {
    return self::ownPassword() ? self::read(self::ADDRESS) : MailSetupForm::savedAddress();
  }

  /**
   * Whether an app password is saved.
   */
  public static function hasPassword(): bool {
    return self::password() !== '';
  }

  /**
   * Whether an inbox-only app password is saved (else the sending one).
   */
  public static function ownPassword(): bool {
    return self::read(self::PASSWORD) !== '';
  }

  /**
   * The app password ('' when none). Only for logging in.
   */
  public static function password(): string {
    return self::ownPassword() ? self::read(self::PASSWORD) : self::read('app_password.txt');
  }

  /**
   * Saves both. FALSE if a file couldn't be written.
   */
  public static function save(string $address, string $password): bool {
    return self::write(self::ADDRESS, $address) && self::write(self::PASSWORD, $password);
  }

  /**
   * Deletes the inbox-only app password (the sending one takes over).
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
