<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingManager;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The Returned and Keeping it buttons on a return reminder (Phase 6).
 */
final class ReturnController extends ControllerBase {

  public function __construct(
    private readonly ListingManager $listings,
  ) {}

  /**
   * The buttons for a return reminder row, as "Needs you now" takes them.
   */
  public static function buttons(NodeInterface $item, string $return): array {
    $url = fn (string $action) => Url::fromRoute(
      'ishop_items.return_decide',
      ['node' => $item->id(), 'action' => $action],
      ['query' => ['destination' => $return]],
    )->toString();
    return [
      ['label' => t('Returned'), 'url' => $url('returned'), 'ghost' => FALSE],
      ['label' => t('Keeping it'), 'url' => $url('keep'), 'ghost' => TRUE],
      ['label' => t('Open'), 'url' => $item->toUrl()->toString(), 'ghost' => TRUE],
    ];
  }

  /**
   * Marks the item returned, or stops its return reminders.
   */
  public function decide(NodeInterface $node, string $action): RedirectResponse {
    if ($action === 'returned') {
      $down = $this->listings->finishItem($node, 'returned', 'Return reminder: Returned.');
      $this->messenger()->addStatus($down
        ? $this->t('@item is marked Returned. Take it down from @list.', [
          '@item' => $node->label(),
          '@list' => implode(', ', $down),
        ])
        : $this->t('@item is marked Returned.', ['@item' => $node->label()]));
    }
    else {
      $node->set('field_return_keep', TRUE);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->currentUser()->id());
      $node->setRevisionLogMessage('Return reminder: Keeping it.');
      $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $node->save();
      $this->messenger()->addStatus($this->t('Keeping @item: no more return reminders for it.', ['@item' => $node->label()]));
    }
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

}
