<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Drush\Commands;

use Drupal\ishop_items\ListingImport\ImportPlan;
use Drupal\ishop_items\ListingImport\ListingImporter;
use Drupal\ishop_items\ListingImport\SheetReader;
use Drupal\ishop_items\Platforms;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Listings import dry run (Phase 10). Reads only; saves nothing.
 */
final class ListingImportCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ListingImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Shows what the Import listings page would preview for a file.
   *
   * Saves nothing.
   */
  #[CLI\Command(name: 'ishop:import-preview')]
  #[CLI\Argument(name: 'file', description: 'Path to the .xlsx or .csv file.')]
  #[CLI\Option(name: 'no-fill', description: 'Leave out the old Crosslister records.')]
  #[CLI\Option(name: 'items', description: 'List every item with its problems.')]
  public function preview(string $file, array $options = ['no-fill' => FALSE, 'items' => FALSE]): int {
    if (!is_file($file) || !is_readable($file)) {
      $this->logger()->error(sprintf('No readable file at %s (give the full path).', $file));
      return self::EXIT_FAILURE;
    }
    try {
      $sheets = SheetReader::read($file, pathinfo($file, PATHINFO_EXTENSION));
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $index = $this->importer->bestTab($sheets);
    $sheet = $sheets[$index];
    $mapping = $this->importer->guessColumns($sheet);
    $maps = $this->importer->proposeValueMaps($sheet, $mapping);
    $plan = ImportPlan::build($sheet['rows'], $mapping, $this->importer->context($maps, !$options['no-fill']));
    $c = ImportPlan::counts($plan);
    $out = $this->output();
    $out->writeln('Tabs: ' . implode(', ', array_map(fn ($s) => $s['name'] . ' (' . count($s['rows']) . ' rows)', $sheets)) . '; reading "' . $sheet['name'] . '" (headings on row ' . $sheet['header_row'] . ')');
    foreach ($sheet['headers'] as $i => $header) {
      $out->writeln(sprintf('  %s %-16s -> %s', SheetReader::letter($i), $header, $mapping[$i] === '' ? 'Ignore' : $mapping[$i]));
    }
    foreach ($maps as $field => $map) {
      $terms = $this->importer->terms($field);
      foreach ($map as $value => $tid) {
        $out->writeln(sprintf('  %s "%s" -> %s', $field, $value, $tid ? $terms[$tid] : '(blank)'));
      }
    }
    $out->writeln(sprintf('Rows %d -> items %d: listed %d, sold %d ($%s), ready %d, ended %d. Ticked by default %d. Items with problems %d.',
      $c['rows'], $c['items'], $c['kinds']['listed'], $c['kinds']['sold'], number_format($c['sold_total'], 2), $c['kinds']['ready'], $c['kinds']['ended'], $c['ticked'], $c['problems']));
    $out->writeln(sprintf('Crosslister records matched %d, filled in %d, photos %d (on %d items).', $c['records'], $c['filled'], $c['photos'], $c['photo_items']));
    $out->writeln(sprintf('%-10s %7s %7s %8s %7s %9s %8s %6s %6s', 'Platform', 'Posted', 'Sold', 'TakeDown', 'ToPost', 'NotNeeded', 'NotAllow', 'Other', 'Blank'));
    foreach ($c['platforms'] as $platform => $s) {
      $other = array_sum($s) - $s['blank'] - $s['posted'] - $s['sold_here'] - $s['take_down'] - $s['to_post'] - $s['not_needed_sold'] - $s['not_allowed'];
      $out->writeln(sprintf('%-10s %7d %7d %8d %7d %9d %8d %6d %6d', Platforms::PLATFORMS[$platform], $s['posted'], $s['sold_here'], $s['take_down'], $s['to_post'], $s['not_needed_sold'], $s['not_allowed'], $other, $s['blank']));
    }
    $problems = [];
    foreach ($plan as $item) {
      foreach ($item['problems'] as [$level, $message]) {
        $problems[$level . ': ' . preg_replace('/"[^"]*"|\d+/', '…', $message)][] = implode('+', $item['rows']);
      }
      if ($options['items']) {
        $out->writeln(sprintf('[%s] rows %s %s | %s | %s', $item['tick'] ? 'x' : ' ', implode(',', $item['rows']), $item['kind'], mb_strimwidth($item['title'], 0, 50, '…'), implode('; ', array_column($item['problems'], 1))));
      }
    }
    ksort($problems);
    foreach ($problems as $kind => $rows) {
      $out->writeln(sprintf('  %3d x %s (rows %s)', count($rows), $kind, implode(', ', array_slice($rows, 0, 12)) . (count($rows) > 12 ? ', …' : '')));
    }
    $this->logger()->notice('Dry run: nothing was saved.');
    return self::EXIT_SUCCESS;
  }

}
