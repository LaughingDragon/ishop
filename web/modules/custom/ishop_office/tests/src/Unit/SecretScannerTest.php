<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Parser\SecretScanner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the secret and personal data safety net for reports.
 */
#[CoversClass(SecretScanner::class)]
#[Group('ishop_office')]
final class SecretScannerTest extends UnitTestCase {

  /**
   * Lines that must be flagged.
   */
  public static function secrets(): array {
    return [
      ['-----BEGIN OPENSSH PRIVATE KEY-----'],
      ['key is sk-ant-api03-abcdefghijklmnop'],
      ['AKIAABCDEFGHIJKLMNOP'],
      ['token ghp_abcdefghijklmnopqrstuvwxyz0123456789'],
      ['AIzaSyA1234567890abcdefghijklmnopqrstuv'],
      ['mysql://ishop:hunter22@localhost/ishop'],
      ['https://example.com/user/reset/1/1700000000/AbCdEfGhIjKlMnOp/login'],
      ['password: Tr0ub4dor&3'],
      ["DB_PASSWORD='s3cr3tvalue'"],
      ['api_key = 0123456789abcdef'],
      ['Buyer was jane.doe@example.com'],
    ];
  }

  /**
   * Tests that each secret is flagged.
   */
  #[DataProvider('secrets')]
  public function testSecretsAreFlagged(string $line): void {
    $findings = SecretScanner::scan("Fine line.\n" . $line);
    $this->assertNotEmpty($findings);
    $this->assertStringStartsWith('line 2: ', $findings[0]);
  }

  /**
   * Tests that findings never repeat the secret.
   */
  public function testFindingsNeverEchoTheValue(): void {
    $findings = SecretScanner::scan('password: Tr0ub4dor&3');
    $this->assertStringNotContainsString('Tr0ub4dor', implode(' ', $findings));
  }

  /**
   * Tests that secrets and email addresses are told apart.
   */
  public function testClassify(): void {
    $this->assertSame(['secrets' => [], 'emails' => TRUE], SecretScanner::classify(SecretScanner::scan("Send from a@gmail.com\nand b@gmail.com")));
    $both = SecretScanner::classify(SecretScanner::scan("a@gmail.com\npassword: Tr0ub4dor&3"));
    $this->assertSame(['password or key assignment'], $both['secrets']);
    $this->assertTrue($both['emails']);
    $this->assertSame(['secrets' => [], 'emails' => FALSE], SecretScanner::classify([]));
  }

  /**
   * Lines that must pass.
   */
  public static function harmless(): array {
    return [
      ['The DB password was rotated; the exposed value is dead.'],
      ['password: <redacted>'],
      ['password: not set'],
      ['Placeholder site-foreman@ishoponamazonwaytoomuch.ohbah.com'],
      ['Commit 69f5e1d added the roadmap.'],
      ['Visit https://ishoponamazonwaytoomuch.ohbah.com/site-office'],
      ['The task-list skill is fine.'],
      ['Pass: commit and file a report. Fail: roll back.'],
    ];
  }

  /**
   * Tests that ordinary text passes.
   */
  #[DataProvider('harmless')]
  public function testHarmlessTextPasses(string $line): void {
    $this->assertSame([], SecretScanner::scan($line));
  }

}
