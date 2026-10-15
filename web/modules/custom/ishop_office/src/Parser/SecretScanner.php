<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Parser;

/**
 * Finds things in a report that look like secrets or personal data.
 *
 * This is a safety net, not a guarantee: the author is still responsible for
 * keeping secrets out. Findings name the kind and line, never the value.
 */
final class SecretScanner {

  /**
   * Email domains that are fine to mention (site placeholders).
   */
  private const ALLOWED_EMAIL_DOMAINS = ['ishoponamazonwaytoomuch.ohbah.com'];

  private const PATTERNS = [
    'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    'Anthropic API key' => '/\bsk-ant-[A-Za-z0-9_-]{10,}/',
    'API secret key (sk-...)' => '/\bsk-[A-Za-z0-9]{20,}/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}/',
    'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
    'Slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
    'URL with a password' => '#\b[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@#i',
    'Drupal one-time login link' => '#/user/reset/\d+/\d+/[A-Za-z0-9_-]{10,}#',
    'password or key assignment' => '/(?<![a-z0-9])(?:password|passwd|pass|secret|api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret)\b\s*[:=]\s*(?!<?redacted>?|none\b|n\/a\b|not set\b|\*+)[\'"]?[^\s\'"]{6,}/i',
  ];

  /**
   * Scans text.
   *
   * @return list<string>
   *   One "line N: kind" entry per finding. Empty when nothing was found.
   */
  public static function scan(string $text): array {
    $findings = [];
    foreach (preg_split('/\R/', $text) as $i => $line) {
      foreach (self::PATTERNS as $kind => $pattern) {
        if (preg_match($pattern, $line)) {
          $findings[] = sprintf('line %d: %s', $i + 1, $kind);
        }
      }
      if (preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $line, $m)) {
        foreach ($m[1] as $domain) {
          if (!in_array(strtolower($domain), self::ALLOWED_EMAIL_DOMAINS, TRUE)) {
            $findings[] = sprintf('line %d: email address (possible personal data)', $i + 1);
            break;
          }
        }
      }
    }
    return $findings;
  }

}
