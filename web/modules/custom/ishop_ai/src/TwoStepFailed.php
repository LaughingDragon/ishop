<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

/**
 * Step 1 of the two-step run gave nothing usable; its tokens still count.
 */
final class TwoStepFailed extends \RuntimeException {

  public function __construct(string $message, public readonly array $usage) {
    parent::__construct($message);
  }

}
