<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Return-by dates (Phase 6): about N days from the order date.
 *
 * Counted from the order date, not delivery (Amazon counts from delivery,
 * which is usually later), so the reminder comes early rather than late.
 * The holiday rule (orders Nov 1 to Dec 31 returnable until Jan 31) is a
 * setting, as are the days (Liam, decision 241).
 */
final class ReturnWindow {

  /**
   * The settings' config name.
   */
  public const CONFIG = 'ishop_items.returns';

  /**
   * Settings used when the config is missing.
   */
  public const DEFAULTS = [
    'days' => 30,
    'holiday' => TRUE,
    'holiday_from' => '11-01',
    'holiday_to' => '12-31',
    'holiday_until' => '01-31',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The current settings.
   */
  public function settings(): array {
    $config = $this->configFactory->get(self::CONFIG);
    $settings = [];
    foreach (self::DEFAULTS as $key => $default) {
      $settings[$key] = $config->get($key) ?? $default;
    }
    return $settings;
  }

  /**
   * The default return-by date (Y-m-d) for an order date.
   */
  public function defaultFor(string $order_date): string {
    return self::compute($order_date, $this->settings());
  }

  /**
   * Whether an item's return-by is the default for its purchase date.
   *
   * Then it is shown as "about": it was worked out, not read off the order.
   */
  public function isDefault(NodeInterface $item): bool {
    $by = (string) $item->get('field_return_by')->value;
    $bought = (string) $item->get('field_purchase_date')->value;
    return $by !== '' && $bought !== '' && $by === $this->defaultFor($bought);
  }

  /**
   * The return-by date for an order date and settings.
   *
   * @param string $order_date
   *   Y-m-d (anything after the first 10 characters is ignored).
   * @param array $settings
   *   As self::DEFAULTS.
   *
   * @return string
   *   Y-m-d.
   */
  public static function compute(string $order_date, array $settings): string {
    $settings += self::DEFAULTS;
    $order = new \DateTimeImmutable(substr($order_date, 0, 10) . ' 12:00');
    $by = $order->modify('+' . max(0, (int) $settings['days']) . ' days');
    $day = $order->format('m-d');
    if ($settings['holiday'] && $day >= $settings['holiday_from'] && $day <= $settings['holiday_to']) {
      // "Until Jan 31" after Nov-Dec orders is the next year's Jan 31.
      $year = (int) $order->format('Y') + ($settings['holiday_until'] < $settings['holiday_from'] ? 1 : 0);
      $until = new \DateTimeImmutable($year . '-' . $settings['holiday_until'] . ' 12:00');
      if ($until > $by) {
        $by = $until;
      }
    }
    return $by->format('Y-m-d');
  }

}
