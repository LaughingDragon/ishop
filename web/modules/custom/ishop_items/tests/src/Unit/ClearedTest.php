<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\ishop_items\Cleared;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the "Cleared to sell" check (decision 265).
 */
#[CoversClass(Cleared::class)]
final class ClearedTest extends UnitTestCase {

  /**
   * Only a ticked box clears an item; unticked or missing does not.
   */
  public function testIsCleared(): void {
    $this->assertTrue(Cleared::isCleared($this->item(TRUE, '1')));
    $this->assertFalse(Cleared::isCleared($this->item(TRUE, '0')));
    $this->assertFalse(Cleared::isCleared($this->item(TRUE, NULL)));
    $this->assertFalse(Cleared::isCleared($this->item(FALSE, NULL)));
  }

  /**
   * An item node with or without the field, holding $value.
   */
  private function item(bool $has_field, ?string $value): NodeInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($value);
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with(Cleared::FIELD)->willReturn($has_field);
    $node->method('get')->with(Cleared::FIELD)->willReturn($field);
    return $node;
  }

}
