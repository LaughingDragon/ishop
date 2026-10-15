<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\Plugin\Mail\SymfonyMailer;
use Drupal\Core\StringTranslation\TranslatableMarkup;

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
   * State key of the last successful send's time.
   */
  public const STATE_LAST_OK = 'ishop_office.mail_last_ok';

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $ok = (bool) parent::mail($message);
    $now = \Drupal::time()->getRequestTime();
    $state = \Drupal::state();
    $state->set(self::STATE_LAST, [
      'time' => date('c', $now),
      'ok' => $ok,
      'kind' => (string) ($message['id'] ?? ''),
    ]);
    if ($ok) {
      $state->set(self::STATE_LAST_OK, date('c', $now));
    }
    return $ok;
  }

}
