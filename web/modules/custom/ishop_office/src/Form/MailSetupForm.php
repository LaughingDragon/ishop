<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Lets Cody save the Gmail address and app password the site sends mail with.
 *
 * Both go only into files under secrets/mail (read by settings.php); they are
 * never stored in config, git or the database, never shown again, and never
 * logged. Saving sends a test email so Cody sees at once whether it works.
 */
final class MailSetupForm extends FormBase {

  /**
   * Where the address and app password live.
   */
  public const DIR = '/var/www/ishop/secrets/mail';

  /**
   * Accounts allowed to set this up.
   */
  public const ACCOUNTS = ['theowner', 'webmaster'];

  /**
   * Access: Cody and the webmaster only.
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(in_array($account->getAccountName(), self::ACCOUNTS, TRUE))->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_office_mail_setup';
  }

  /**
   * The saved address, or ''.
   */
  public static function savedAddress(): string {
    $file = self::DIR . '/address.txt';
    return is_readable($file) ? trim((string) file_get_contents($file)) : '';
  }

  /**
   * Whether an app password is saved (its value is never read for display).
   */
  public static function hasPassword(): bool {
    return is_file(self::DIR . '/app_password.txt') && filesize(self::DIR . '/app_password.txt') > 0;
  }

  /**
   * A 16-letter Google app password, with spaces removed, or NULL.
   */
  public static function normalizePassword(string $value): ?string {
    $clean = strtolower((string) preg_replace('/\s+/', '', $value));
    return preg_match('/^[a-z]{16}$/', $clean) ? $clean : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $address = self::savedAddress();
    $set_up = $address !== '' && self::hasPassword();
    $form['state'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if not set_up %} so-callout--warn{% endif %}"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p></div></section>',
      '#context' => [
        'set_up' => $set_up,
        'title' => $set_up ? $this->t('Mail is set up') : $this->t('Mail is not set up yet'),
        'text' => $set_up
          ? $this->t('The site sends email as @address. To change the address or app password, save new ones below.', ['@address' => $address])
          : $this->t("Right now the site can't send any email, not even password resets."),
      ],
    ];
    $form['address'] = [
      '#type' => 'email',
      '#title' => $this->t('Gmail address'),
      '#description' => $this->t('The Gmail account the site sends from. Type it carefully.'),
      '#default_value' => $address,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['app_password'] = [
      '#type' => 'password',
      '#title' => $this->t('App password from Google'),
      '#description' => $this->t('The 16 letters Google showed you (spaces are fine). It is saved privately on the server and never shown again. Not your normal Gmail password.'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and send a test email'),
      '#button_type' => 'primary',
    ];
    if ($set_up) {
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Turn off mail'),
        '#submit' => ['::remove'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['button--danger']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $address = strtolower(trim((string) $form_state->getValue('address')));
    if (!preg_match('/^[a-z0-9.+_-]+@gmail\.com$/', $address)) {
      $form_state->setErrorByName('address', $this->t('Use the full Gmail address, ending in @gmail.com.'));
    }
    if (self::normalizePassword((string) $form_state->getValue('app_password')) === NULL) {
      $form_state->setErrorByName('app_password', $this->t("That doesn't look like a Google app password: it should be 16 letters (Google shows them in four groups of four)."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $address = strtolower(trim((string) $form_state->getValue('address')));
    $password = self::normalizePassword((string) $form_state->getValue('app_password'));
    // Drop the typed value from the form state as soon as possible.
    $form_state->setValue('app_password', NULL);

    if (!$this->write('address.txt', $address) || !$this->write('app_password.txt', $password)) {
      $this->messenger()->addError($this->t('Could not save on the server. Nothing was changed; tell site-foreman.'));
      return;
    }

    $error = $this->sendTest($address, $password);
    unset($password);
    if ($error === NULL) {
      $this->messenger()->addStatus($this->t('Saved. A test email was sent to @address: check that inbox.', ['@address' => $address]));
      $this->logger('ishop_office')->notice('Mail setup saved by @user; test email sent.', ['@user' => $this->currentUser()->getAccountName()]);
    }
    else {
      $this->messenger()->addError($this->t('Saved, but Google refused the test email: @error. Check the address and create a new app password, then save again.', ['@error' => $error]));
      $this->logger('ishop_office')->warning('Mail setup saved by @user; test email failed: @error', [
        '@user' => $this->currentUser()->getAccountName(),
        '@error' => $error,
      ]);
    }
  }

  /**
   * Turns mail off: removes the saved address and app password.
   */
  public function remove(array &$form, FormStateInterface $form_state): void {
    @unlink(self::DIR . '/app_password.txt');
    @unlink(self::DIR . '/address.txt');
    $this->messenger()->addStatus($this->t('Mail turned off: the app password was deleted from the server.'));
    $this->logger('ishop_office')->notice('Mail setup removed by @user.', ['@user' => $this->currentUser()->getAccountName()]);
  }

  /**
   * Writes a secrets file atomically, readable by owner and group only.
   */
  private function write(string $name, string $value): bool {
    $tmp = @tempnam(self::DIR, '.tmp-');
    if ($tmp === FALSE) {
      return FALSE;
    }
    $ok = file_put_contents($tmp, $value . "\n") !== FALSE && chmod($tmp, 0640) && rename($tmp, self::DIR . '/' . $name);
    if (!$ok) {
      @unlink($tmp);
    }
    return $ok;
  }

  /**
   * Sends a test email through Gmail with these credentials.
   *
   * @return string|null
   *   NULL on success, or the reason it failed (with the password removed).
   */
  private function sendTest(string $address, string $password): ?string {
    try {
      $transport = Transport::fromDsn(sprintf('smtp://%s:%s@smtp.gmail.com:587', rawurlencode($address), rawurlencode($password)));
      (new Mailer($transport))->send((new Email())
        ->from($address)
        ->to($address)
        ->subject('Reseller Matrix: mail is working')
        ->text("This test email was sent by your site after you saved the Gmail app password.\n\nIf you're reading this, password resets and reminders can reach you now."));
      return NULL;
    }
    catch (\Throwable $e) {
      $message = str_replace([$password, rawurlencode($password)], '***', $e->getMessage());
      return mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, 300);
    }
  }

}
