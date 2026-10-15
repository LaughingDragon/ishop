<?php

/**
 * @file
 * Runs every enabled Backup and Migrate schedule (with its "keep" pruning).
 *
 * Called nightly by ishop-backup.timer:
 *   vendor/bin/drush php:script scripts/ishop-backup.php
 * B&M's own backup_migrate:schedule_backup command is interactive-only.
 * Pass "--force" after "--" to run schedules that are not due yet.
 */

use Drupal\backup_migrate\Entity\Schedule;

$force = in_array('--force', $extra ?? [], TRUE);
$bam = backup_migrate_get_service_object();
foreach (Schedule::loadMultiple() as $schedule) {
  if ($schedule->get('enabled')) {
    $schedule->run($bam, $force);
    \Drupal::logger('ishop_backup')->info('Ran backup schedule %id', ['%id' => $schedule->id()]);
  }
}
