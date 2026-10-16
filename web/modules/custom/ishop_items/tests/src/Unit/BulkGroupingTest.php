<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\BulkGrouping;
use Drupal\ishop_items\PhotoPrep;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests splitting a pile of photos into items by time taken.
 */
#[CoversClass(BulkGrouping::class)]
final class BulkGroupingTest extends UnitTestCase {

  /**
   * A 45-minute gap (or more) starts a new item; order is by time taken.
   */
  public function testSplit(): void {
    $t = strtotime('2026-10-15 14:00:00 UTC');
    $photos = [
      11 => $t,
      13 => $t + 120,
      12 => $t + 60,
      21 => $t + 120 + 45 * 60,
      22 => $t + 120 + 45 * 60 + 30,
      31 => $t + 5 * 3600,
    ];
    $this->assertSame([[11, 12, 13], [21, 22], [31]], BulkGrouping::split($photos));
    // Just under the gap stays together.
    $this->assertSame([[1, 2]], BulkGrouping::split([1 => $t, 2 => $t + 45 * 60 - 1]));
    // A wider gap merges, a narrow one splits.
    $this->assertSame([[11, 12, 13, 21, 22], [31]], BulkGrouping::split($photos, 120));
    $this->assertSame([[11], [12], [13], [21, 22], [31]], BulkGrouping::split($photos, 1));
    $this->assertSame([], BulkGrouping::split([]));
  }

  /**
   * EXIF dates are camera local time, read as Pacific.
   */
  public function testParseExifDate(): void {
    $this->assertSame(strtotime('2026-10-15 14:29:01 America/Los_Angeles'), PhotoPrep::parseExifDate("2026:10:15 14:29:01\n"));
    $this->assertNull(PhotoPrep::parseExifDate(''));
    $this->assertNull(PhotoPrep::parseExifDate('0000:00:00 00:00:00'));
  }

}
