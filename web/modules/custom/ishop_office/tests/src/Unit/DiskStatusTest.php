<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\DiskStatus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the disk report's arithmetic and warnings.
 */
#[CoversClass(DiskStatus::class)]
final class DiskStatusTest extends UnitTestCase {

  /**
   * Free share and the low-space flag.
   */
  public function testSummarize(): void {
    $this->assertSame(['free_bytes' => 25, 'total_bytes' => 100, 'free_percent' => 25, 'low' => FALSE], DiskStatus::summarize(25, 100));
    $this->assertTrue(DiskStatus::summarize(14, 100)['low']);
    $this->assertFalse(DiskStatus::summarize(15, 100)['low']);
  }

  /**
   * Problems: missing data disk and low space, in plain sentences.
   */
  public function testProblems(): void {
    $ok = [
      'data_disk_mounted' => TRUE,
      'boot_disk' => DiskStatus::summarize(40, 100),
      'data_disk' => DiskStatus::summarize(90, 100),
    ];
    $this->assertSame([], DiskStatus::problems($ok));
    $missing = [
      'data_disk_mounted' => FALSE,
      'boot_disk' => DiskStatus::summarize(10, 100),
      'data_disk' => NULL,
    ];
    $this->assertSame([
      'The data disk is not mounted: item photos and local backups are offline.',
      'Boot disk is low on space: 10% free.',
    ], DiskStatus::problems($missing));
  }

}
