<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * One listing per item per platform.
 */
#[Constraint(
  id: 'IshopUniqueItemPlatform',
  label: new TranslatableMarkup('One listing per item per platform', [], ['context' => 'Validation']),
  type: ['entity:platform_listing'],
)]
final class UniqueItemPlatform extends SymfonyConstraint {

  /**
   * The message shown on a duplicate.
   */
  public string $message = 'This item already has a %platform listing.';

}
