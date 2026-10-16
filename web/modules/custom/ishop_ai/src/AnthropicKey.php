<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Cody's own Anthropic API key (Phase 7 step 1).
 *
 * Kept like the Gmail app password: one file under secrets/ (outside the web
 * root and git, owner and web server group only), read through the Key module
 * (key "anthropic_api_key", file provider: config holds only the path). Never
 * in config, the database, logs, reports or punch items; never shown again.
 */
final class AnthropicKey {

  /**
   * Where the key lives (the Key entity's file provider points here).
   */
  public const FILE = '/var/www/ishop/secrets/anthropic/api_key.txt';

  /**
   * The Key module entity that reads it.
   */
  public const KEY_ID = 'anthropic_api_key';

  /**
   * The model the site uses (the current Opus).
   */
  public const MODEL = 'claude-opus-5-5';

  /**
   * Whether a key is saved.
   */
  public static function has(string $file = self::FILE): bool {
    return is_file($file) && filesize($file) > 0;
  }

  /**
   * The saved key, or NULL. For API calls only: never display or log it.
   *
   * The real key is read through the Key module; a test file (another
   * path) is read directly.
   */
  public static function get(string $file = self::FILE): ?string {
    if (!self::has($file)) {
      return NULL;
    }
    $key = $file === self::FILE
      ? \Drupal::service('key.repository')->getKey(self::KEY_ID)?->getKeyValue()
      : file_get_contents($file);
    $key = trim((string) $key);
    return $key === '' ? NULL : $key;
  }

  /**
   * A key shown safely: "sk-ant-…" and its last 4 characters.
   */
  public static function mask(string $key): string {
    return strlen($key) > 12 ? 'sk-ant-…' . substr($key, -4) : '(hidden)';
  }

  /**
   * The last 4 characters of a key (all that is ever recorded).
   */
  public static function last4(string $key): string {
    return substr($key, -4);
  }

  /**
   * A typed key with spaces and line breaks removed, or NULL if it isn't one.
   */
  public static function normalize(string $value): ?string {
    $key = (string) preg_replace('/\s+/', '', $value);
    return preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $key) ? $key : NULL;
  }

  /**
   * Saves the key atomically, readable by owner and group only.
   */
  public static function save(string $key, string $file = self::FILE): bool {
    $tmp = @tempnam(dirname($file), '.tmp-');
    if ($tmp === FALSE) {
      return FALSE;
    }
    $ok = file_put_contents($tmp, $key . "\n") !== FALSE && chmod($tmp, 0640) && rename($tmp, $file);
    if (!$ok) {
      @unlink($tmp);
    }
    return $ok;
  }

  /**
   * Deletes the key.
   */
  public static function remove(string $file = self::FILE): void {
    @unlink($file);
  }

  /**
   * Makes one tiny test call with this key.
   *
   * A 16-token message (a fraction of a cent), which also proves the account
   * has credit.
   *
   * @return array{ok: bool, kind: string, message: string}
   *   kind: ok, auth, credit, permission, rate, connection or other; message
   *   in plain English, with the key masked.
   */
  public static function test(string $key): array {
    try {
      $client = new Client(apiKey: $key);
      $client->messages->create(
        model: self::MODEL,
        maxTokens: 16,
        messages: [['role' => 'user', 'content' => 'Reply with OK.']],
        outputConfig: ['effort' => 'low'],
      );
      return ['ok' => TRUE, 'kind' => 'ok', 'message' => 'Key works.'];
    }
    catch (AuthenticationException) {
      [
        $kind,
        $reason,
      ] = ['auth', 'Anthropic did not accept this key. Check it was copied in full, or create a new one.'];
    }
    catch (PermissionDeniedException) {
      [
        $kind,
        $reason,
      ] = [
        'permission',
        'This key is not allowed to use the model the site needs. Check the key\'s workspace in the Anthropic Console.',
      ];
    }
    catch (RateLimitException) {
      [$kind, $reason] = ['rate', 'Anthropic says too many requests right now. Wait a minute and try again.'];
    }
    catch (APIStatusException $e) {
      [$kind, $reason] = $e->type?->value === 'billing_error'
        ? ['credit', 'The account has no credit. Add credit in the Anthropic Console (Billing), then try again.']
        : ['other', 'Anthropic returned an error: ' . $e->getMessage()];
    }
    catch (APIConnectionException) {
      [$kind, $reason] = ['connection', 'The server could not reach Anthropic. Try again in a minute.'];
    }
    catch (\Throwable $e) {
      [$kind, $reason] = ['other', 'Unexpected error: ' . $e->getMessage()];
    }
    // Belt and braces: never echo the key back.
    return ['ok' => FALSE, 'kind' => $kind, 'message' => str_replace($key, self::mask($key), $reason)];
  }

}
