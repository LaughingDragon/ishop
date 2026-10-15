<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Stockroom search and filters (Phase 4, step 1).
 *
 * Read from the page's query string (so a filtered list has its own link)
 * and applied to the item query. Built in, without Search API: right for a
 * few hundred items (Liam, decision 178).
 */
final class StockroomFilters {

  /**
   * Query-string keys, in the order the form shows them.
   */
  public const KEYS = ['q', 'brand', 'size', 'status', 'platform', 'location', 'min', 'max'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Cleans raw query-string values: unknown or empty ones are dropped.
   *
   * @return array<string, string|int|float>
   *   Only the filters in use.
   */
  public static function parse(array $query): array {
    $filters = [];
    $q = trim((string) ($query['q'] ?? ''));
    if ($q !== '') {
      $filters['q'] = mb_substr($q, 0, 100);
    }
    foreach (['brand', 'location'] as $key) {
      if (ctype_digit((string) ($query[$key] ?? '')) && (int) $query[$key] > 0) {
        $filters[$key] = (int) $query[$key];
      }
    }
    $size = trim((string) ($query['size'] ?? ''));
    if ($size !== '') {
      $filters['size'] = mb_substr($size, 0, 60);
    }
    if (preg_match('/^[a-z_]+$/', (string) ($query['status'] ?? ''))) {
      $filters['status'] = $query['status'];
    }
    if (isset(Platforms::PLATFORMS[$query['platform'] ?? ''])) {
      $filters['platform'] = $query['platform'];
    }
    foreach (['min', 'max'] as $key) {
      $value = str_replace(['$', ','], '', trim((string) ($query[$key] ?? '')));
      if ($value !== '' && is_numeric($value) && (float) $value >= 0) {
        $filters[$key] = (float) $value;
      }
    }
    return $filters;
  }

  /**
   * Adds the filters to an item entity query.
   *
   * @return bool
   *   FALSE when nothing can match (no need to run the query).
   */
  public function apply(QueryInterface $query, array $filters): bool {
    if (isset($filters['q'])) {
      $query->condition('title', $filters['q'], 'CONTAINS');
    }
    if (isset($filters['brand'])) {
      $query->condition('field_brand', $filters['brand']);
    }
    if (isset($filters['size'])) {
      $query->condition('field_size', $filters['size']);
    }
    if (isset($filters['status'])) {
      $query->condition('field_item_status', $filters['status']);
    }
    if (isset($filters['location'])) {
      // The location and everything inside it ("Game room" covers its tables).
      $tids = [$filters['location']];
      foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('storage_location', $filters['location']) as $term) {
        $tids[] = (int) $term->tid;
      }
      $query->condition('field_storage_location', $tids, 'IN');
    }
    if (isset($filters['min'])) {
      $query->condition('field_asking_price', $filters['min'], '>=');
    }
    if (isset($filters['max'])) {
      $query->condition('field_asking_price', $filters['max'], '<=');
    }
    if (isset($filters['platform'])) {
      $listings = $this->entityTypeManager->getStorage('platform_listing');
      $ids = $listings->getQuery()->accessCheck(FALSE)
        ->condition('platform', $filters['platform'])
        ->condition('status', Stale::LIVE, 'IN')
        ->execute();
      $nids = array_unique(array_map(fn ($listing) => (int) $listing->get('item')->target_id, $listings->loadMultiple($ids)));
      if (!$nids) {
        return FALSE;
      }
      $query->condition('nid', $nids, 'IN');
    }
    return TRUE;
  }

  /**
   * Choices for the filter form, from what the items actually use.
   *
   * @return array{brand: array, size: array, status: array, platform: array, location: array}
   *   Value => label per filter.
   */
  public function options(array $location_options): array {
    $brands = [];
    $brand_ids = $this->database->query("SELECT DISTINCT field_brand_target_id FROM {node__field_brand} WHERE bundle = 'item'")->fetchCol();
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($brand_ids) as $term) {
      $brands[(int) $term->id()] = $term->label();
    }
    natcasesort($brands);
    $sizes = $this->database->query("SELECT DISTINCT field_size_value FROM {node__field_size} WHERE bundle = 'item' AND field_size_value <> ''")->fetchCol();
    natcasesort($sizes);
    $statuses = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node')['field_item_status']->getSetting('allowed_values');
    return [
      'brand' => $brands,
      'size' => array_combine($sizes, $sizes) ?: [],
      'status' => $statuses,
      'platform' => Platforms::PLATFORMS,
      'location' => $location_options,
    ];
  }

}
