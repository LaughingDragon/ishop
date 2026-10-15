<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\ReadyCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the ready-to-list checklist.
 */
#[CoversClass(ReadyCheck::class)]
final class ReadyCheckTest extends UnitTestCase {

  /**
   * A phone-added item misses everything but photos; a full one misses nothing.
   */
  public function testMissing(): void {
    $phone = [
      'photos' => 2,
      'title' => 'New item 2026-10-15 14:05',
      'description' => '',
      'category' => FALSE,
      'condition' => FALSE,
      'price' => NULL,
    ];
    $this->assertSame([
      ReadyCheck::ITEMS['title'],
      ReadyCheck::ITEMS['description'],
      ReadyCheck::ITEMS['category'],
      ReadyCheck::ITEMS['condition'],
      ReadyCheck::ITEMS['price'],
    ], ReadyCheck::missing($phone));

    $full = [
      'photos' => 1,
      'title' => 'Carhartt jacket L',
      'description' => 'Warm.',
      'category' => TRUE,
      'condition' => TRUE,
      'price' => '45.00',
    ];
    $this->assertSame([], ReadyCheck::missing($full));
    $this->assertSame([ReadyCheck::ITEMS['photos']], ReadyCheck::missing(['photos' => 0] + $full));
    $this->assertSame([ReadyCheck::ITEMS['price']], ReadyCheck::missing(['price' => '0.00'] + $full));
    $this->assertSame([ReadyCheck::ITEMS['description']], ReadyCheck::missing(['description' => '   '] + $full));
  }

}
