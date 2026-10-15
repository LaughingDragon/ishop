<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\TaxExport;
use Drupal\ishop_life\Form\ExpenseForm;
use Drupal\ishop_life\Form\MileageForm;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\LogBook;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * My office: the mileage and expense log (Phase 5 step 1).
 */
final class LogController extends ControllerBase {

  public function __construct(
    private readonly LogBook $logBook,
  ) {}

  /**
   * The two quick-add forms and the latest entries.
   */
  public function page(): array {
    $destination = Url::fromRoute('ishop_life.log')->toString();
    $entry = function ($node, string $line) use ($destination) {
      return [
        'line' => $line,
        'edit' => $node->toUrl('edit-form', ['query' => ['destination' => $destination]])->toString(),
        'delete' => $node->toUrl('delete-form', ['query' => ['destination' => $destination]])->toString(),
        'receipt' => $node->hasField('field_receipt') && !$node->get('field_receipt')->isEmpty() ? $node->get('field_receipt')->entity?->createFileUrl() : NULL,
      ];
    };
    $trips = [];
    foreach ($this->logBook->recent('mileage') as $node) {
      $trips[] = $entry($node, $node->label());
    }
    $expenses = [];
    foreach ($this->logBook->recent('expense') as $node) {
      $category = LifeStructure::CATEGORIES[$node->get('field_expense_category')->value] ?? '';
      $expenses[] = $entry($node, sprintf('%s %s, $%s (%s)', $node->get('field_log_date')->value, $node->label(), number_format((float) $node->get('field_amount')->value, 2), $category));
    }
    return [
      'back' => [
        '#type' => 'inline_template',
        '#template' => '<p class="rm-log__back"><a href="{{ url }}">&larr; {{ label }}</a></p>',
        '#context' => [
          'url' => Url::fromRoute('ishop_matrix.my_office')->toString(),
          'label' => $this->t('Back to My office'),
        ],
      ],
      'forms' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['rm-log__forms']],
        'mileage' => $this->formBuilder()->getForm(MileageForm::class),
        'expense' => $this->formBuilder()->getForm(ExpenseForm::class),
      ],
      'recent' => [
        '#type' => 'inline_template',
        '#template' => '<div class="rm-log__lists">{% for list in lists %}<section class="rm-log__list"><h2>{{ list.title }}</h2>{% if list.rows %}<ul>{% for r in list.rows %}<li><span>{{ r.line }}</span><span class="rm-log__links">{% if r.receipt %}<a href="{{ r.receipt }}">{{ receipt }}</a> {% endif %}<a href="{{ r.edit }}">{{ edit }}</a> <a href="{{ r.delete }}">{{ delete }}</a></span></li>{% endfor %}</ul>{% else %}<p>{{ list.empty }}</p>{% endif %}</section>{% endfor %}<p class="rm-log__note">{{ note }}</p></div>',
        '#context' => [
          'lists' => [
            ['title' => $this->t('Latest trips'), 'rows' => $trips, 'empty' => $this->t('No trips logged yet.')],
            [
              'title' => $this->t('Latest expenses'),
              'rows' => $expenses,
              'empty' => $this->t('No expenses logged yet.'),
            ],
          ],
          'receipt' => $this->t('Receipt'),
          'edit' => $this->t('Edit'),
          'delete' => $this->t('Delete'),
          'note' => $this->t('Everything here goes into the year-end tax export in My office (Mileage and Expenses files). Miles are recorded as driven; the site does not work out any deduction.'),
        ],
      ],
      '#attached' => ['library' => ['ishop_life/log']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The Mileage or Expenses CSV for one calendar year.
   */
  public function export(string $year, string $kind): Response {
    $year = (int) $year;
    $csv = $kind === 'mileage'
      ? TaxExport::csv(['Date', 'Trip', 'Miles', 'Note'], $this->logBook->mileageRows($year))
      : TaxExport::csv(['Date', 'What', 'Category', 'Amount', 'Receipt', 'Note'], $this->logBook->expenseRows($year));
    $response = new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Cache-Control' => 'private, no-store',
    ]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('ishop-%d-%s.csv', $year, $kind)));
    return $response;
  }

}
