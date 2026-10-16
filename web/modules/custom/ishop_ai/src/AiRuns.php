<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The "Write my listing" runs: the queue, the monthly cap, the results.
 *
 * Runs wait while AI is paused (expired or refused key, no credit) or the
 * month's AI spend has reached the site cap, and go ahead once renewed or
 * next month. Nothing is ever written to an item until Cody ticks it.
 */
final class AiRuns {

  /**
   * Site-side monthly AI cap in USD.
   *
   * Matches the $32 monthly limit on Cody's Anthropic account (item 27).
   * Was $25 (decision 226); raised to match on 2026-10-16 at Liam's request.
   */
  public const MONTHLY_CAP = 32.0;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiStatus $status,
    private readonly AiKeyManager $keys,
    private readonly ListingWriter $writer,
    #[Autowire(service: 'logger.channel.ishop_ai')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Queues a run for an item (once: an open run is reused).
   *
   * @return int
   *   The run ID.
   */
  public function queue(int $nid): int {
    $open = $this->database->select('ishop_ai_run', 'r')
      ->fields('r', ['id'])
      ->condition('nid', $nid)
      ->condition('status', ['queued', 'running'], 'IN')
      ->execute()->fetchField();
    if ($open) {
      return (int) $open;
    }
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('ishop_ai_run')->fields([
      'nid' => $nid,
      'uid' => (int) $this->currentUser->id(),
      'created' => $now,
      'changed' => $now,
      'status' => 'queued',
      'reason' => '',
    ])->execute();
  }

  /**
   * The latest run for an item, or NULL.
   */
  public function latest(int $nid): ?array {
    $row = $this->database->select('ishop_ai_run', 'r')->fields('r')
      // Cost-test runs (status "test") count in spend but are never drafts.
      ->condition('nid', $nid)->condition('status', 'test', '<>')->orderBy('id', 'DESC')->range(0, 1)
      ->execute()->fetchAssoc();
    return $row ? self::decode($row) : NULL;
  }

  /**
   * Finished drafts Cody hasn't used or dismissed yet, newest first.
   */
  public function unreviewed(): array {
    $rows = $this->database->select('ishop_ai_run', 'r')->fields('r')
      ->condition('status', 'done')->condition('reviewed', 0)
      ->orderBy('id', 'DESC')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return array_map([self::class, 'decode'], $rows);
  }

  /**
   * AI spend from the first of this month (Pacific) in USD.
   */
  public function monthCost(): float {
    $start = (new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('America/Los_Angeles')))->getTimestamp();
    return (float) $this->database->query('SELECT COALESCE(SUM(cost), 0) FROM {ishop_ai_run} WHERE created >= :start', [':start' => $start])->fetchField();
  }

  /**
   * AI spend between two dates (Y-m-d, inclusive, Pacific; NULL = open).
   *
   * @return array{cost: float, runs: int, items: int, by_item: array<int, float>}
   *   Total, run and item counts, and cost per item (highest first).
   */
  public function spendBetween(?string $from, ?string $to): array {
    $zone = new \DateTimeZone('America/Los_Angeles');
    $query = $this->database->select('ishop_ai_run', 'r')->fields('r', ['nid', 'cost'])->isNotNull('cost');
    if ($from) {
      $query->condition('created', (new \DateTimeImmutable($from . ' 00:00', $zone))->getTimestamp(), '>=');
    }
    if ($to) {
      $query->condition('created', (new \DateTimeImmutable($to . ' 00:00', $zone))->modify('+1 day')->getTimestamp(), '<');
    }
    $by_item = [];
    $runs = 0;
    foreach ($query->execute() as $row) {
      $by_item[(int) $row->nid] = ($by_item[(int) $row->nid] ?? 0.0) + (float) $row->cost;
      $runs++;
    }
    arsort($by_item);
    return ['cost' => array_sum($by_item), 'runs' => $runs, 'items' => count($by_item), 'by_item' => $by_item];
  }

  /**
   * AI cost per item (all runs), keyed by node ID.
   */
  public function costByItem(): array {
    return array_map('floatval', $this->database->query('SELECT nid, SUM(cost) FROM {ishop_ai_run} GROUP BY nid')->fetchAllKeyed());
  }

  /**
   * Why queued runs can't go now, or NULL.
   */
  public function waitReason(): ?string {
    $status = $this->status->get();
    if (!$status['configured'] || !AnthropicKey::has()) {
      return 'No AI key is saved yet (AI setup).';
    }
    if ($reason = AiStatus::pausedReason($status, AiStatus::today())) {
      return 'AI is paused: ' . (AiStatus::PAUSE_REASONS[$reason] ?? $reason) . '. It will run once the key is renewed.';
    }
    if ($this->monthCost() >= self::MONTHLY_CAP) {
      return sprintf('This month\'s AI spend has reached the $%d cap. It will run next month.', (int) self::MONTHLY_CAP);
    }
    return NULL;
  }

  /**
   * Runs the oldest queued run, if it can go.
   *
   * @return string
   *   What happened, for the log and the command line.
   */
  public function processNext(): string {
    $id = $this->database->select('ishop_ai_run', 'r')->fields('r', ['id'])
      ->condition('status', 'queued')->orderBy('id')->range(0, 1)->execute()->fetchField();
    if (!$id) {
      return 'Nothing queued.';
    }
    if ($wait = $this->waitReason()) {
      $this->database->update('ishop_ai_run')->fields(['reason' => mb_substr($wait, 0, 255)])->condition('status', 'queued')->execute();
      return 'Waiting: ' . $wait;
    }
    $run = self::decode($this->database->select('ishop_ai_run', 'r')->fields('r')->condition('id', $id)->execute()->fetchAssoc());
    $item = $this->entityTypeManager->getStorage('node')->load($run['nid']);
    if (!$item instanceof NodeInterface) {
      $this->finish($id, 'failed', 'The item no longer exists.');
      return 'Item gone.';
    }
    $this->database->update('ishop_ai_run')->fields(['status' => 'running', 'reason' => '', 'changed' => time()])->condition('id', $id)->execute();
    $key = AnthropicKey::get();
    try {
      $result = $this->writer->write($item, (string) $key);
      unset($key);
      $cost = ListingWriter::cost($result['usage']);
      $this->database->update('ishop_ai_run')->fields([
        'status' => 'done',
        'reason' => '',
        'draft' => json_encode($result['draft'] + ['searched' => $result['searched']]),
        'usage' => json_encode($result['usage']),
        'cost' => $cost,
        'changed' => time(),
      ])->condition('id', $id)->execute();
      $this->keys->recordCall(['ok' => TRUE, 'kind' => 'ok', 'message' => '']);
      $this->logger->notice('AI draft ready for item @nid ($@cost, @searches searches).', [
        '@nid' => $run['nid'],
        '@cost' => number_format($cost, 4),
        '@searches' => $result['usage']['searches'],
      ]);
      return sprintf('Done: item %d, $%s.', $run['nid'], number_format($cost, 4));
    }
    catch (AuthenticationException | PermissionDeniedException $e) {
      return $this->pauseAndRequeue($id, 'auth', 'Anthropic stopped accepting the key.');
    }
    catch (APIStatusException $e) {
      if ($e->type?->value === 'billing_error') {
        return $this->pauseAndRequeue($id, 'credit', 'The Anthropic account is out of credit.');
      }
      $this->finish($id, 'failed', 'Anthropic returned an error. Try again later.');
      $this->keys->recordCall(['ok' => FALSE, 'kind' => 'other', 'message' => $e->getMessage()]);
      return 'Failed: ' . $e->getMessage();
    }
    catch (\RuntimeException $e) {
      $this->finish($id, 'failed', $e->getMessage());
      return 'Failed: ' . $e->getMessage();
    }
    catch (\Throwable $e) {
      $this->finish($id, 'failed', 'Something went wrong. Try again later.');
      $this->logger->error('AI run @id failed: @error', ['@id' => $id, '@error' => $e->getMessage()]);
      return 'Failed: ' . $e->getMessage();
    }
  }

  /**
   * A refused key or no credit: pause AI, keep the run queued for later.
   */
  private function pauseAndRequeue(int $id, string $kind, string $message): string {
    $this->keys->recordCall(['ok' => FALSE, 'kind' => $kind, 'message' => $message]);
    $this->database->update('ishop_ai_run')->fields([
      'status' => 'queued',
      'reason' => mb_substr('AI is paused: ' . $message . ' It will run once the key is renewed.', 0, 255),
      'changed' => time(),
    ])->condition('id', $id)->execute();
    return 'Paused: ' . $message;
  }

  /**
   * Ends a run with a status and reason.
   */
  private function finish(int $id, string $status, string $reason): void {
    $this->database->update('ishop_ai_run')->fields([
      'status' => $status,
      'reason' => mb_substr($reason, 0, 255),
      'changed' => time(),
    ])->condition('id', $id)->execute();
  }

  /**
   * Marks a run's suggestion as used or dismissed.
   */
  public function markReviewed(int $id): void {
    $this->database->update('ishop_ai_run')->fields(['reviewed' => 1])->condition('id', $id)->execute();
  }

  /**
   * A run row with its JSON decoded.
   */
  public static function decode(array $row): array {
    $row['draft'] = $row['draft'] ? json_decode($row['draft'], TRUE) : NULL;
    $row['usage'] = $row['usage'] ? json_decode($row['usage'], TRUE) : NULL;
    $row['id'] = (int) $row['id'];
    $row['nid'] = (int) $row['nid'];
    return $row;
  }

}
