<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ishop_ai\AiRuns;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Queues "Write my listing" (an AI run) for an item.
 */
final class WriteListingController extends ControllerBase {

  public function __construct(
    private readonly AiRuns $runs,
  ) {}

  /**
   * Queues the run and goes back to the item.
   */
  public function queue(NodeInterface $node): RedirectResponse {
    $this->runs->queue((int) $node->id());
    $wait = $this->runs->waitReason();
    $wait
      ? $this->messenger()->addWarning($this->t('Queued. @wait Everything else keeps working; items and photos still save.', ['@wait' => $wait]))
      : $this->messenger()->addStatus($this->t('Queued: the AI is looking at the photos and searching sold prices. The draft appears on this page in a minute or two. You choose what to use.'));
    return $this->redirect('entity.node.canonical', ['node' => $node->id()], ['fragment' => 'ai']);
  }

}
