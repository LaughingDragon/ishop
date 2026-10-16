<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\ishop_items\Entity\PlatformListing;

/**
 * The Shipping room (Phase 10 A): sales to pack, on the way, delivered.
 *
 * A sale is a listing marked "Sold here". It is "to ship" until Shipped
 * records its tracking number, "on the way" until Delivered, then
 * "delivered". Every change is a new listing revision, and each one can be
 * undone from the room.
 *
 * The label PDF holds the buyer's address, so it is the only place the site
 * keeps one: a private file, deleted label_keep_days after delivery.
 */
final class Shipping {

  public const TO_SHIP = 'to_ship';
  public const ON_THE_WAY = 'on_the_way';
  public const DELIVERED = 'delivered';

  /**
   * Stages, in order.
   */
  public const STAGES = [
    self::TO_SHIP => 'To ship',
    self::ON_THE_WAY => 'On the way',
    self::DELIVERED => 'Delivered',
  ];

  /**
   * How many days of deliveries the room shows.
   */
  public const DELIVERED_DAYS = 30;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ListingManager $listings,
  ) {}

  /**
   * A sale's stage from its shipped and delivered dates.
   */
  public static function stage(?string $shipped, ?string $delivered): string {
    return match (TRUE) {
      (string) $delivered !== '' => self::DELIVERED,
      (string) $shipped !== '' => self::ON_THE_WAY,
      default => self::TO_SHIP,
    };
  }

  /**
   * Sales not shipped yet, oldest sale first.
   *
   * @return \Drupal\ishop_items\Entity\PlatformListing[]
   *   Sold listings whose item still exists.
   */
  public function toShip(): array {
    $query = $this->sales()->notExists('date_shipped')->sort('date_sold')->sort('id');
    return $this->load($query->execute());
  }

  /**
   * Shipped and not delivered yet, oldest shipment first.
   *
   * @return \Drupal\ishop_items\Entity\PlatformListing[]
   *   Sold listings.
   */
  public function onTheWay(): array {
    $query = $this->sales()->exists('date_shipped')->notExists('date_delivered')->sort('date_shipped')->sort('id');
    return $this->load($query->execute());
  }

  /**
   * Delivered in the last DELIVERED_DAYS days, newest first.
   *
   * @return \Drupal\ishop_items\Entity\PlatformListing[]
   *   Sold listings.
   */
  public function delivered(): array {
    $since = (new \DateTimeImmutable(Stale::today()))->modify('-' . self::DELIVERED_DAYS . ' days')->format('Y-m-d');
    $query = $this->sales()->condition('date_delivered', $since, '>=')->sort('date_delivered', 'DESC')->sort('id', 'DESC');
    return $this->load($query->execute());
  }

  /**
   * Records the shipment: tracking number, carrier, today's date.
   *
   * @param \Drupal\ishop_items\Entity\PlatformListing $sale
   *   A "Sold here" listing.
   * @param string $number
   *   The tracking number as typed or scanned (may be empty: handed over).
   * @param string $carrier
   *   A Tracking::CARRIERS key.
   * @param float|null $postage
   *   Postage paid by the seller, if any (Money: shipping paid).
   */
  public function markShipped(PlatformListing $sale, string $number, string $carrier, ?float $postage): void {
    $number = Tracking::normalize($number);
    $sale->set('tracking_number', $number === '' ? NULL : $number);
    $sale->set('carrier', $carrier === '' ? NULL : $carrier);
    $sale->set('date_shipped', Stale::today());
    if ($postage !== NULL) {
      $sale->set('shipping_paid', $postage);
    }
    $this->listings->saveRevision($sale, $number === ''
      ? 'Shipped (no tracking number).'
      : sprintf('Shipped: %s %s.', Tracking::CARRIERS[$carrier] ?? $carrier, $number));
  }

  /**
   * Marks a shipment delivered today.
   */
  public function markDelivered(PlatformListing $sale): void {
    $sale->set('date_delivered', Stale::today());
    $this->listings->saveRevision($sale, 'Delivered.');
  }

  /**
   * Undoes Shipped (back to "to ship"; tracking number and carrier cleared).
   */
  public function undoShipped(PlatformListing $sale): void {
    $sale->set('date_shipped', NULL);
    $sale->set('date_delivered', NULL);
    $sale->set('tracking_number', NULL);
    $sale->set('carrier', NULL);
    $this->listings->saveRevision($sale, 'Shipped undone.');
  }

  /**
   * Undoes Delivered (back to "on the way").
   */
  public function undoDelivered(PlatformListing $sale): void {
    $sale->set('date_delivered', NULL);
    $this->listings->saveRevision($sale, 'Delivered undone.');
  }

  /**
   * Sets the real ship-by date (no longer an estimate).
   */
  public function setShipBy(PlatformListing $sale, string $date, string $why): void {
    $sale->set('ship_by', $date);
    $sale->set('ship_by_estimated', FALSE);
    $this->listings->saveRevision($sale, $why);
  }

  /**
   * Attaches a label PDF; a label it replaces is deleted.
   */
  public function setLabel(PlatformListing $sale, FileInterface $file, string $why): void {
    $old = $sale->get('shipping_label')->entity;
    $sale->set('shipping_label', $file->id());
    $this->listings->saveRevision($sale, $why);
    if ($old instanceof FileInterface && $old->id() !== $file->id()) {
      $old->delete();
    }
  }

  /**
   * Deletes labels (buyer addresses) label_keep_days after delivery.
   *
   * A shipment never marked Delivered keeps its label 30 days longer,
   * counted from the day it shipped.
   *
   * @return int
   *   How many labels were deleted.
   */
  public function deleteOldLabels(): int {
    $days = (int) ($this->configFactory->get('ishop_items.shipping')->get('label_keep_days') ?? 30);
    $before = (new \DateTimeImmutable(Stale::today()))->modify("-$days days")->format('Y-m-d');
    $delivered = $this->sales()->exists('shipping_label')->condition('date_delivered', $before, '<')->execute();
    // Never marked Delivered: kept another 30 days after shipping, then gone.
    $shipped_before = (new \DateTimeImmutable($before))->modify('-30 days')->format('Y-m-d');
    $shipped = $this->sales()->exists('shipping_label')->notExists('date_delivered')->condition('date_shipped', $shipped_before, '<')->execute();
    $deleted = 0;
    foreach ($this->load(array_merge($delivered, $shipped)) as $sale) {
      $file = $sale->get('shipping_label')->entity;
      $sale->set('shipping_label', NULL);
      $this->listings->saveRevision($sale, sprintf('Shipping label deleted %d days after delivery (buyer address).', $days));
      $file?->delete();
      $deleted++;
    }
    return $deleted;
  }

  /**
   * A query for listings marked Sold here.
   */
  private function sales() {
    return $this->entityTypeManager->getStorage('platform_listing')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', Platforms::SOLD_HERE);
  }

  /**
   * Loads listings in the given order, skipping any without an item.
   */
  private function load(array $ids): array {
    $listings = $this->entityTypeManager->getStorage('platform_listing')->loadMultiple($ids);
    return array_values(array_filter($listings, fn (PlatformListing $l) => $l->getItem() !== NULL));
  }

}
