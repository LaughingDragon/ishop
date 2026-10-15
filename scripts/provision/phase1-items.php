<?php

/**
 * @file
 * Phase 1 step 2: taxonomies, the Item photo media type and the Item type.
 *
 * Idempotent: creates what is missing, leaves what exists. The result is
 * exported to config/sync; this script records how it was built.
 *
 *   vendor/bin/drush php:script scripts/provision/phase1-items.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;

$say = fn (string $m) => print("$m\n");

// 1. Vocabularies and starting terms.
$vocabularies = [
  'condition' => ['Condition', 'Item condition. Mapped to each platform\'s own words in Phase 3.'],
  'category' => ['Category', 'Two levels at most. Second-level terms are added as items need them.'],
  'brand' => ['Brand', 'Grows as brands are typed on items.'],
  'storage_location' => ['Storage location', 'Where an item is kept (from Cody, 2026-10-15).'],
  'purchase_source' => ['Purchase source', 'Where an item was bought.'],
];
foreach ($vocabularies as $vid => [$name, $description]) {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name, 'description' => $description])->save();
    $say("vocabulary $vid created");
  }
}

/**
 * Finds or creates a term; returns it.
 */
$term = function (string $vid, string $name, int $weight = 0, ?Term $parent = NULL): Term {
  $props = ['vid' => $vid, 'name' => $name];
  $found = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties($props);
  foreach ($found as $t) {
    $parents = array_column($t->get('parent')->getValue(), 'target_id');
    if (in_array((string) ($parent?->id() ?? 0), array_map('strval', $parents), TRUE)) {
      return $t;
    }
  }
  $t = Term::create($props + ['weight' => $weight, 'parent' => $parent ? [$parent->id()] : [0]]);
  $t->save();
  print("term $vid: $name\n");
  return $t;
};

foreach (['New with tags', 'New without tags', 'Like new', 'Good', 'Fair', 'Poor'] as $i => $name) {
  $term('condition', $name, $i);
}
foreach ([
  "Men's Clothing", "Women's Clothing", 'Shoes', 'Jewelry & Accessories', 'Toys & Hobbies',
  'Collectibles', 'Home & Kitchen', 'Electronics', 'Sporting Goods', 'Health & Beauty',
  'Office & Craft', 'Automotive', 'Other',
] as $i => $name) {
  $term('category', $name, $i);
}
$game_room = $term('storage_location', 'Game room', 0);
$table = $term('storage_location', 'Table', 0, $game_room);
foreach (['Table A', 'Table B', 'Table C'] as $i => $name) {
  $term('storage_location', $name, $i, $table);
}
$term('storage_location', 'Overflow', 1, $game_room);
$term('purchase_source', 'Amazon', 0);

// 2. Media type "Item photo", stored in private files.
$media_type = MediaType::load('item_photo');
if (!$media_type) {
  $media_type = MediaType::create([
    'id' => 'item_photo',
    'label' => 'Item photo',
    'description' => 'Photos of items for sale. Private; prepared on upload (JPEG, upright, no metadata, at most 2560 px).',
    'source' => 'image',
    'source_configuration' => ['source_field' => ''],
    'new_revision' => FALSE,
    'queue_thumbnail_downloads' => FALSE,
  ]);
  $media_type->save();
  $source_field = $media_type->getSource()->createSourceField($media_type);
  $source_field->getFieldStorageDefinition()->setSetting('uri_scheme', 'private')->save();
  $source_field->set('label', 'Photo');
  $source_field->setSettings([
    'file_directory' => 'items/[date:custom:Y-m]',
    'file_extensions' => 'jpg jpeg png webp heic heif',
    'max_filesize' => '32 MB',
    'alt_field' => FALSE,
    'alt_field_required' => FALSE,
    'title_field' => FALSE,
    'max_resolution' => '',
    'min_resolution' => '',
  ] + $source_field->getSettings());
  $source_field->save();
  $media_type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
  $say('media type item_photo created, source field ' . $source_field->getName());
}
$photo_field = $media_type->getSource()->getConfiguration()['source_field'];
foreach (['default', 'media_library'] as $mode) {
  $form = EntityFormDisplay::load("media.item_photo.$mode") ?? EntityFormDisplay::create(['targetEntityType' => 'media', 'bundle' => 'item_photo', 'mode' => $mode, 'status' => TRUE]);
  $form->setComponent($photo_field, ['type' => 'image_image', 'weight' => 0, 'settings' => ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']]);
  foreach (['name', 'uid', 'created', 'path', 'status'] as $hidden) {
    $form->removeComponent($hidden);
  }
  $form->save();
  $view = EntityViewDisplay::load("media.item_photo.$mode") ?? EntityViewDisplay::create(['targetEntityType' => 'media', 'bundle' => 'item_photo', 'mode' => $mode, 'status' => TRUE]);
  $view->setComponent($photo_field, ['type' => 'image', 'label' => 'hidden', 'settings' => ['image_style' => $mode === 'media_library' ? 'medium' : 'large', 'image_link' => 'file']]);
  foreach (['name', 'uid', 'created', 'thumbnail'] as $hidden) {
    $view->removeComponent($hidden);
  }
  $view->save();
}

// 3. Content type "Item".
if (!NodeType::load('item')) {
  NodeType::create([
    'type' => 'item',
    'name' => 'Item',
    'description' => 'Something for sale. Only photos are needed to start; the item page shows what is still missing before listing.',
    'new_revision' => TRUE,
    'preview_mode' => 0,
    'display_submitted' => FALSE,
  ])->save();
  $say('content type item created');
}

$fields = [
  // name => [type, label, storage settings, field settings, cardinality, required, description, default].
  'field_photos' => ['entity_reference', 'Photos', ['target_type' => 'media'], ['handler' => 'default:media', 'handler_settings' => ['target_bundles' => ['item_photo' => 'item_photo']]], -1, FALSE, 'The first photo is the cover.'],
  'field_description' => ['string_long', 'Description', [], [], 1, FALSE, 'Most platforms allow 1000 characters.'],
  'field_flaws' => ['string_long', 'Flaws and condition notes', [], [], 1, FALSE, 'Stains, wear, missing parts. Buyers trust honest notes.'],
  'field_brand' => ['entity_reference', 'Brand', ['target_type' => 'taxonomy_term'], ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['brand' => 'brand'], 'auto_create' => TRUE]], 1, FALSE, 'Type to pick, or type a new brand.'],
  'field_size' => ['string', 'Size', ['max_length' => 60], [], 1, FALSE, 'As on the tag, for example L, 2XLT, 32 x 30, 10.5.'],
  'field_color' => ['string', 'Color', ['max_length' => 60], [], 1, FALSE, ''],
  'field_category' => ['entity_reference', 'Category', ['target_type' => 'taxonomy_term'], ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['category' => 'category']]], 1, FALSE, ''],
  'field_condition' => ['entity_reference', 'Condition', ['target_type' => 'taxonomy_term'], ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['condition' => 'condition'], 'sort' => ['field' => 'weight', 'direction' => 'ASC']]], 1, FALSE, ''],
  'field_quantity' => ['integer', 'Quantity', ['unsigned' => TRUE], ['min' => 1], 1, TRUE, 'How many identical ones you have.', [['value' => 1]]],
  'field_item_status' => ['list_string', 'Status', ['allowed_values' => ['draft' => 'Draft', 'ready' => 'Ready', 'listed' => 'Listed', 'sold' => 'Sold', 'returned' => 'Returned to Amazon', 'donated' => 'Donated', 'kept' => 'Kept']], [], 1, TRUE, '', [['value' => 'draft']]],
  'field_asking_price' => ['decimal', 'Asking price', ['precision' => 10, 'scale' => 2], ['min' => 0, 'prefix' => '$'], 1, FALSE, ''],
  'field_ai_price_min' => ['decimal', 'AI price range: low', ['precision' => 10, 'scale' => 2], ['min' => 0, 'prefix' => '$'], 1, FALSE, 'Filled by the AI listing helper (Phase 7).'],
  'field_ai_price_max' => ['decimal', 'AI price range: high', ['precision' => 10, 'scale' => 2], ['min' => 0, 'prefix' => '$'], 1, FALSE, ''],
  'field_original_price' => ['decimal', 'Original retail price', ['precision' => 10, 'scale' => 2], ['min' => 0, 'prefix' => '$'], 1, FALSE, 'Poshmark asks for this.'],
  'field_cost_paid' => ['decimal', 'Cost paid', ['precision' => 10, 'scale' => 2], ['min' => 0, 'prefix' => '$'], 1, FALSE, ''],
  'field_purchase_date' => ['datetime', 'Purchase date', ['datetime_type' => 'date'], [], 1, FALSE, ''],
  'field_purchase_source' => ['entity_reference', 'Purchase source', ['target_type' => 'taxonomy_term'], ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['purchase_source' => 'purchase_source'], 'auto_create' => TRUE]], 1, FALSE, ''],
  'field_order_id' => ['string', 'Order ID', ['max_length' => 60], [], 1, FALSE, ''],
  'field_asin' => ['string', 'Amazon ASIN', ['max_length' => 10], [], 1, FALSE, '10 letters and numbers, on the Amazon product page.'],
  'field_return_by' => ['datetime', 'Return by', ['datetime_type' => 'date'], [], 1, FALSE, 'Last day to return it to the seller.'],
  'field_weight_oz' => ['decimal', 'Weight (oz, packed)', ['precision' => 8, 'scale' => 1], ['min' => 0, 'suffix' => ' oz'], 1, FALSE, '16 oz = 1 lb.'],
  'field_package_length' => ['decimal', 'Package length (in)', ['precision' => 6, 'scale' => 1], ['min' => 0, 'suffix' => ' in'], 1, FALSE, ''],
  'field_package_width' => ['decimal', 'Package width (in)', ['precision' => 6, 'scale' => 1], ['min' => 0, 'suffix' => ' in'], 1, FALSE, ''],
  'field_package_height' => ['decimal', 'Package height (in)', ['precision' => 6, 'scale' => 1], ['min' => 0, 'suffix' => ' in'], 1, FALSE, ''],
  'field_storage_location' => ['entity_reference', 'Storage location', ['target_type' => 'taxonomy_term'], ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['storage_location' => 'storage_location']]], 1, FALSE, '', [['target_uuid' => $table->uuid()]]],
  'field_internal_notes' => ['string_long', 'Internal notes', [], [], 1, FALSE, 'Only the crew sees these.'],
  'field_show_in_shop' => ['boolean', 'Show in shop', [], ['on_label' => 'Yes', 'off_label' => 'No'], 1, FALSE, 'For the public catalog (Phase 8, switched off).', [['value' => 0]]],
];
foreach ($fields as $name => $def) {
  [$type, $label, $storage_settings, $field_settings, $cardinality, $required, $description] = $def;
  $default = $def[7] ?? [];
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storage_settings,
    ])->save();
  }
  if (!FieldConfig::loadByName('node', 'item', $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'item',
      'label' => $label,
      'required' => $required,
      'description' => $description,
      'settings' => $field_settings,
      'default_value' => $default,
    ])->save();
    $say("field $name");
  }
}

// 4. Form display, in groups.
$form = EntityFormDisplay::load('node.item.default') ?? EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => 'item', 'mode' => 'default', 'status' => TRUE]);
$widgets = [
  'title' => ['string_textfield', []],
  'field_photos' => ['media_library_widget', ['media_types' => ['item_photo']]],
  'field_description' => ['string_textarea', ['rows' => 6]],
  'field_flaws' => ['string_textarea', ['rows' => 3]],
  'field_brand' => ['entity_reference_autocomplete', ['match_operator' => 'CONTAINS', 'size' => 40]],
  'field_size' => ['string_textfield', ['size' => 20]],
  'field_color' => ['string_textfield', ['size' => 20]],
  'field_category' => ['options_select', []],
  'field_condition' => ['options_buttons', []],
  'field_quantity' => ['number', []],
  'field_item_status' => ['options_select', []],
  'field_asking_price' => ['number', []],
  'field_ai_price_min' => ['number', []],
  'field_ai_price_max' => ['number', []],
  'field_original_price' => ['number', []],
  'field_cost_paid' => ['number', []],
  'field_purchase_date' => ['datetime_default', []],
  'field_purchase_source' => ['entity_reference_autocomplete', ['match_operator' => 'CONTAINS', 'size' => 30]],
  'field_order_id' => ['string_textfield', ['size' => 30]],
  'field_asin' => ['string_textfield', ['size' => 12]],
  'field_return_by' => ['datetime_default', []],
  'field_weight_oz' => ['number', []],
  'field_package_length' => ['number', []],
  'field_package_width' => ['number', []],
  'field_package_height' => ['number', []],
  'field_storage_location' => ['options_select', []],
  'field_internal_notes' => ['string_textarea', ['rows' => 3]],
  'field_show_in_shop' => ['boolean_checkbox', ['display_label' => TRUE]],
];
$weight = 0;
foreach ($widgets as $name => [$widget, $settings]) {
  $form->setComponent($name, ['type' => $widget, 'weight' => $weight++, 'settings' => $settings]);
}
foreach (['uid', 'created', 'promote', 'sticky', 'path'] as $hidden) {
  $form->removeComponent($hidden);
}
$groups = [
  'group_listing' => ['Listing', ['title', 'field_photos', 'field_description', 'field_flaws', 'field_brand', 'field_size', 'field_color', 'field_category', 'field_condition', 'field_quantity', 'field_item_status'], TRUE, 0],
  'group_prices' => ['Prices', ['field_asking_price', 'field_ai_price_min', 'field_ai_price_max', 'field_original_price'], TRUE, 1],
  'group_shipping' => ['Shipping', ['field_weight_oz', 'field_package_length', 'field_package_width', 'field_package_height'], FALSE, 2],
  'group_purchase' => ['Money and purchase', ['field_cost_paid', 'field_purchase_date', 'field_purchase_source', 'field_order_id', 'field_asin', 'field_return_by'], FALSE, 3],
  'group_storage' => ['Storage and notes', ['field_storage_location', 'field_internal_notes', 'field_show_in_shop'], FALSE, 4],
];
foreach ($groups as $group => [$label, $children, $open, $gweight]) {
  $form->setThirdPartySetting('field_group', $group, [
    'children' => $children,
    'label' => $label,
    'parent_name' => '',
    'region' => 'content',
    'weight' => 100 + $gweight,
    'format_type' => 'details',
    'format_settings' => ['open' => $open, 'description' => '', 'required_fields' => TRUE, 'id' => '', 'classes' => 'ishop-item-group'],
  ]);
}
$form->setComponent('status', ['type' => 'boolean_checkbox', 'weight' => 200, 'settings' => ['display_label' => TRUE]]);
$form->save();

// 5. View display.
$view = EntityViewDisplay::load('node.item.default') ?? EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'item', 'mode' => 'default', 'status' => TRUE]);
$weight = 0;
$view->setComponent('ishop_ready', ['weight' => $weight++]);
foreach ($widgets as $name => $unused) {
  if ($name === 'title') {
    continue;
  }
  $formatter = match ($fields[$name][0]) {
    'entity_reference' => $name === 'field_photos'
      ? ['type' => 'entity_reference_entity_view', 'settings' => ['view_mode' => 'media_library']]
      : ['type' => 'entity_reference_label', 'settings' => ['link' => FALSE]],
    'decimal' => ['type' => 'number_decimal', 'settings' => ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]],
    'datetime' => ['type' => 'datetime_custom', 'settings' => ['date_format' => 'Y-m-d']],
    'list_string' => ['type' => 'list_default', 'settings' => []],
    'boolean' => ['type' => 'boolean', 'settings' => ['format' => 'default']],
    default => ['type' => $fields[$name][0] === 'integer' ? 'number_integer' : 'basic_string', 'settings' => []],
  };
  $view->setComponent($name, $formatter + ['label' => $name === 'field_photos' ? 'hidden' : 'inline', 'weight' => $weight++]);
}
$view->removeComponent('links');
$view->save();

// 6. Permissions: site-foreman manages items and photos (not users).
Role::load('site_foreman')
  ->grantPermission('create item_photo media')
  ->grantPermission('edit any item_photo media')
  ->grantPermission('delete any item_photo media')
  ->grantPermission('access media overview')
  ->grantPermission('administer media types')
  ->grantPermission('administer media display')
  ->grantPermission('administer media fields')
  ->grantPermission('administer media form display')
  ->save();

$say('done');
