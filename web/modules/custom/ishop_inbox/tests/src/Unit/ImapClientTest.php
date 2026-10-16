<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_inbox\Unit;

use Drupal\ishop_inbox\Imap\ImapClient;
use Drupal\ishop_inbox\Imap\ImapException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the read-only IMAP client against a scripted server.
 */
#[CoversClass(ImapClient::class)]
final class ImapClientTest extends UnitTestCase {

  /**
   * A client that reads a scripted server reply and records what it sent.
   */
  private function client(string $server): ImapClient {
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $server);
    rewind($stream);
    return new class($stream) extends ImapClient {

      /**
       * What the client sent.
       */
      public string $sent = '';

      /**
       * {@inheritdoc}
       */
      protected function write(string $data): void {
        $this->sent .= $data;
      }

    };
  }

  /**
   * Login, EXAMINE, search and a fetch with a literal.
   */
  public function testSession(): void {
    $message = "From: a@ebay.com\r\nSubject: Hi\r\n\r\nBody {5}\r\n";
    $server = "A0001 OK LOGIN completed\r\n"
      . "* FLAGS (\\Seen)\r\n* OK [UIDVALIDITY 654321] UIDs valid\r\nA0002 OK [READ-ONLY] INBOX selected\r\n"
      . "* SEARCH 12 5 9\r\nA0003 OK SEARCH completed\r\n"
      . '* 1 FETCH (UID 5 BODY[] {' . strlen($message) . "}\r\n" . $message . ")\r\nA0004 OK Success\r\n";
    $client = $this->client($server);
    $client->login('shop@example.com', 'abcd "efgh"');
    $this->assertSame(654321, $client->examine());
    $this->assertSame([5, 9, 12], $client->searchGmail('from:(ebay.com) newer_than:14d'));
    $this->assertSame($message, $client->fetch(5));
    $this->assertSame(
      "A0001 LOGIN \"shop@example.com\" \"abcd \\\"efgh\\\"\"\r\n"
      . "A0002 EXAMINE \"INBOX\"\r\n"
      . "A0003 UID SEARCH X-GM-RAW \"from:(ebay.com) newer_than:14d\"\r\n"
      . "A0004 UID FETCH 5 (BODY.PEEK[])\r\n",
      $client->sent,
    );
  }

  /**
   * A refused login says so, without the password.
   */
  public function testLoginRefused(): void {
    $client = $this->client("A0001 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
    try {
      $client->login('shop@example.com', 'secretpassword12');
      $this->fail('No exception');
    }
    catch (ImapException $e) {
      $this->assertTrue($e->authFailed());
      $this->assertStringNotContainsString('secretpassword12', $e->getMessage());
    }
  }

  /**
   * Only the read-only commands can be sent.
   */
  public function testOnlyReadOnlyCommands(): void {
    foreach (['STORE', 'COPY', 'MOVE', 'APPEND', 'EXPUNGE', 'DELETE', 'SELECT', 'UID STORE', 'UID EXPUNGE'] as $command) {
      $this->assertNotContains($command, ImapClient::ALLOWED);
    }
    $client = $this->client('');
    $method = new \ReflectionMethod($client, 'command');
    $this->expectException(\LogicException::class);
    $method->invoke($client, 'UID STORE', '1 +FLAGS (\\Deleted)');
  }

  /**
   * Line breaks can't be smuggled into a command.
   */
  public function testQuote(): void {
    $this->assertSame('"a\\"b\\\\c"', ImapClient::quote('a"b\\c'));
    $this->expectException(\InvalidArgumentException::class);
    ImapClient::quote("x\r\nA0009 STORE");
  }

}
