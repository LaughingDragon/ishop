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
    'bill' => ['Bill', 'A bill or subscription with a due date. Shown in "Needs you now" before it is due.'],
    'event' => ['Event', 'Something on the Calendar wall, once or repeating.'],
    'todo' => ['To-do', 'Something to do, with an optional date. Kept in the Break room.'],
    'note' => ['Note', 'A note kept in the Break room.'],
    'document' => ['Document', 'A receipt, warranty, manual or other paper, kept privately in the Mailroom.'],
  ];

  /**
   * Kinds of document in the vault.
   */
  public const DOC_KINDS = [
    'receipt' => 'Receipt',
    'warranty' => 'Warranty',
    'manual' => 'Manual',
    'other' => 'Other',
  ];

  /**
   * How often a bill repeats.
   */
  public const REPEATS = [
    'monthly' => 'Every month',
    'yearly' => 'Every year',
    'weekly' => 'Every week',
    'once' => 'Just once',
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
   * Field storage: name => [type, settings, cardinality (default 1)].
   */
  private const STORAGE = [
    'field_log_date' => ['datetime', ['datetime_type' => 'date']],
    'field_trip' => ['string', ['max_length' => 120]],
    'field_miles' => ['decimal', ['precision' => 7, 'scale' => 1]],
    'field_amount' => ['decimal', ['precision' => 10, 'scale' => 2]],
    'field_expense_category' => ['list_string', ['allowed_values' => self::CATEGORIES]],
    'field_receipt' => ['file', ['uri_scheme' => 'private', 'target_type' => 'file']],
    'field_log_note' => ['string_long', []],
    'field_due_date' => ['datetime', ['datetime_type' => 'date']],
    'field_due_day' => ['integer', []],
    'field_repeat' => ['list_string', ['allowed_values' => self::REPEATS]],
    'field_last_paid' => ['datetime', ['datetime_type' => 'date']],
    'field_bill_business' => ['boolean', []],
    'field_event_date' => ['datetime', ['datetime_type' => 'date']],
    'field_event_time' => ['string', ['max_length' => 5]],
    'field_todo_due' => ['datetime', ['datetime_type' => 'date']],
    'field_todo_done' => ['boolean', []],
    'field_done_on' => ['datetime', ['datetime_type' => 'date']],
    'field_doc_kind' => ['list_string', ['allowed_values' => self::DOC_KINDS]],
    'field_doc_files' => ['file', ['uri_scheme' => 'private', 'target_type' => 'file'], -1],
    'field_doc_item' => ['entity_reference', ['target_type' => 'node']],
    'field_doc_until' => ['datetime', ['datetime_type' => 'date']],
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
    'bill' => [
      'field_amount' => ['Amount', FALSE, ['min' => 0, 'prefix' => '$']],
      'field_due_date' => ['Next due', TRUE, []],
      'field_repeat' => ['Repeats', TRUE, []],
      'field_bill_business' => ['Business expense (log it in Expenses when paid)', FALSE, []],
      'field_last_paid' => ['Last paid', FALSE, []],
      'field_due_day' => ['Day of the month it is due', FALSE, ['min' => 1, 'max' => 31]],
      'field_log_note' => ['Note', FALSE, []],
    ],
    'event' => [
      'field_event_date' => ['Date', TRUE, []],
      'field_event_time' => ['Time (optional, like 13:25)', FALSE, []],
      'field_repeat' => ['Repeats', TRUE, []],
      'field_due_day' => ['Day of the month', FALSE, ['min' => 1, 'max' => 31]],
      'field_log_note' => ['Note', FALSE, []],
    ],
    'todo' => [
      'field_todo_due' => ['Date (optional)', FALSE, []],
      'field_todo_done' => ['Done', FALSE, []],
      'field_done_on' => ['Done on', FALSE, []],
    ],
    'note' => [
      'field_log_note' => ['Text', FALSE, []],
    ],
    'document' => [
      'field_doc_kind' => ['Kind', TRUE, []],
      'field_doc_files' => ['Files', TRUE, [
        'file_directory' => 'vault/[date:custom:Y]-[date:custom:m]',
        'file_extensions' => 'jpg jpeg png webp heic heif pdf',
        'max_filesize' => '32 MB',
      ],
      ],
      'field_doc_item' => ['Item', FALSE, [
        'handler' => 'default:node',
        'handler_settings' => [
          'target_bundles' => ['item' => 'item'],
          'sort' => ['field' => 'title', 'direction' => 'ASC'],
        ],
      ],
      ],
      'field_doc_until' => ['Warranty until', FALSE, []],
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
    foreach (self::STORAGE as $name => $definition) {
      [$type, $settings] = $definition;
      $cardinality = $definition[2] ?? 1;
      if (!FieldStorageConfig::loadByName('node', $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'type' => $type,
          'settings' => $settings,
          'cardinality' => $cardinality,
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
