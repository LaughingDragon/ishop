<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Content types and fields for the daily-life tools (Phase 5).
 *
 * Created by code (like the site office's), so a fresh install or a rebuild
 * gets exactly the same structure. ensure() only adds what is missing.
 */
final class LifeStructure {

  /**
   * Content types: machine name => [label, description].
   */
  public const TYPES = [
    'mileage' => ['Mileage', 'A trip for the business (post office runs and the like). Logged in My office.'],
    'expense' => ['Expense', 'Money spent for the business (supplies, packing materials, fees). Logged in My office.'],
  ];

  /**
   * Expense categories (packing materials are supplies kept in the game room).
   */
  public const CATEGORIES = [
    'supplies' => 'Supplies',
    'packing' => 'Packing materials',
    'fees' => 'Fees and subscriptions',
    'other' => 'Other',
  ];

  /**
   * Field storage: name => [type, settings].
   */
  private const STORAGE = [
    'field_log_date' => ['datetime', ['datetime_type' => 'date']],
    'field_trip' => ['string', ['max_length' => 120]],
    'field_miles' => ['decimal', ['precision' => 7, 'scale' => 1]],
    'field_amount' => ['decimal', ['precision' => 10, 'scale' => 2]],
    'field_expense_category' => ['list_string', ['allowed_values' => self::CATEGORIES]],
    'field_receipt' => ['file', ['uri_scheme' => 'private', 'target_type' => 'file']],
    'field_log_note' => ['string_long', []],
  ];

  /**
   * Fields per type: name => [label, required, field settings].
   */
  private const FIELDS = [
    'mileage' => [
      'field_log_date' => ['Date', TRUE, []],
      'field_trip' => ['Trip', TRUE, []],
      'field_miles' => ['Miles', TRUE, ['min' => 0]],
      'field_log_note' => ['Note', FALSE, []],
    ],
    'expense' => [
      'field_log_date' => ['Date', TRUE, []],
      'field_amount' => ['Amount', TRUE, ['min' => 0, 'prefix' => '$']],
      'field_expense_category' => ['Category', TRUE, []],
      'field_receipt' => [
        'Receipt',
        FALSE,
        [
          'file_directory' => 'receipts/[date:custom:Y]-[date:custom:m]',
          'file_extensions' => 'jpg jpeg png webp heic heif pdf',
          'max_filesize' => '32 MB',
        ],
      ],
      'field_log_note' => ['Note', FALSE, []],
    ],
  ];

  /**
   * Creates whatever is missing.
   */
  public static function ensure(): void {
    foreach (self::TYPES as $type => [$label, $description]) {
      if (!NodeType::load($type)) {
        NodeType::create([
          'type' => $type,
          'name' => $label,
          'description' => $description,
          'new_revision' => TRUE,
          'display_submitted' => FALSE,
        ])->save();
      }
    }
    foreach (self::STORAGE as $name => [$type, $settings]) {
      if (!FieldStorageConfig::loadByName('node', $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'type' => $type,
          'settings' => $settings,
        ])->save();
      }
    }
    $repository = \Drupal::service('entity_display.repository');
    foreach (self::FIELDS as $bundle => $fields) {
      $form = $repository->getFormDisplay('node', $bundle);
      $view = $repository->getViewDisplay('node', $bundle);
      $weight = 10;
      foreach ($fields as $name => [$label, $required, $settings]) {
        if (!FieldConfig::loadByName('node', $bundle, $name)) {
          FieldConfig::create([
            'field_name' => $name,
            'entity_type' => 'node',
            'bundle' => $bundle,
            'label' => $label,
            'required' => $required,
            'settings' => $settings,
          ])->save();
        }
        $form->setComponent($name, ['weight' => $weight]);
        $view->setComponent($name, ['weight' => $weight, 'label' => 'inline']);
        $weight++;
      }
      $form->save();
      $view->save();
    }
  }

}
