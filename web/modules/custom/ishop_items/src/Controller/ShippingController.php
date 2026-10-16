<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\ShipBy;
use Drupal\ishop_items\Shipping;
use Drupal\ishop_items\Stale;
use Drupal\ishop_items\Tracking;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The Shipping room (Phase 10 A): to ship, on the way, delivered.
 *
 * To ship: oldest sale first, with its ship-by date, label PDF, weight,
 * package size and packing note. Shipped records the tracking number; the
 * carrier's own page tracks it; Delivered finishes the sale on the Money page.
 */
final class ShippingController extends ControllerBase {

  public function __construct(
    private readonly Shipping $shipping,
  ) {}

  /**
   * The page.
   */
  public function page(): array {
    $today = Stale::today();
    $to_ship = array_map(fn ($sale) => $this->toShipRow($sale, $today), $this->shipping->toShip());
    $on_the_way = array_map(fn ($sale) => $this->onTheWayRow($sale), $this->shipping->onTheWay());
    $delivered = array_map(fn ($sale) => $this->deliveredRow($sale), $this->shipping->delivered());
    return [
      '#type' => 'inline_template',
      '#template' => '<p class="rm-ship__intro">{{ intro }}</p>
<section class="rm-ship" id="to-ship" aria-labelledby="rm-ship-to"><h2 id="rm-ship-to" class="rm-section-title">{{ to_title }} <span class="rm-ship__count">{{ to_ship|length }}</span></h2>
{% for r in to_ship %}<article class="rm-card rm-ship__sale{% if r.urgent %} is-urgent{% endif %}" id="sale-{{ r.id }}">
<div class="rm-ship__head">{% if r.thumb %}<img class="rm-ship__thumb" src="{{ r.thumb }}" alt="" width="64" height="64">{% else %}<span class="rm-ship__thumb" aria-hidden="true"></span>{% endif %}<div class="rm-ship__name"><h3><a href="{{ r.item_url }}">{{ r.title }}</a></h3><p class="rm-ship__meta">{{ r.meta }}</p></div></div>
<p class="rm-ship__when"><span class="rm-chip rm-chip--{{ r.chip }}">{{ r.ship_by }}</span>{% if r.estimated %} <span class="rm-ship__est">{{ estimated }}</span>{% endif %}</p>
<dl class="rm-ship__facts">{% for f in r.facts %}<dt>{{ f.label }}</dt><dd>{% if f.url %}<a href="{{ f.url }}">{{ f.value }}</a>{% else %}{{ f.value }}{% endif %}</dd>{% endfor %}</dl>
<p class="rm-ship__buttons">{% if r.label %}<a class="rm-button rm-button--ghost" href="{{ r.label }}" target="_blank" rel="noopener">{{ open_label }}</a>{% endif %}<a class="rm-button rm-button--ghost" href="{{ r.label_form }}">{{ r.label ? change_label : add_label }}</a><a class="rm-button rm-button--gold" href="{{ r.shipped }}">{{ shipped }}</a></p>
</article>{% else %}<p class="rm-ship__empty">{{ nothing_to_ship }}</p>{% endfor %}</section>
<section class="rm-ship" id="on-the-way" aria-labelledby="rm-ship-way"><h2 id="rm-ship-way" class="rm-section-title">{{ way_title }} <span class="rm-ship__count">{{ on_the_way|length }}</span></h2>
{% for r in on_the_way %}<article class="rm-card rm-ship__sale" id="sale-{{ r.id }}"><div class="rm-ship__name"><h3><a href="{{ r.item_url }}">{{ r.title }}</a></h3><p class="rm-ship__meta">{{ r.meta }}</p></div>
<p class="rm-ship__buttons">{% if r.track %}<a class="rm-button rm-button--ghost" href="{{ r.track }}" target="_blank" rel="noopener noreferrer">{{ r.track_label }}</a>{% endif %}<a class="rm-button rm-button--gold" href="{{ r.delivered }}">{{ delivered_label }}</a><a class="rm-button rm-button--ghost rm-ship__undo" href="{{ r.undo }}">{{ undo_shipped }}</a></p></article>
{% else %}<p class="rm-ship__empty">{{ nothing_on_way }}</p>{% endfor %}</section>
<section class="rm-ship" id="delivered" aria-labelledby="rm-ship-done"><h2 id="rm-ship-done" class="rm-section-title">{{ done_title }}</h2>
{% if delivered %}<ul class="rm-ship__done">{% for r in delivered %}<li id="sale-{{ r.id }}"><span><a href="{{ r.item_url }}">{{ r.title }}</a> · {{ r.meta }}</span><a class="rm-ship__undo-link" href="{{ r.undo }}">{{ undo_delivered }}</a></li>{% endfor %}</ul>{% else %}<p class="rm-ship__empty">{{ nothing_done }}</p>{% endif %}</section>',
      '#context' => [
        'intro' => $this->t('Sales waiting to go out, oldest first. Add the label, pack it, then tap Shipped and type or paste the tracking number.'),
        'to_title' => $this->t('To ship'),
        'to_ship' => $to_ship,
        'estimated' => $this->t('(estimate: check the app)'),
        'open_label' => $this->t('Open label (PDF)'),
        'add_label' => $this->t('Add label PDF'),
        'change_label' => $this->t('Change label or date'),
        'shipped' => $this->t('Shipped'),
        'nothing_to_ship' => $this->t('Nothing to ship. When something sells, mark it "Sold here" and it shows up here.'),
        'way_title' => $this->t('On the way'),
        'on_the_way' => $on_the_way,
        'delivered_label' => $this->t('Delivered'),
        'undo_shipped' => $this->t('Undo Shipped'),
        'nothing_on_way' => $this->t('Nothing on the way.'),
        'done_title' => $this->t('Delivered in the last @days days', ['@days' => Shipping::DELIVERED_DAYS]),
        'delivered' => $delivered,
        'undo_delivered' => $this->t('Undo'),
        'nothing_done' => $this->t('No deliveries in the last @days days.', ['@days' => Shipping::DELIVERED_DAYS]),
      ],
      '#attached' => ['library' => ['ishop_matrix/matrix', 'ishop_items/shipping']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Shipped, Delivered and the two undos (CSRF-protected links).
   */
  public function act(PlatformListing $platform_listing, string $action): RedirectResponse {
    $item = $platform_listing->getItem();
    $sold = $platform_listing->getStatus() === Platforms::SOLD_HERE && $item;
    $shipped = !$platform_listing->get('date_shipped')->isEmpty();
    $delivered = !$platform_listing->get('date_delivered')->isEmpty();
    $args = ['@item' => $item?->label() ?? ''];
    $fragment = 'to-ship';
    if (!$sold) {
      $this->messenger()->addWarning($this->t('That listing is no longer a sale.'));
    }
    elseif ($action === 'delivered' && $shipped && !$delivered) {
      $this->shipping->markDelivered($platform_listing);
      $this->messenger()->addStatus($this->t('Delivered: @item. Touchdown!', $args));
      $fragment = 'delivered';
    }
    elseif ($action === 'undo-delivered' && $delivered) {
      $this->shipping->undoDelivered($platform_listing);
      $this->messenger()->addStatus($this->t('@item is back under On the way.', $args));
      $fragment = 'on-the-way';
    }
    elseif ($action === 'undo-shipped' && $shipped) {
      $this->shipping->undoShipped($platform_listing);
      $this->messenger()->addStatus($this->t('@item is back under To ship.', $args));
    }
    else {
      $this->messenger()->addStatus($this->t('Already done.'));
    }
    return $this->redirect('ishop_items.shipping_room', [], ['fragment' => $fragment]);
  }

  /**
   * A "to ship" card.
   */
  private function toShipRow(PlatformListing $sale, string $today): array {
    $item = $sale->getItem();
    $ship_by = (string) $sale->get('ship_by')->value;
    $status = $ship_by !== '' ? ShipBy::status($ship_by, $today) : NULL;
    $edit = $item->toUrl('edit-form')->toString();
    $weight = ItemHooks::measureText($item, 'field_weight');
    $size = ItemHooks::measureText($item, 'field_dimensions');
    $facts = [
      [
        'label' => $this->t('Weight'),
        'value' => $weight ?? $this->t('Not weighed yet: add it'),
        'url' => $weight === NULL ? $edit : NULL,
      ],
      [
        'label' => $this->t('Package'),
        'value' => $size ?? $this->t('No size yet: add it'),
        'url' => $size === NULL ? $edit : NULL,
      ],
    ];
    $note = trim((string) $item->get('field_packing_note')->value);
    if ($note !== '') {
      $facts[] = ['label' => $this->t('Packing note'), 'value' => $note, 'url' => NULL];
    }
    $label = $sale->get('shipping_label')->entity;
    return [
      'id' => $sale->id(),
      'title' => $item->label(),
      'item_url' => $item->toUrl()->toString(),
      'thumb' => $this->thumb($item),
      'meta' => $this->t('Sold on @platform, @date', [
        '@platform' => Platforms::PLATFORMS[$sale->getPlatform()],
        '@date' => $this->day((string) $sale->get('date_sold')->value),
      ]),
      'ship_by' => $status ? ShipBy::words($ship_by, $today) : $this->t('No ship-by date'),
      'chip' => $status && in_array($status['key'], ['late', 'today'], TRUE) ? 'alert' : 'todo',
      'urgent' => $status && in_array($status['key'], ['late', 'today'], TRUE),
      'estimated' => $status && (bool) $sale->get('ship_by_estimated')->value,
      'facts' => $facts,
      'label' => $label instanceof FileInterface ? $label->createFileUrl() : NULL,
      'label_form' => Url::fromRoute('ishop_items.shipping_label', ['platform_listing' => $sale->id()])->toString(),
      'shipped' => Url::fromRoute('ishop_items.shipping_shipped', ['platform_listing' => $sale->id()])->toString(),
    ];
  }

  /**
   * An "on the way" card.
   */
  private function onTheWayRow(PlatformListing $sale): array {
    $item = $sale->getItem();
    $carrier = (string) $sale->get('carrier')->value;
    $number = (string) $sale->get('tracking_number')->value;
    $how = $number !== '' ? sprintf('%s %s', Tracking::CARRIERS[$carrier] ?? '', $number) : (string) $this->t('no tracking number');
    return [
      'id' => $sale->id(),
      'title' => $item->label(),
      'item_url' => $item->toUrl()->toString(),
      'meta' => $this->t('@platform · shipped @date · @how', [
        '@platform' => Platforms::PLATFORMS[$sale->getPlatform()],
        '@date' => $this->day((string) $sale->get('date_shipped')->value),
        '@how' => trim($how),
      ]),
      'track' => Tracking::url($carrier, $number),
      'track_label' => $this->t('Track on @carrier', ['@carrier' => Tracking::CARRIERS[$carrier] ?? '']),
      'delivered' => $this->actUrl($sale, 'delivered'),
      'undo' => $this->actUrl($sale, 'undo-shipped'),
    ];
  }

  /**
   * A delivered line.
   */
  private function deliveredRow(PlatformListing $sale): array {
    $item = $sale->getItem();
    return [
      'id' => $sale->id(),
      'title' => $item->label(),
      'item_url' => $item->toUrl()->toString(),
      'meta' => $this->t('@platform · delivered @date', [
        '@platform' => Platforms::PLATFORMS[$sale->getPlatform()],
        '@date' => $this->day((string) $sale->get('date_delivered')->value),
      ]),
      'undo' => $this->actUrl($sale, 'undo-delivered'),
    ];
  }

  /**
   * A CSRF-protected action link.
   */
  private function actUrl(PlatformListing $sale, string $action): string {
    return Url::fromRoute('ishop_items.shipping_act', ['platform_listing' => $sale->id(), 'action' => $action])->toString();
  }

  /**
   * A Y-m-d date as "Fri Oct 16".
   */
  private function day(string $date): string {
    return $date === '' ? '' : (new \DateTimeImmutable($date))->format('D M j');
  }

  /**
   * The item's first photo as a thumbnail URL, or NULL.
   */
  private function thumb($item): ?string {
    $file = $item->get('field_photos')->entity?->get('field_media_image_1')->entity;
    return $file ? ImageStyle::load('thumbnail')->buildUrl($file->getFileUri()) : NULL;
  }

}
