<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_life\Bills;
use Drupal\ishop_life\Form\BillForm;
use Drupal\ishop_life\LifeStructure;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * My office: bills and subscriptions (Phase 5 step 2).
 */
final class BillsController extends ControllerBase {

  public function __construct(
    private readonly Bills $bills,
  ) {}

  /**
   * The "Paid" link for a bill (with a CSRF token), coming back to $return.
   */
  public static function paidUrl(NodeInterface $bill, string $return): string {
    return Url::fromRoute('ishop_life.bill_paid', ['node' => $bill->id()], ['query' => ['destination' => $return]])->toString();
  }

  /**
   * Upcoming bills with Paid buttons, then the add form.
   */
  public function page(): array {
    $return = Url::fromRoute('ishop_life.bills')->toString();
    $rows = [];
    foreach ($this->bills->active() as $bill) {
      $rows[] = [
        'line' => $this->bills->lineFor($bill),
        'repeat' => LifeStructure::REPEATS[$bill->get('field_repeat')->value] ?? '',
        'business' => (bool) $bill->get('field_bill_business')->value,
        'paid' => self::paidUrl($bill, $return),
        'edit' => $bill->toUrl('edit-form', ['query' => ['destination' => $return]])->toString(),
        'delete' => $bill->toUrl('delete-form', ['query' => ['destination' => $return]])->toString(),
      ];
    }
    return [
      'list' => [
        '#type' => 'inline_template',
        '#template' => '<p class="rm-log__back"><a href="{{ back }}">&larr; {{ back_label }}</a></p><section class="rm-bills" aria-labelledby="rm-bills-title"><h2 id="rm-bills-title">{{ title }}</h2>{% if rows %}<ul class="rm-bills__list">{% for r in rows %}<li><span><strong>{{ r.line }}</strong><span class="rm-bills__meta">{{ r.repeat }}{% if r.business %} · {{ business }}{% endif %}</span></span><span class="rm-bills__actions"><a class="rm-button rm-button--gold" href="{{ r.paid }}">{{ paid }}</a><a href="{{ r.edit }}">{{ edit }}</a><a href="{{ r.delete }}">{{ delete }}</a></span></li>{% endfor %}</ul>{% else %}<p>{{ empty }}</p>{% endif %}<p class="rm-log__note">{{ note }}</p></section>',
        '#context' => [
          'back' => Url::fromRoute('ishop_matrix.my_office')->toString(),
          'back_label' => $this->t('Back to My office'),
          'title' => $this->t('Coming up'),
          'rows' => $rows,
          'business' => $this->t('business expense'),
          'paid' => $this->t('Paid'),
          'edit' => $this->t('Edit'),
          'delete' => $this->t('Delete'),
          'empty' => $this->t('No bills yet.'),
          'note' => $this->t('Each bill shows in "Needs you now" @days days before it is due. "Paid" moves it to its next due date (a one-off bill is archived).', ['@days' => Bills::DAYS_AHEAD]),
        ],
      ],
      'form' => $this->formBuilder()->getForm(BillForm::class),
      '#attached' => ['library' => ['ishop_life/log']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Paid: next due date (or archived), then back where Cody was.
   */
  public function paid(NodeInterface $node): RedirectResponse {
    $this->messenger()->addStatus($this->bills->pay($node));
    return $this->redirect('ishop_life.bills');
  }

}
