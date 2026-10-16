<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\Plugin\Mail\SymfonyMailer;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Core's Symfony mailer (Gmail SMTP here), recording each send's result.
 *
 * The last result (time, success, which kind of email) goes to Drupal State
 * for status.json and the site office board. The recipient is not stored.
 */
#[Mail(
  id: 'ishop_mail',
  label: new TranslatableMarkup('iShop mail (Symfony mailer with status)'),
)]
final class IshopMail extends SymfonyMailer {

  /**
   * State key of the last send's result.
   */
  public const STATE_LAST = 'ishop_office.mail_last';

  /**
   * Whether an SMTP error means the login was refused (Gmail answers 535).
   */
  public static function isAuthFailure(string $error): bool {
    return (bool) preg_match('/\b535\b|Failed to authenticate|Username and Password not accepted|authentication failed/i', $error);
  }

  /**
   * The "Needs you now" line about email, or NULL.
   *
   * Only when the last send failed because the login was refused.
   */
  public static function problem(): ?string {
    $last = \Drupal::state()->get(self::STATE_LAST);
    return is_array($last) && empty($last['ok']) && !empty($last['auth_failed'])
      ? 'Email stopped working: renew the app password.'
      : NULL;
  }

  /**
   * State key of the last successful send's time.
   */
  public const STATE_LAST_OK = 'ishop_office.mail_last_ok';

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    // Core logs the failure and returns FALSE; watch what it logs to tell an
    // authentication failure (a revoked or changed app password) from
    // anything else. Only a yes/no is kept: never the message, which names
    // the account.
    $logger = $this->logger;
    $watch = new class($logger) extends AbstractLogger {

      /**
       * Whether a logged failure looked like a refused login.
       */
      public bool $authFailed = FALSE;

      public function __construct(private LoggerInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $text = strtr((string) $message, array_map(fn ($v) => is_scalar($v) || $v instanceof \Stringable ? (string) $v : '', $context));
        if (IshopMail::isAuthFailure($text)) {
          $this->authFailed = TRUE;
        }
        $this->inner->log($level, $message, $context);
      }

    };
    $this->logger = $watch;
    try {
      $ok = (bool) parent::mail($message);
    }
    finally {
      $this->logger = $logger;
    }
    $now = \Drupal::time()->getRequestTime();
    $state = \Drupal::state();
    $state->set(self::STATE_LAST, [
      'time' => date('c', $now),
      'ok' => $ok,
      'kind' => (string) ($message['id'] ?? ''),
      'auth_failed' => !$ok && $watch->authFailed,
    ]);
    if ($ok) {
      $state->set(self::STATE_LAST_OK, date('c', $now));
    }
    return $ok;
  }

}
