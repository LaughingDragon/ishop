<?php

declare(strict_types=1);

namespace Drupal\ishop_office;

/**
 * The two disks: is the data disk mounted, and how full is each one.
 *
 * The data disk (ishopway2muchdata, /srv/ishop-data) holds only item photos
 * and local backup files. The site and database never depend on it. Each of
 * its bind mounts carries a marker file; when the disk is missing, the empty
 * read-only mountpoint underneath has no marker.
 */
final class DiskStatus {

  /**
   * Marker file name at the top of each data-disk folder.
   */
  public const MARKER = '.ishop-data-disk';

  /**
   * Where the data disk is mounted.
   */
  public const DATA_MOUNT = '/srv/ishop-data';

  /**
   * Item photos folder (bind-mounted from the data disk).
   */
  public const PHOTOS_DIR = '/var/www/ishop/private/items';

  /**
   * Local backups folder (bind-mounted from the data disk).
   */
  public const BACKUPS_DIR = '/var/backups/ishop';

  /**
   * Warn when a disk has less than this share free.
   */
  public const LOW_FREE_PERCENT = 15;

  /**
   * Whether item photos can be shown and added.
   */
  public static function photosOnline(): bool {
    return is_file(self::PHOTOS_DIR . '/' . self::MARKER);
  }

  /**
   * Whether the local backups folder is on the data disk.
   */
  public static function backupsOnline(): bool {
    return is_file(self::BACKUPS_DIR . '/' . self::MARKER);
  }

  /**
   * Free and total space for a path, or NULL when it can't be read.
   *
   * @return array{free_bytes: int, total_bytes: int, free_percent: int, low: bool}|null
   *   The summary from summarize(), or NULL.
   */
  public static function space(string $path): ?array {
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    if ($free === FALSE || $total === FALSE || $total <= 0) {
      return NULL;
    }
    return self::summarize((int) $free, (int) $total);
  }

  /**
   * Turns byte counts into the reported shape.
   */
  public static function summarize(int $free, int $total): array {
    $percent = (int) floor(100 * $free / $total);
    return [
      'free_bytes' => $free,
      'total_bytes' => $total,
      'free_percent' => $percent,
      'low' => $percent < self::LOW_FREE_PERCENT,
    ];
  }

  /**
   * Everything, for status.json, the status board and (Phase 5) the digest.
   */
  public static function report(): array {
    $photos = self::photosOnline();
    $backups = self::backupsOnline();
    $mounted = $photos && $backups;
    return [
      'data_disk_mounted' => $mounted,
      'photos_online' => $photos,
      'local_backups_online' => $backups,
      'boot_disk' => self::space('/'),
      'data_disk' => $mounted ? self::space(self::DATA_MOUNT) : NULL,
    ];
  }

  /**
   * Problems worth showing, as short sentences (empty when all is well).
   */
  public static function problems(array $report): array {
    $problems = [];
    if (!$report['data_disk_mounted']) {
      $problems[] = 'The data disk is not mounted: item photos and local backups are offline.';
    }
    foreach (['boot_disk' => 'Boot disk', 'data_disk' => 'Data disk'] as $key => $label) {
      if (!empty($report[$key]['low'])) {
        $problems[] = sprintf('%s is low on space: %d%% free.', $label, $report[$key]['free_percent']);
      }
    }
    return $problems;
  }

}
