<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\PhotoPrep;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the pure parts of photo preparation.
 */
#[CoversClass(PhotoPrep::class)]
final class PhotoPrepTest extends UnitTestCase {

  /**
   * Names end in .jpg after preparing.
   */
  public function testJpegName(): void {
    $this->assertSame('IMG_0001.jpg', PhotoPrep::jpegName('IMG_0001.HEIC'));
    $this->assertSame('private://items/2026-10/a.jpg', PhotoPrep::jpegName('private://items/2026-10/a.heif'));
    $this->assertSame('a.b.jpg', PhotoPrep::jpegName('a.b.png'));
    $this->assertSame('noext.jpg', PhotoPrep::jpegName('noext'));
    $this->assertSame('photo.jpg', PhotoPrep::jpegName('.heic'));
  }

  /**
   * HEIC is recognised by content.
   */
  public function testIsHeif(): void {
    $this->assertTrue(PhotoPrep::isHeif("\0\0\0\x18ftypheic\0\0\0\0"));
    $this->assertTrue(PhotoPrep::isHeif("\0\0\0\x1cftypmif1\0\0\0\0"));
    $this->assertFalse(PhotoPrep::isHeif("\xFF\xD8\xFF\xE0\0\x10JFIF\0\1\1\0"));
    $this->assertFalse(PhotoPrep::isHeif("\0\0\0\x18ftypavif\0\0\0\0"));
    $this->assertFalse(PhotoPrep::isHeif('short'));
  }

}
