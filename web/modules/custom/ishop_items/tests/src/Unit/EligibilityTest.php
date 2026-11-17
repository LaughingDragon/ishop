<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Eligibility as E;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the ported eligibility rules, including the old app's known traps.
 */
#[CoversClass(E::class)]
final class EligibilityTest extends UnitTestCase {

  /**
   * Classification by category, then by title and description.
   */
  #[DataProvider('kindProvider')]
  public function testClassify(array $item, string $kind): void {
    $this->assertSame($kind, E::classify($item));
  }

  /**
   * Items and their kind.
   */
  public static function kindProvider(): array {
    return [
      "women's before men's" => [['category' => "Women's Clothing > Dresses"], 'womens_wear'],
      "men's" => [['category' => "Men's Clothing > Jackets"], 'mens_wear'],
      'office accessories are not clothing' => [['category' => 'Office & Desk Accessories > Organizers'], 'office'],
      'site category Electronics' => [['category' => 'Electronics'], 'electronics'],
      'site category Office & Craft' => [['category' => 'Office & Craft'], 'office'],
      'site category Shoes' => [['category' => 'Shoes'], 'unisex_wear'],
      'site category Toys & Hobbies' => [['category' => 'Toys & Hobbies'], 'toy'],
      'no category: title decides' => [['category' => '', 'title' => 'Ravensburger puzzle 1000 pieces'], 'toy'],
      'nothing to go on' => [['category' => 'Other', 'title' => 'Thing'], 'other'],
    ];
  }

  /**
   * Verdicts, flags and overrides on real-world cases.
   */
  public function testEvaluate(): void {
    $lego = ['category' => 'Toys & Hobbies', 'title' => 'LEGO set'];
    $this->assertSame(E::NO, E::evaluate($lego, 'grailed')['verdict']);
    $this->assertSame(E::YES, E::evaluate($lego, 'mercari')['verdict']);

    $scarf = ['category' => "Women's Clothing > Scarves", 'title' => 'Heated scarf, rechargeable battery'];
    $this->assertSame(['powered'], E::flags($scarf));
    $this->assertSame(E::NO, E::evaluate($scarf, 'depop')['verdict']);
    $this->assertSame(E::YES, E::evaluate($scarf, 'grailed')['verdict']);

    $cleaner = ['category' => 'Home & Kitchen', 'title' => 'Oven cleaner 3-pack'];
    $this->assertSame(E::NO, E::evaluate($cleaner, 'vinted')['verdict']);
    $this->assertSame(E::CHECK, E::evaluate($cleaner, 'mercari')['verdict']);

    $mattress = ['category' => 'Home & Kitchen', 'title' => 'Queen size mattress'];
    $this->assertSame(E::YES, E::evaluate($mattress, 'facebook')['verdict']);
    $this->assertSame(E::NO, E::evaluate(['category' => 'Shoes', 'title' => 'Sneakers'], 'facebook')['verdict']);

    $overridden = E::evaluate($lego + ['override' => E::YES, 'override_note' => 'Lego minifig keychain'], 'grailed');
    $this->assertSame(E::YES, $overridden['verdict']);
    $this->assertSame('Lego minifig keychain', $overridden['reason']);
    $this->assertTrue($overridden['overridden']);
  }

}
