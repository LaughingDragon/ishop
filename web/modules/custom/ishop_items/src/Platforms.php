<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * The selling platforms and listing statuses (Phase 1, approved 2026-10-15).
 */
final class Platforms {

  /**
   * Platforms, in the order they are shown.
   */
  public const PLATFORMS = [
    'poshmark' => 'Poshmark',
    'mercari' => 'Mercari',
    'depop' => 'Depop',
    'grailed' => 'Grailed',
    'vinted' => 'Vinted',
    'facebook' => 'Facebook',
  ];

  public const TO_POST = 'to_post';
  public const POSTED = 'posted';
  public const SOLD_HERE = 'sold_here';
  public const TAKE_DOWN = 'take_down';
  public const NOT_NEEDED_SOLD = 'not_needed_sold';
  public const AT_RISK = 'at_risk';
  public const NOT_ALLOWED = 'not_allowed';

  /**
   * Listing statuses.
   */
  public const STATUSES = [
    self::TO_POST => 'To post',
    self::POSTED => 'Posted',
    self::SOLD_HERE => 'Sold here',
    self::TAKE_DOWN => 'Take down',
    self::NOT_NEEDED_SOLD => 'Not needed - sold',
    self::AT_RISK => 'Posted - at risk',
    self::NOT_ALLOWED => 'Not allowed',
  ];

  /**
   * Manual eligibility overrides (empty means "use the rule", Phase 3).
   */
  public const ELIGIBILITY = [
    'yes' => 'Yes',
    'no' => 'No',
    'check' => 'Check',
  ];

}
