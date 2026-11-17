<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\ishop_items\ItemPhotos;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * Tests the file names that pin a photo to its item.
 */
#[CoversClass(ItemPhotos::class)]
final class ItemPhotosTest extends UnitTestCase {

  /**
   * The service with only transliteration real.
   */
  private function photos(): ItemPhotos {
    return new ItemPhotos(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(AccountProxyInterface::class),
      new PhpTransliteration(),
      new NullLogger(),
    );
  }

  /**
   * Item number, a few title words, the file ID; the extension is kept.
   */
  public function testFileName(): void {
    $item = $this->createMock(NodeInterface::class);
    $item->method('id')->willReturn(374);
    $item->method('label')->willReturn('Set of Whitewashed Wood Planter Boxes 3 Compartment');
    $this->assertSame('item-374-set-of-whitewashed-wood-planter-boxes-3-185.jpg', $this->photos()->fileName($item, 185, 'IMG_4410.JPG'));
    $this->assertSame('item-374-', ItemPhotos::prefix(374));
  }

  /**
   * Accents, symbols and an empty title.
   */
  public function testSlug(): void {
    $photos = $this->photos();
    $this->assertSame('pokemon-cafe-mug-12-oz', $photos->slug('Pokémon Café Mug — 12 oz!'));
    $this->assertSame('item', $photos->slug('★★★'));
    $this->assertLessThanOrEqual(40, strlen($photos->slug(str_repeat('word ', 30))));
  }

}
