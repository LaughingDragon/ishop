<?php

/**
 * @file
 * Runs every enabled Backup and Migrate schedule (with its "keep" pruning).
 *
 * Called nightly by ishop-backup.timer:
 *   vendor/bin/drush php:script scripts/ishop-backup.php
 * B&M's own backup_migrate:schedule_backup command is interactive-only.
 *
 * B&M's hook_cron also runs any schedule whose period has passed, and core's
 * automated cron fires every 3 hours, so a short period gave several backups
 * a day and "keep 14" held only about 2 days. The schedules' period is now
 * 52 weeks (cron never finds them due) and this script always forces them,
 * so the timer is the only thing that runs them.
 */

use Drupal\backup_migrate\Entity\Schedule;

$bam = backup_migrate_get_service_object();
foreach (Schedule::loadMultiple() as $schedule) {
  if ($schedule->get('enabled')) {
    $schedule->run($bam, TRUE);
    \Drupal::logger('ishop_backup')->info('Ran backup schedule %id', ['%id' => $schedule->id()]);
  }
}
