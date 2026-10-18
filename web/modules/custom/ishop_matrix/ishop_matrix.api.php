<?php

/**
 * @file
 * Hooks provided by the Reseller Matrix.
 */

/**
 * Adds rows to "Needs you now" (the front desk and the morning digest).
 *
 * @param string $return
 *   The front desk's path, for buttons that come back to it.
 *
 * @return array[]
 *   Rows: 'text' and either 'url' + 'action' + 'ghost', or 'buttons' (each
 *   'label', 'url', 'ghost'). Rows with buttons are never cached. Other
 *   rows are cached with the front desk: invalidate the 'ishop_needs' cache
 *   tag when they change for any other reason than an item or listing save.
 */
function hook_ishop_needs(string $return): array {
  return [['text' => 'Something needs you.', 'url' => '/room/front-desk', 'action' => 'Look', 'ghost' => FALSE]];
}
