<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\ishop_inbox\Imap\ImapClient;
use Drupal\ishop_inbox\Imap\ImapException;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\Shipping;

/**
 * The marketplace inbox (Phase 10 B).
 *
 * Every 10 minutes (ishop-inbox.timer) reads new emails from the seven
 * marketplaces in the business Gmail, read-only, and turns each into a
 * "Needs you now" row: a sale to confirm, a label to add to its sale, a
 * ship-by date to save, an offer, a buyer message, a return, a case or a
 * payout. Nothing changes an item or a listing until Cody taps the button:
 * a label PDF is only kept with its row (deleted if he says no). Mail with
 * nothing to act on (favorites, promotions, "on its way") is recorded
 * quietly. Each email is read once (its row is the "processed" mark);
 * nothing in the mailbox is changed, sent or deleted.
 */
final class Inbox {

  use StringTranslationTrait;

  /**
   * State key: the last check's outcome (no message text, no password).
   */
  public const STATE = 'ishop_inbox.status';

  /**
   * Emails this many days older than the switch-on are recorded, not shown.
   */
  public const GRACE_DAYS = 3;

  /**
   * State key: delivery-failure notices found in the inbox (crew only).
   */
  public const BOUNCES = 'ishop_inbox.bounces';

  /**
   * Seconds between bounce scans, and how many days back each looks.
   */
  public const BOUNCE_EVERY = 3600;
  public const BOUNCE_DAYS = 14;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly Matcher $matcher,
    private readonly Shipping $shipping,
    private readonly ListingManager $listings,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Reads new marketplace mail (the timer's job). Returns a summary line.
   */
  public function check(): string {
    // Crew monitoring, so it runs even while Cody's inbox is switched off.
    $bounces = $this->scanBounces();
    if (!$this->configFactory->get('ishop_inbox.settings')->get('enabled')) {
      return 'Off: the marketplace inbox is switched off.' . ($bounces ? ' ' . $bounces : '');
    }
    $counts = [];
    $result = $this->read(FALSE, NULL, function (array $row) use (&$counts) {
      $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    });
    if ($result !== NULL) {
      return $result;
    }
    $parts = [];
    foreach ($counts as $status => $n) {
      $parts[] = "$n $status";
    }
    return 'Checked: ' . ($parts ? implode(', ', $parts) : 'nothing new') . '.';
  }

  /**
   * Lists Gmail delivery-failure notices for crew (status.json), at most
   * hourly. Read-only: headers of the returned message only, nothing
   * stored in the inbox table, nothing shown to Cody.
   *
   * @return string
   *   A summary line, or '' when it wasn't due.
   */
  public function scanBounces(bool $force = FALSE): string {
    $last = $this->state->get(self::BOUNCES);
    $now = $this->time->getRequestTime();
    if (!$force && is_array($last) && $now - (int) ($last['at'] ?? 0) < self::BOUNCE_EVERY) {
      return '';
    }
    $address = InboxCredentials::address();
    $password = InboxCredentials::password();
    if ($address === '' || $password === '') {
      return '';
    }
    $found = [];
    try {
      $client = ImapClient::connect();
      $client->login($address, $password);
      unset($password);
      $client->examine();
      foreach ($client->searchGmail(Senders::bounceQuery(self::BOUNCE_DAYS)) as $uid) {
        $raw = $client->fetch($uid);
        if ($raw === NULL) {
          continue;
        }
        $mail = MessageReader::read($raw);
        $found[] = [
          'received' => date('c', $mail['received']),
          'notice' => $mail['subject'],
        ] + Senders::bounceDetails($raw);
      }
      $client->logout();
    }
    catch (ImapException $e) {
      $this->state->set(self::BOUNCES, ['at' => $now, 'ok' => FALSE, 'error' => $e->getMessage(), 'items' => $last['items'] ?? []]);
      return 'Bounce scan failed: ' . $e->getMessage();
    }
    $this->state->set(self::BOUNCES, ['at' => $now, 'ok' => TRUE, 'items' => $found]);
    return sprintf('Delivery-failure notices (last %d days): %d.', self::BOUNCE_DAYS, count($found));
  }

  /**
   * The "mail_bounces" section of status.json.
   */
  public function bounceSummary(): array {
    $last = $this->state->get(self::BOUNCES);
    if (!is_array($last)) {
      return ['checked' => NULL, 'count' => 0, 'items' => []];
    }
    return [
      'checked' => date('c', (int) $last['at']),
      'ok' => $last['ok'],
      'error' => $last['error'] ?? NULL,
      'days' => self::BOUNCE_DAYS,
      'count' => count($last['items']),
      'items' => $last['items'],
    ];
  }

  /**
   * Reads the last $days days without storing or changing anything.
   *
   * @return array
   *   One row per marketplace email (with its subject, for the terminal
   *   only), or ['error' => ...].
   */
  public function preview(int $days): array {
    $rows = [];
    $error = $this->read(TRUE, $days, function (array $row) use (&$rows) {
      $rows[] = $row;
    });
    return $error !== NULL ? ['error' => $error] : $rows;
  }

  /**
   * Tries the login only (Mail setup's test). NULL when it works.
   */
  public function testLogin(string $address, string $password): ?string {
    try {
      $client = ImapClient::connect();
      $client->login($address, $password);
      $client->examine();
      $client->logout();
      return NULL;
    }
    catch (ImapException $e) {
      return $e->getMessage();
    }
  }

  /**
   * Forgets the last check's outcome (a new app password was saved).
   */
  public function resetStatus(): void {
    $this->state->delete(self::STATE);
  }

  /**
   * Switches the inbox on or off.
   *
   * Mail from before switching on is only recorded (GRACE_DAYS before
   * excepted), so old news doesn't flood in.
   */
  public function switch(bool $on): void {
    $config = $this->configFactory->getEditable('ishop_inbox.settings');
    $config->set('enabled', $on);
    if ($on) {
      $config->set('enabled_since', $this->time->getRequestTime());
    }
    $config->save();
  }

  /**
   * The "Needs you now" line about the inbox itself, or NULL.
   */
  public function problem(): ?string {
    $status = $this->state->get(self::STATE);
    if (!$this->configFactory->get('ishop_inbox.settings')->get('enabled')) {
      return NULL;
    }
    if (!InboxCredentials::hasPassword() || (is_array($status) && !empty($status['auth_failed']))) {
      return (string) $this->t('Marketplace emails stopped: renew the inbox app password.');
    }
    return NULL;
  }

  /**
   * Connects, reads, and hands each marketplace email to $each.
   *
   * @return string|null
   *   NULL on success, or why it couldn't read.
   */
  private function read(bool $dry, ?int $days, callable $each): ?string {
    $address = InboxCredentials::address();
    $password = InboxCredentials::password();
    if ($address === '' || $password === '') {
      return 'No inbox app password saved (Mail setup).';
    }
    $days ??= (int) ($this->configFactory->get('ishop_inbox.settings')->get('days') ?: 14);
    try {
      $client = ImapClient::connect();
      $client->login($address, $password);
      unset($password);
      $validity = $client->examine();
      foreach ($client->searchGmail(Senders::gmailQuery($days)) as $uid) {
        $key = $validity . ':' . $uid;
        if (!$dry && $this->known($key)) {
          continue;
        }
        $raw = $client->fetch($uid);
        if ($raw !== NULL) {
          $each($this->process($key, $raw, $dry));
        }
      }
      $client->logout();
      if (!$dry) {
        $this->state->set(self::STATE, ['ok' => TRUE, 'auth_failed' => FALSE, 'at' => $this->time->getRequestTime()]);
      }
      return NULL;
    }
    catch (ImapException $e) {
      if (!$dry) {
        $this->state->set(self::STATE, ['ok' => FALSE, 'auth_failed' => $e->authFailed(), 'at' => $this->time->getRequestTime(), 'error' => $e->getMessage()]);
      }
      return $e->getMessage();
    }
  }

  /**
   * Whether a message was read before.
   */
  private function known(string $key): bool {
    return (bool) $this->database->select('ishop_inbox_message', 'm')->fields('m', ['id'])->condition('mail_key', $key)->execute()->fetchField();
  }

  /**
   * Reads one email, acts on it, records it. Returns its row.
   */
  private function process(string $key, string $raw, bool $dry): array {
    $mail = MessageReader::read($raw);
    $now = $this->time->getRequestTime();
    $row = [
      'mail_key' => $key,
      'message_id' => mb_substr($mail['message_id'], 0, 255),
      'received' => $mail['received'],
      'platform' => Senders::platform($mail['from']) ?? 'unknown',
      'kind' => Classifier::OTHER,
      'title' => NULL,
      'price' => NULL,
      'ship_by' => NULL,
      'listing' => NULL,
      'fid' => NULL,
      'status' => 'open',
      'note' => NULL,
      'created' => $now,
      'changed' => $now,
    ];
    $extra = ['subject' => $mail['subject'], 'pdfs' => count($mail['pdfs']), 'authentic' => FALSE, 'item' => NULL];
    if (Senders::isBounce($mail['from'])) {
      $row['status'] = 'ignored';
      return $this->record($row, $dry, $extra, 'Delivery-failure notice: crew only (status.json).');
    }
    if ($row['platform'] === 'unknown') {
      $row['status'] = 'ignored';
      return $this->record($row, $dry, $extra, 'Not a marketplace sender.');
    }
    $extra['authentic'] = Senders::authentic($mail['auth'], $row['platform']);
    if (!$extra['authentic']) {
      $row['status'] = 'ignored';
      return $this->record($row, $dry, $extra, 'Gmail could not confirm the sender: ignored.');
    }
    $received = (new \DateTimeImmutable('@' . $mail['received']))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
    $c = Classifier::classify($row['platform'], $mail['subject'], $mail['text'], (bool) $mail['pdfs'], $received);
    $row['kind'] = $c['kind'];
    $row['title'] = $c['title'] === NULL ? NULL : mb_substr($c['title'], 0, 255);
    $row['price'] = $c['price'];
    $row['ship_by'] = $c['ship_by'];
    if ($c['kind'] === Classifier::IGNORE) {
      $row['status'] = 'ignored';
      return $this->record($row, $dry, $extra, 'Not about Marketplace.');
    }
    if ($c['kind'] === Classifier::OTHER || ($c['kind'] === Classifier::PAYOUT && $c['price'] === NULL)) {
      $row['status'] = 'ignored';
      return $this->record($row, $dry, $extra, 'Nothing to act on.');
    }
    $listing = $this->matcher->find($row['platform'], $c['kind'], $c['ref'], $c['title']);
    $row['listing'] = $listing?->id();
    $extra['item'] = $listing?->getItem()->label();
    $since = (int) $this->configFactory->get('ishop_inbox.settings')->get('enabled_since');
    if ($since && $mail['received'] < $since - self::GRACE_DAYS * 86400) {
      $row['status'] = 'skipped';
      return $this->record($row, $dry, $extra, 'From before the inbox was switched on.');
    }
    if ($dry) {
      return $this->record($row, TRUE, $extra, NULL);
    }
    $note = $this->act($row, $listing, $mail['pdfs']);
    return $this->record($row, FALSE, $extra, $note);
  }

  /**
   * Prepares the row for Cody's tap; changes no item or listing.
   *
   * Keeps a label PDF with its row (deleted if Cody says no), joins a sale
   * email and its label email into one row, and closes rows that would ask
   * nothing new. Returns a note. May change $row.
   */
  private function act(array &$row, ?PlatformListing $listing, array $pdfs): ?string {
    $sale = $listing && $listing->getStatus() === Platforms::SOLD_HERE ? $listing : NULL;
    $shipped = $sale && !$sale->get('date_shipped')->isEmpty();
    $needs_label = $listing && !$shipped && (!$sale || $sale->get('shipping_label')->isEmpty());
    if ($pdfs && $needs_label && in_array($row['kind'], [Classifier::SOLD, Classifier::LABEL], TRUE)) {
      $row['fid'] = $this->saveLabel($row['platform'], $pdfs[0]['data'])->id();
    }
    $new_date = $sale && !$shipped && $row['ship_by'] !== NULL
      && ($sale->get('ship_by_estimated')->value || (string) $sale->get('ship_by')->value !== $row['ship_by']);

    // Already marked sold: only a label or a new ship-by date is worth asking.
    if ($sale && in_array($row['kind'], [Classifier::SOLD, Classifier::LABEL, Classifier::SHIP_BY], TRUE)) {
      if (!$row['fid'] && !$new_date) {
        $row['status'] = 'done';
        return $row['kind'] === Classifier::SOLD ? 'Already marked sold.' : 'Nothing new for this sale.';
      }
      return NULL;
    }
    if ($row['kind'] === Classifier::SHIP_BY && !$sale) {
      // A ship-by reminder for a sale not marked yet: the sale row asks first.
      return NULL;
    }

    // A sale not marked yet: one question per sale, whichever email came first.
    if ($listing && in_array($row['kind'], [Classifier::SOLD, Classifier::LABEL], TRUE)) {
      $earlier = $this->database->select('ishop_inbox_message', 'm')->fields('m')
        ->condition('status', 'open')
        ->condition('listing', $listing->id())
        ->condition('kind', [Classifier::SOLD, Classifier::LABEL], 'IN')
        ->orderBy('id')->range(0, 1)->execute()->fetchAssoc();
      if ($earlier) {
        $merge = [];
        foreach (['price', 'ship_by', 'fid'] as $field) {
          if ($earlier[$field] === NULL && $row[$field] !== NULL) {
            $merge[$field] = $row[$field];
            if ($field === 'fid') {
              $row['fid'] = NULL;
            }
          }
        }
        if ($merge) {
          $merge['changed'] = $this->time->getRequestTime();
          $this->database->update('ishop_inbox_message')->fields($merge)->condition('id', $earlier['id'])->execute();
        }
        if ($row['fid']) {
          // The earlier row already has a label: this copy isn't needed.
          $this->entityTypeManager->getStorage('file')->load($row['fid'])?->delete();
          $row['fid'] = NULL;
        }
        $row['status'] = 'done';
        return 'Joined with the earlier email about this sale.';
      }
    }
    return NULL;
  }

  /**
   * Cody tapped "Add label" / "Save date" on a sale already marked.
   *
   * @return string
   *   What happened, for the message.
   */
  public function applyToSale(array $row): string {
    $listing = $row['listing'] ? $this->entityTypeManager->getStorage('platform_listing')->load($row['listing']) : NULL;
    if (!$listing instanceof PlatformListing || !$listing->getItem() || $listing->getStatus() !== Platforms::SOLD_HERE) {
      $this->close($row, 'dismissed', 'Not a sale any more.');
      return (string) $this->t('That sale is no longer marked sold, so nothing was changed.');
    }
    $platform = Platforms::PLATFORMS[$listing->getPlatform()];
    $did = [];
    if ($row['ship_by'] !== NULL && $listing->get('date_shipped')->isEmpty()) {
      $this->shipping->setShipBy($listing, $row['ship_by'], sprintf('Ship-by date %s from the %s email (Cody tapped Save).', $row['ship_by'], $platform));
      $did[] = (string) $this->t('ship-by date saved');
      $listing = $this->entityTypeManager->getStorage('platform_listing')->loadUnchanged($listing->id());
    }
    $file = $row['fid'] ? $this->entityTypeManager->getStorage('file')->load($row['fid']) : NULL;
    if ($file instanceof FileInterface && $listing->get('shipping_label')->isEmpty()) {
      $this->shipping->setLabel($listing, $file, sprintf('Shipping label from the %s email (Cody tapped Add label).', $platform));
      $did[] = (string) $this->t('label added');
    }
    $this->close($row, 'done', 'Applied by Cody: ' . ($did ? implode(', ', $did) : 'nothing left to do') . '.');
    return $did
      ? (string) $this->t('@item: @what. It is in the Shipping room.', ['@item' => $listing->getItem()->label(), '@what' => implode(', ', $did)])
      : (string) $this->t('Nothing left to do for that sale.');
  }

  /**
   * Saves a label PDF as a private file (no buyer details in its name).
   */
  private function saveLabel(string $platform, string $data): FileInterface {
    $dir = 'private://shipping-labels/' . date('Y-m');
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $this->fileRepository->writeData($data, sprintf('%s/label-%s-%s.pdf', $dir, $platform, date('Ymd-His')), FileExists::Rename);
  }

  /**
   * Stores the row (unless $dry) and returns it with the extras.
   */
  private function record(array $row, bool $dry, array $extra, ?string $note): array {
    $row['note'] = $note;
    if (!$dry) {
      $row['id'] = $this->database->insert('ishop_inbox_message')->fields($row)->execute();
    }
    return $row + $extra;
  }

  /**
   * Open rows, oldest email first.
   *
   * @return array[]
   *   Rows as arrays.
   */
  public function openRows(): array {
    return $this->database->select('ishop_inbox_message', 'm')->fields('m')
      ->condition('status', 'open')
      ->orderBy('received')
      ->orderBy('id')
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * One row by ID, or NULL.
   */
  public function row(int $id): ?array {
    $row = $this->database->select('ishop_inbox_message', 'm')->fields('m')->condition('id', $id)->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Cody confirmed a sale: Sold here with its cascade, price, date, label.
   *
   * @return string
   *   What happened, for the message.
   */
  public function confirmSale(array $row): string {
    $listing = $row['listing'] ? $this->entityTypeManager->getStorage('platform_listing')->load($row['listing']) : NULL;
    if (!$listing instanceof PlatformListing || !$listing->getItem()) {
      $this->close($row, 'dismissed', 'The listing is gone.');
      return (string) $this->t('That listing no longer exists.');
    }
    $platform = Platforms::PLATFORMS[$listing->getPlatform()];
    if ($listing->getStatus() !== Platforms::SOLD_HERE) {
      if ($row['price'] !== NULL && $listing->get('sold_price')->isEmpty()) {
        $listing->set('sold_price', $row['price']);
      }
      $this->listings->applySale($listing, sprintf('Sold on %s (from the %s email, confirmed by Cody).', $platform, $platform));
    }
    $listing = $this->entityTypeManager->getStorage('platform_listing')->loadUnchanged($listing->id());
    if ($row['ship_by'] !== NULL) {
      $this->shipping->setShipBy($listing, $row['ship_by'], sprintf('Ship-by date %s from the %s email.', $row['ship_by'], $platform));
    }
    $file = $row['fid'] ? $this->entityTypeManager->getStorage('file')->load($row['fid']) : NULL;
    if ($file instanceof FileInterface && $listing->get('shipping_label')->isEmpty()) {
      $this->shipping->setLabel($listing, $file, sprintf('Shipping label from the %s email.', $platform));
    }
    $this->close($row, 'done', 'Sale confirmed by Cody.');
    return (string) $this->t('Marked sold on @platform: @item. It is in the Shipping room.', ['@platform' => $platform, '@item' => $listing->getItem()->label()]);
  }

  /**
   * Closes a row (Done / OK / Not this item); an unused label is deleted.
   */
  public function close(array $row, string $status = 'dismissed', ?string $note = NULL): void {
    if ($row['fid'] && $status !== 'done' && ($file = $this->entityTypeManager->getStorage('file')->load($row['fid']))) {
      $file->delete();
    }
    $this->database->update('ishop_inbox_message')
      ->fields(['status' => $status, 'note' => $note ?? $row['note'], 'changed' => $this->time->getRequestTime(), 'fid' => NULL])
      ->condition('id', $row['id'])
      ->execute();
  }

  /**
   * The Gmail link that opens one message (Cody's own signed-in Gmail).
   */
  public static function gmailUrl(string $message_id): string {
    return 'https://mail.google.com/mail/u/0/#search/' . rawurlencode('rfc822msgid:' . $message_id);
  }

  /**
   * The open emails as "Needs you now" rows (dashboard and digest).
   */
  public function needs(string $return): array {
    $needs = [];
    if ($problem = $this->problem()) {
      $needs[] = [
        'text' => $problem,
        'url' => Url::fromRoute('ishop_office.mail_setup', [], ['fragment' => 'inbox'])->toString(),
        'action' => $this->t('Renew'),
        'ghost' => FALSE,
      ];
    }
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    foreach ($this->openRows() as $row) {
      $listing = $row['listing'] ? $storage->load($row['listing']) : NULL;
      $item = $listing instanceof PlatformListing ? $listing->getItem() : NULL;
      $needs[] = $this->line($row, $item?->label(), $listing, $return);
    }
    return $needs;
  }

  /**
   * A sale already marked: offer the label and/or the ship-by date.
   */
  private function saleUpdate(array $row, array $args, callable $act, array $open): array {
    $text = match (TRUE) {
      $row['fid'] && $row['ship_by'] => $this->t('@platform label for @what (ship by @date). Add the label and the date to the Shipping room?', $args),
      (bool) $row['fid'] => $this->t('@platform label for @what. Add it to the Shipping room?', $args),
      default => $this->t('@platform says ship @what by @date. Save that date in the Shipping room?', $args),
    };
    return [
      'text' => $text,
      'buttons' => [
        ['label' => $row['fid'] ? $this->t('Add label') : $this->t('Save date'), 'url' => $act('apply'), 'ghost' => FALSE],
        ['label' => $this->t('Not needed'), 'url' => $act('done'), 'ghost' => TRUE],
        $open,
      ],
    ];
  }

  /**
   * One row's text and buttons.
   */
  private function line(array $row, ?string $item, ?PlatformListing $listing, string $return): array {
    $platform = Platforms::PLATFORMS[$row['platform']] ?? $row['platform'];
    $what = $item ?? ($row['title'] !== NULL ? '"' . $row['title'] . '"' : (string) $this->t('an item'));
    $price = $row['price'] !== NULL ? '$' . number_format((float) $row['price'], 2) : NULL;
    $act = fn (string $action) => Url::fromRoute('ishop_inbox.act', ['id' => $row['id'], 'action' => $action], ['query' => ['destination' => $return]])->toString();
    $open = ['label' => $this->t('Open it'), 'url' => self::gmailUrl($row['message_id']), 'ghost' => TRUE];
    $done = ['label' => $this->t('Done'), 'url' => $act('done'), 'ghost' => TRUE];
    $ok = ['label' => $this->t('OK'), 'url' => $act('done'), 'ghost' => TRUE];
    $ship_room = ['label' => $this->t('Shipping room'), 'url' => Url::fromRoute('ishop_items.shipping_room')->toString(), 'ghost' => FALSE];
    $args = ['@platform' => $platform, '@what' => $what, '@price' => $price ?? '', '@date' => $row['ship_by'] ? (new \DateTimeImmutable($row['ship_by']))->format('D M j') : ''];
    switch ($row['kind']) {
      case Classifier::SOLD:
      case Classifier::LABEL:
        $sold_here = $listing && $listing->getStatus() === Platforms::SOLD_HERE;
        if ($listing && $item && !$sold_here) {
          $args['@label'] = $row['fid'] ? (string) $this->t(' and add the shipping label') : '';
          return [
            'text' => $price
              ? $this->t('@platform: @what sold for @price. Mark it sold here@label?', $args)
              : $this->t('@platform: @what sold. Mark it sold here@label?', $args),
            'buttons' => [
              ['label' => $this->t('Yes, mark sold'), 'url' => $act('sold'), 'ghost' => FALSE],
              ['label' => $this->t('Not this item'), 'url' => $act('done'), 'ghost' => TRUE],
              $open,
            ],
          ];
        }
        if ($listing && $item && $sold_here) {
          return $this->saleUpdate($row, $args, $act, $open);
        }
        if ($row['kind'] === Classifier::LABEL) {
          return ['text' => $this->t('@platform: a shipping label is ready for @what. Open the email to get it.', $args), 'buttons' => [$open, $done]];
        }
        return [
          'text' => $this->t("@platform email: you sold @what@for, but the site can't tell which item. Find it in the Stockroom and mark it Sold here.", $args + ['@for' => $price ? ' (' . $price . ')' : '']),
          'buttons' => [
            ['label' => $this->t('Find it'), 'url' => Url::fromRoute('ishop_matrix.stockroom', [], ['query' => ['q' => (string) $row['title']]])->toString(), 'ghost' => FALSE],
            $open,
            $done,
          ],
        ];

      case Classifier::SHIP_BY:
        if ($item && $row['ship_by'] && $listing && $listing->getStatus() === Platforms::SOLD_HERE) {
          return $this->saleUpdate($row, $args, $act, $open);
        }
        return ['text' => $this->t('@platform: reminder to ship @what.', $args), 'buttons' => [$open, $done]];

      case Classifier::MESSAGE:
        return ['text' => $this->t('@platform: new message about @what.', $args), 'buttons' => [$open, $done]];

      case Classifier::OFFER:
        return [
          'text' => $price ? $this->t('@platform: offer of @price on @what. Answer it in the app.', $args) : $this->t('@platform: new offer on @what. Answer it in the app.', $args),
          'buttons' => [$open, $done],
        ];

      case Classifier::DELAY:
        return ['text' => $this->t('@platform: shipping delay on @what.', $args), 'buttons' => [$open, $done]];

      case Classifier::RETURN:
        return ['text' => $this->t('@platform: return requested for @what.', $args), 'buttons' => [$open, $done]];

      case Classifier::CASE:
        return ['text' => $this->t('@platform: a case was opened on @what. Answer it soon.', $args), 'buttons' => [$open, $done]];

      case Classifier::PAYOUT:
        return ['text' => $price ? $this->t('@platform payout: @price.', $args) : $this->t('@platform: payout sent.', $args), 'buttons' => [$open, $ok]];
    }
    return ['text' => $this->t('@platform: open the email about @what.', $args), 'buttons' => [$open, $done]];
  }

}
