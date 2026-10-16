<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ishop_inbox\Classifier;
use Drupal\ishop_inbox\Form\InboxSetupForm;
use Drupal\ishop_inbox\Inbox;
use Drupal\ishop_office\Form\MailSetupForm;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The marketplace email rows' buttons, and Mail setup with the inbox form.
 */
final class InboxController extends ControllerBase {

  public function __construct(
    private readonly Inbox $inbox,
  ) {}

  /**
   * Acts on one row, then goes back (the destination, or the front desk).
   */
  public function act(int $id, string $action): RedirectResponse {
    $row = $this->inbox->row($id);
    if (!$row || $row['status'] !== 'open') {
      $this->messenger()->addStatus($this->t('Already done.'));
    }
    elseif ($action === 'sold' && $row['kind'] === Classifier::SOLD && $row['listing']) {
      $this->messenger()->addStatus($this->inbox->confirmSale($row));
    }
    else {
      $this->inbox->close($row);
    }
    return $this->redirect('ishop_matrix.dashboard', [], ['fragment' => 'needs']);
  }

  /**
   * Mail setup: sending mail, then the marketplace inbox.
   */
  public function mailSetup(): array {
    return [
      'mail' => $this->formBuilder()->getForm(MailSetupForm::class),
      'inbox' => $this->formBuilder()->getForm(InboxSetupForm::class),
    ];
  }

}
