<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ishop_items\ListingAccessControlHandler;
use Drupal\ishop_items\Platforms;
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

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
