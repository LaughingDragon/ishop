<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ishop_items\ListingAccessControlHandler;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\ShipBy;
use Drupal\ishop_items\Tracking;
use Drupal\node\NodeInterface;
use Drupal\views\EntityViewsData;

/**
 * One item on one platform: its status, link, prices and fees.
 *
 * Each item has exactly one listing per platform (created with the item).
 * Revisionable, so every status change keeps its own small history.
 */
#[ContentEntityType(
  id: 'platform_listing',
  label: new TranslatableMarkup('Platform listing'),
  label_collection: new TranslatableMarkup('Platform listings'),
  label_singular: new TranslatableMarkup('platform listing'),
  label_plural: new TranslatableMarkup('platform listings'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'platform',
  ],
  handlers: [
    'access' => ListingAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'canonical' => '/listing/{platform_listing}',
    'edit-form' => '/listing/{platform_listing}/edit',
    'delete-form' => '/listing/{platform_listing}/delete',
  ],
  admin_permission: 'manage platform listings',
  base_table: 'platform_listing',
  revision_table: 'platform_listing_revision',
  show_revision_ui: TRUE,
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log',
  ],
  constraints: ['IshopUniqueItemPlatform' => []],
)]
final class PlatformListing extends ContentEntityBase implements RevisionLogInterface {

  use EntityChangedTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $platform = Platforms::PLATFORMS[$this->getPlatform()] ?? $this->getPlatform();
    $item = $this->getItem();
    return $item ? sprintf('%s: %s', $platform, $item->label()) : $platform;
  }

  /**
   * The item this listing belongs to.
   */
  public function getItem(): ?NodeInterface {
    $item = $this->get('item')->entity;
    return $item instanceof NodeInterface ? $item : NULL;
  }

  /**
   * Platform key, for example "depop".
   */
  public function getPlatform(): string {
    return (string) $this->get('platform')->value;
  }

  /**
   * {@inheritdoc}
   *
   * A listing that goes live gets today's date as "Date posted" if it has
   * none, so "Listed this season" counts every way of marking it Posted.
   * "Sold here" sets "Date sold" (Phase 4: days to sell, the Money page, the
   * tax export); un-marking the sale clears it.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $today = \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d');
    if (in_array($this->getStatus(), [Platforms::POSTED, Platforms::AT_RISK], TRUE) && $this->get('date_posted')->isEmpty()) {
      $this->set('date_posted', $today);
    }
    if ($this->getStatus() === Platforms::SOLD_HERE && $this->get('date_sold')->isEmpty()) {
      $this->set('date_sold', $today);
    }
    elseif ($this->getStatus() !== Platforms::SOLD_HERE && !$this->get('date_sold')->isEmpty()) {
      $this->set('date_sold', NULL);
    }
    // "Sold before the site" (date unknown) lasts until a real Date sold is
    // entered, or the sale is undone.
    if ($this->get('date_sold_unknown')->value) {
      $before = $this->isNew() ? NULL : $this->original?->get('date_sold')->value;
      if ($this->getStatus() !== Platforms::SOLD_HERE || ($before !== NULL && $before !== $this->get('date_sold')->value)) {
        $this->set('date_sold_unknown', FALSE);
      }
    }
    // The price book (Phase 7.5) keeps each sale's category and condition as
    // they were when it sold, so editing the item later doesn't move it.
    $item = $this->getItem();
    if ($this->getStatus() === Platforms::SOLD_HERE && $item && $this->get('sold_category')->isEmpty()) {
      $this->set('sold_category', $item->get('field_category')->target_id);
      $this->set('sold_condition', $item->get('field_condition')->target_id);
    }
    elseif ($this->getStatus() !== Platforms::SOLD_HERE) {
      $this->set('sold_category', NULL);
      $this->set('sold_condition', NULL);
    }
    // The Shipping room (Phase 10 A): a new sale gets an estimated ship-by
    // date from the platform's usual handling time until a real one is known.
    // Un-marking a sale that wasn't shipped clears it.
    if ($this->getStatus() === Platforms::SOLD_HERE && $this->get('ship_by')->isEmpty()) {
      $days = \Drupal::config('ishop_items.shipping')->get('handling_days.' . $this->getPlatform());
      $this->set('ship_by', ShipBy::estimate((string) $this->get('date_sold')->value, is_numeric($days) ? (int) $days : ShipBy::FALLBACK_DAYS));
      $this->set('ship_by_estimated', TRUE);
    }
    elseif ($this->getStatus() !== Platforms::SOLD_HERE && $this->get('date_shipped')->isEmpty()) {
      $this->set('ship_by', NULL);
      $this->set('ship_by_estimated', FALSE);
    }
  }

  /**
   * Status key, for example "posted".
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['item'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Item'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', ['target_bundles' => ['item' => 'item']])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 0]);

    $fields['platform'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Platform'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', Platforms::PLATFORMS)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 1]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(Platforms::TO_POST)
      ->setSetting('allowed_values', Platforms::STATUSES)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 2]);

    $fields['url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Listing URL'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'uri', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'uri_link', 'weight' => 3]);

    $money = [
      'listed_price' => t('Listed price'),
      'sold_price' => t('Sold price (what the buyer paid)'),
      'fees' => t('Platform fees'),
      'shipping_paid' => t('Shipping paid by seller'),
    ];
    $weight = 2;
    foreach ($money as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('decimal')
        ->setLabel($label)
        ->setRevisionable(TRUE)
        ->setSetting('precision', 10)
        ->setSetting('scale', 2)
        ->setSetting('min', 0)
        ->setSetting('prefix', '$')
        ->setDisplayOptions('form', ['type' => 'number', 'weight' => $weight])
        ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'number_decimal', 'weight' => $weight + 2]);
      $weight++;
    }

    $fields['date_sold'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date sold'))
      ->setDescription(t('Filled in when the listing is marked "Sold here"; cleared if the sale is un-marked.'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 11])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'datetime_custom',
        'settings' => ['date_format' => 'Y-m-d'],
        'weight' => 11,
      ]);

    // Liam, punch 622 part 2: an imported sale with no date in the
    // spreadsheet gets the import day as Date sold (it must have one) and
    // this flag, so it counts in All time only, never in a month or season.
    $fields['date_sold_unknown'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Sold before the site (date unknown)'))
      ->setDescription(t('Counted in All time only. Entering the real Date sold clears this.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setInitialValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 11,
        'settings' => ['display_label' => TRUE],
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'boolean',
        'settings' => [
          'format' => 'custom',
          'format_custom_true' => 'Sold before the site (date unknown)',
          'format_custom_false' => '',
        ],
        'weight' => 11,
      ]);

    $fields['date_posted'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date posted'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 10])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'datetime_custom',
        'settings' => ['date_format' => 'Y-m-d'],
        'weight' => 10,
      ]);

    $fields['eligibility_override'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Eligibility override'))
      ->setDescription(t('Leave empty to use the rules (Phase 3).'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', Platforms::ELIGIBILITY)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 11])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 11]);

    $fields['eligibility_note'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Eligibility note'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 12])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 12]);

    // The price book: the item's category and condition when it sold.
    $fields['sold_category'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Category when sold'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'taxonomy_term');
    $fields['sold_condition'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Condition when sold'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'taxonomy_term');

    // Bookkeeping for the "Sold here" cascade, so un-marking can undo it.
    $fields['cascade_sale'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Changed by the sale on'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'platform_listing');
    $fields['status_before_cascade'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status before the sale'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 32);
    $fields['item_status_before_sale'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Item status before this sale'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 32);

    $fields += static::shippingFieldDefinitions();

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

  /**
   * The Shipping room's fields on a sold listing (Phase 10 A).
   *
   * The label PDF is the only place a buyer's address is kept: a private
   * file, deleted Shipping::LABEL_KEEP_DAYS days after delivery.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   Field name => definition.
   */
  public static function shippingFieldDefinitions(): array {
    $date = fn ($label) => BaseFieldDefinition::create('datetime')
      ->setLabel($label)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'date');
    return [
      'ship_by' => $date(t('Ship by')),
      'ship_by_estimated' => BaseFieldDefinition::create('boolean')
        ->setLabel(t('Ship-by date is an estimate'))
        ->setRevisionable(TRUE)
        ->setDefaultValue(FALSE),
      'shipping_label' => BaseFieldDefinition::create('file')
        ->setLabel(t('Shipping label (PDF)'))
        ->setRevisionable(TRUE)
        ->setSetting('uri_scheme', 'private')
        ->setSetting('file_directory', 'shipping-labels')
        ->setSetting('file_extensions', 'pdf')
        ->setSetting('max_filesize', '10 MB'),
      'carrier' => BaseFieldDefinition::create('list_string')
        ->setLabel(t('Carrier'))
        ->setRevisionable(TRUE)
        ->setSetting('allowed_values', Tracking::CARRIERS),
      'tracking_number' => BaseFieldDefinition::create('string')
        ->setLabel(t('Tracking number'))
        ->setRevisionable(TRUE)
        ->setSetting('max_length', 64),
      'date_shipped' => $date(t('Date shipped')),
      'date_delivered' => $date(t('Date delivered')),
    ];
  }

}
