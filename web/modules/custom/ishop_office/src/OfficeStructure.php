<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;

/**
 * Creates the site office's text format, role, content types and fields.
 *
 * Runs once from hook_install(). After that the structure lives in exported
 * config (config/sync) like everything else; this class only skips what
 * already exists, so running it again is harmless.
 */
final class OfficeStructure {

  public const FORMAT = 'office_markdown';
  public const ROLE = 'site_office';

  public const BUNDLES = [
    'office_plan' => ['Office: plan', 'The roadmap, synced from ROADMAP.md by drush ishop:plans.'],
    'office_phase' => ['Office: phase', 'One row of the status board. Created and updated by drush ishop:plans.'],
    'office_report' => ['Office: build report', 'One per work session, filed with drush ishop:report.'],
    'office_punch' => ['Office: punch list item', 'An open question or decision waiting on a person.'],
    'office_decision' => ['Office: decision', 'What was decided, by whom, when and why.'],
  ];

  /**
   * Field storage: name => [type, storage settings, cardinality].
   */
  private const STORAGE = [
    'field_office_markdown' => ['text_long', [], 1],
    'field_phase_key' => ['string', ['max_length' => 16], 1],
    'field_phase_weight' => ['integer', [], 1],
    'field_phase_status' => ['list_string', ['allowed_values' => Vocabulary::PHASE_STATUS], 1],
    'field_checklist_done' => ['integer', [], 1],
    'field_checklist_total' => ['integer', [], 1],
    'field_latest_report' => ['entity_reference', ['target_type' => 'node'], 1],
    'field_office_phase' => ['entity_reference', ['target_type' => 'node'], 1],
    'field_office_date' => ['datetime', ['datetime_type' => 'date'], 1],
    'field_summary' => ['text_long', [], 1],
    'field_work_done' => ['text_long', [], 1],
    'field_next_steps' => ['text_long', [], 1],
    'field_blockers' => ['text_long', [], 1],
    'field_decisions_needed' => ['text_long', [], 1],
    'field_tests' => ['text_long', [], 1],
    'field_commits' => ['string', ['max_length' => 40], -1],
    'field_details' => ['text_long', [], 1],
    'field_instructions' => ['text_long', [], 1],
    'field_waiting_on' => ['list_string', ['allowed_values' => Vocabulary::PEOPLE], -1],
    'field_punch_status' => ['list_string', ['allowed_values' => Vocabulary::PUNCH_STATUS], 1],
    'field_closed_date' => ['datetime', ['datetime_type' => 'date'], 1],
    'field_answer' => ['text_long', [], 1],
    'field_answered_by' => ['list_string', ['allowed_values' => Vocabulary::PEOPLE], 1],
    'field_decided_by' => ['list_string', ['allowed_values' => Vocabulary::PEOPLE], -1],
    'field_why' => ['text_long', [], 1],
  ];

  /**
   * Fields per bundle, in display order: name => [label, required].
   */
  public const FIELDS = [
    'office_plan' => [
      'field_office_markdown' => ['Roadmap', TRUE],
    ],
    'office_phase' => [
      'field_phase_key' => ['Phase number', TRUE],
      'field_phase_status' => ['Status', TRUE],
      'field_checklist_done' => ['Checklist items done', FALSE],
      'field_checklist_total' => ['Checklist items', FALSE],
      'field_latest_report' => ['Latest report', FALSE],
      'field_office_markdown' => ['From the roadmap', FALSE],
      'field_phase_weight' => ['Order', FALSE],
    ],
    'office_report' => [
      'field_office_date' => ['Date', TRUE],
      'field_office_phase' => ['Phase', TRUE],
      'field_summary' => ['Summary', TRUE],
      'field_work_done' => ['What was done', TRUE],
      'field_next_steps' => ["What's next", FALSE],
      'field_blockers' => ['Blockers', FALSE],
      'field_decisions_needed' => ['Decisions needed from Liam or Cody', FALSE],
      'field_tests' => ['Tests run and results', TRUE],
      'field_commits' => ['Git commits', FALSE],
    ],
    'office_punch' => [
      'field_instructions' => ['What to do', FALSE],
      'field_punch_status' => ['Status', TRUE],
      'field_waiting_on' => ['Waiting on', TRUE],
      'field_office_date' => ['Opened', TRUE],
      'field_office_phase' => ['Phase', FALSE],
      'field_details' => ['Background', FALSE],
      'field_answer' => ['Answer', FALSE],
      'field_answered_by' => ['Answered by', FALSE],
      'field_closed_date' => ['Closed', FALSE],
    ],
    'office_decision' => [
      'field_office_date' => ['Decided on', TRUE],
      'field_decided_by' => ['Decided by', TRUE],
      'field_why' => ['Why', FALSE],
      'field_office_phase' => ['Phase', FALSE],
    ],
  ];

  /**
   * Long text fields rendered under their own <h2> heading.
   */
  public const SECTION_FIELDS = [
    'field_office_markdown', 'field_summary', 'field_work_done', 'field_next_steps', 'field_blockers',
    'field_decisions_needed', 'field_tests', 'field_instructions', 'field_details', 'field_answer',
    'field_why',
  ];

  /**
   * Creates whatever is missing.
   */
  public static function ensure(): void {
    self::ensureFormat();
    self::ensureRole();
    self::ensureTypes();
    self::ensureFields();
    self::ensureDisplays();
  }

  /**
   * Creates the Markdown text format used by every long text field.
   */
  private static function ensureFormat(): void {
    if (FilterFormat::load(self::FORMAT)) {
      return;
    }
    FilterFormat::create([
      'format' => self::FORMAT,
      'name' => 'Office Markdown',
      'weight' => 10,
      'filters' => [
        // Markdown first, with raw HTML stripped by league/commonmark...
        'markdown_easy' => [
          'id' => 'markdown_easy',
          'provider' => 'markdown_easy',
          'status' => TRUE,
          'weight' => -50,
          'settings' => ['flavor' => 'github'],
        ],
        // ...then only these tags survive.
        'filter_html' => [
          'id' => 'filter_html',
          'provider' => 'filter',
          'status' => TRUE,
          'weight' => -49,
          'settings' => [
            'allowed_html' => '<a href hreflang> <em> <strong> <cite> <blockquote cite> <code> <pre> <ul> <ol start> <li> <dl> <dt> <dd> <h2 id> <h3 id> <h4 id> <h5 id> <h6 id> <p> <br> <hr> <del> <table> <thead> <tbody> <tr> <th> <td> <input type checked disabled>',
            'filter_html_help' => FALSE,
            'filter_html_nofollow' => TRUE,
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Creates the site office role, or tops up its permissions.
   */
  private static function ensureRole(): void {
    $role = Role::load(self::ROLE) ?? Role::create(['id' => self::ROLE, 'label' => 'Site office']);
    foreach (['access site office', 'access content', 'use text format ' . self::FORMAT] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

  /**
   * Creates the content types, with revisions on and no front page promotion.
   */
  private static function ensureTypes(): void {
    foreach (self::BUNDLES as $id => [$label, $description]) {
      if (NodeType::load($id)) {
        continue;
      }
      NodeType::create([
        'type' => $id,
        'name' => $label,
        'description' => $description,
        'new_revision' => TRUE,
        'preview_mode' => 0,
        'display_submitted' => $id === 'office_report',
      ])->save();
      // Nothing in the office belongs on the front page.
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $id);
      $fields['promote']->getConfig($id)->setDefaultValue(FALSE)->save();
    }
  }

  /**
   * Creates field storages and per-bundle fields.
   */
  private static function ensureFields(): void {
    foreach (self::STORAGE as $name => [$type, $settings, $cardinality]) {
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
    foreach (self::FIELDS as $bundle => $fields) {
      foreach ($fields as $name => [$label, $required]) {
        if (FieldConfig::loadByName('node', $bundle, $name)) {
          continue;
        }
        $settings = [];
        $type = self::STORAGE[$name][0];
        if ($type === 'text_long') {
          $settings['allowed_formats'] = [self::FORMAT];
        }
        if ($type === 'entity_reference') {
          $target = $name === 'field_latest_report' ? 'office_report' : 'office_phase';
          $settings = [
            'handler' => 'default:node',
            'handler_settings' => ['target_bundles' => [$target => $target], 'sort' => ['field' => '_none']],
          ];
        }
        $field = FieldConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'bundle' => $bundle,
          'label' => $label,
          'required' => $required,
          'settings' => $settings,
        ]);
        if ($name === 'field_phase_status') {
          $field->setDefaultValue('not_started');
        }
        if ($name === 'field_punch_status') {
          $field->setDefaultValue('open');
        }
        if ($name === 'field_office_date') {
          $field->setDefaultValue([['default_date_type' => 'now', 'default_date' => 'now']]);
        }
        $field->save();
      }
    }
  }

  /**
   * Puts every field on the edit form and the page, in order.
   */
  private static function ensureDisplays(): void {
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $repository */
    $repository = \Drupal::service('entity_display.repository');
    foreach (self::FIELDS as $bundle => $fields) {
      $form = $repository->getFormDisplay('node', $bundle);
      $view = $repository->getViewDisplay('node', $bundle);
      $weight = 10;
      foreach (array_keys($fields) as $name) {
        $type = self::STORAGE[$name][0];
        $widget = match ($type) {
          'list_string' => 'options_buttons',
          'entity_reference' => 'options_select',
          default => NULL,
        };
        $form->setComponent($name, ['weight' => $weight] + ($widget ? ['type' => $widget] : []));
        $formatter = match ($type) {
          'datetime' => ['type' => 'datetime_custom', 'settings' => ['date_format' => 'Y-m-d']],
          'entity_reference' => ['type' => 'entity_reference_label', 'settings' => ['link' => TRUE]],
          default => [],
        };
        $label = in_array($name, self::SECTION_FIELDS, TRUE) ? 'hidden' : 'inline';
        if ($name === 'field_phase_weight') {
          $view->removeComponent($name);
        }
        else {
          $view->setComponent($name, ['weight' => $weight, 'label' => $label] + $formatter);
        }
        $weight++;
      }
      $view->removeComponent('links');
      $form->save();
      $view->save();
    }
  }

}
