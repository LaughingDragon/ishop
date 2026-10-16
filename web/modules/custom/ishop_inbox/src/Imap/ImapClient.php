<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Imap;

/**
 * A small, read-only IMAP client for the business Gmail (Phase 10 B).
 *
 * Read-only by construction:
 * - the mailbox is opened with EXAMINE, which the server opens read-only;
 * - messages are read with BODY.PEEK[], which doesn't mark them read;
 * - only the commands in ALLOWED can be sent. There is no STORE, COPY,
 *   MOVE, APPEND, EXPUNGE or DELETE, and no way to send mail.
 */
class ImapClient {

  /**
   * The only commands this client will send.
   */
  public const ALLOWED = ['CAPABILITY', 'LOGIN', 'EXAMINE', 'UID SEARCH', 'UID FETCH', 'LOGOUT'];

  /**
   * Gmail's IMAP server.
   */
  public const HOST = 'imap.gmail.com';
  public const PORT = 993;

  /**
   * The next command tag number.
   */
  private int $tag = 0;

  /**
   * Wraps an open stream.
   *
   * @param resource $stream
   *   An open connection to the server (or a test stream).
   */
  public function __construct(
    protected $stream,
  ) {}

  /**
   * Connects to Gmail over TLS and reads the greeting.
   *
   * @throws \Drupal\ishop_inbox\Imap\ImapException
   */
  public static function connect(int $timeout = 20): static {
    $context = stream_context_create(['ssl' => ['verify_peer' => TRUE, 'verify_peer_name' => TRUE, 'peer_name' => self::HOST]]);
    $stream = @stream_socket_client(sprintf('ssl://%s:%d', self::HOST, self::PORT), $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$stream) {
      throw new ImapException(sprintf("Couldn't reach Gmail: %s", $error ?: 'connection failed'));
    }
    stream_set_timeout($stream, $timeout);
    $client = new static($stream);
    $greeting = $client->readLine();
    if (!str_starts_with($greeting, '* OK')) {
      throw new ImapException('Gmail did not greet us: ' . trim($greeting));
    }
    return $client;
  }

  /**
   * Logs in with the address and app password.
   *
   * @throws \Drupal\ishop_inbox\Imap\ImapException
   *   With authFailed() TRUE when Gmail refused the password. The message
   *   never contains the password.
   */
  public function login(string $user, string $password): void {
    try {
      $this->command('LOGIN', self::quote($user) . ' ' . self::quote($password));
    }
    catch (ImapException $e) {
      throw new ImapException('Gmail refused the login (app password wrong or revoked).', TRUE);
    }
  }

  /**
   * Opens a mailbox read-only.
   *
   * @return int
   *   Its UIDVALIDITY (UIDs are only stable while this stays the same).
   */
  public function examine(string $mailbox = 'INBOX'): int {
    $lines = $this->command('EXAMINE', self::quote($mailbox));
    foreach ($lines as $line) {
      if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
        return (int) $m[1];
      }
    }
    throw new ImapException('No UIDVALIDITY from the server.');
  }

  /**
   * UIDs of messages matching a Gmail search (X-GM-RAW), oldest first.
   *
   * @return int[]
   *   Message UIDs.
   */
  public function searchGmail(string $query): array {
    $lines = $this->command('UID SEARCH', 'X-GM-RAW ' . self::quote($query));
    $uids = [];
    foreach ($lines as $line) {
      if (preg_match('/^\* SEARCH ?([\d ]*)/i', $line, $m)) {
        $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
      }
    }
    sort($uids);
    return $uids;
  }

  /**
   * One whole message (RFC 822), without marking it read.
   */
  public function fetch(int $uid): ?string {
    $lines = $this->command('UID FETCH', $uid . ' (BODY.PEEK[])');
    foreach ($lines as $line) {
      if (is_array($line)) {
        return $line['literal'];
      }
    }
    return NULL;
  }

  /**
   * Logs out and closes the connection (errors ignored).
   */
  public function logout(): void {
    try {
      $this->command('LOGOUT', '');
    }
    catch (ImapException) {
      // Closing anyway.
    }
    if (is_resource($this->stream)) {
      fclose($this->stream);
    }
  }

  /**
   * An IMAP quoted string.
   */
  public static function quote(string $value): string {
    if (preg_match('/[\r\n\0]/', $value)) {
      throw new \InvalidArgumentException('Line breaks are not allowed in an IMAP string.');
    }
    return '"' . addcslashes($value, '"\\') . '"';
  }

  /**
   * Sends one allowed command and returns its untagged response lines.
   *
   * A line that carried a literal comes back as ['line' => ..., 'literal' => ...].
   *
   * @throws \Drupal\ishop_inbox\Imap\ImapException
   *   When the command isn't allowed or the server answers NO or BAD.
   */
  protected function command(string $command, string $arguments): array {
    if (!in_array($command, self::ALLOWED, TRUE)) {
      throw new \LogicException("IMAP command not allowed: $command");
    }
    $tag = 'A' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
    $this->write($tag . ' ' . $command . ($arguments === '' ? '' : ' ' . $arguments) . "\r\n");
    $lines = [];
    while (TRUE) {
      $line = $this->readLine();
      if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
        $literal = $this->read((int) $m[1]);
        $rest = $this->readLine();
        $lines[] = ['line' => $line . $rest, 'literal' => $literal];
        continue;
      }
      if (str_starts_with($line, $tag . ' ')) {
        $status = strtoupper(substr($line, strlen($tag) + 1, 3));
        if (!str_starts_with($status, 'OK')) {
          // Never echo the command: LOGIN carries the password.
          throw new ImapException(sprintf('%s failed: %s', $command, trim(substr($line, strlen($tag) + 1))));
        }
        return $lines;
      }
      $lines[] = rtrim($line, "\r\n");
    }
  }

  /**
   * Writes to the server.
   */
  protected function write(string $data): void {
    if (@fwrite($this->stream, $data) === FALSE) {
      throw new ImapException('Lost the connection to Gmail.');
    }
  }

  /**
   * Reads one line, with its line ending.
   */
  protected function readLine(): string {
    $line = fgets($this->stream);
    if ($line === FALSE) {
      throw new ImapException('Lost the connection to Gmail.');
    }
    return $line;
  }

  /**
   * Reads exactly $length bytes.
   */
  protected function read(int $length): string {
    $data = '';
    while (strlen($data) < $length) {
      $chunk = fread($this->stream, $length - strlen($data));
      if ($chunk === FALSE || ($chunk === '' && feof($this->stream))) {
        throw new ImapException('Lost the connection to Gmail.');
      }
      $data .= $chunk;
    }
    return $data;
  }

}
