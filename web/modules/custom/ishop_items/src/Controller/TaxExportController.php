<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ishop_items\TaxExport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Year-end tax export downloads (Phase 4).
 */
final class TaxExportController extends ControllerBase {

  public function __construct(
    private readonly TaxExport $export,
  ) {}

  /**
   * The sales or donations CSV for one calendar year.
   */
  public function download(string $year, string $kind): Response {
    $year = (int) $year;
    $csv = $kind === 'sales'
      ? TaxExport::csv(TaxExport::SALES_COLUMNS, $this->export->salesRows($year))
      : TaxExport::csv(TaxExport::DONATION_COLUMNS, $this->export->donationRows($year));
    $response = new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Cache-Control' => 'private, no-store',
    ]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('ishop-%d-%s.csv', $year, $kind)));
    return $response;
  }

}
