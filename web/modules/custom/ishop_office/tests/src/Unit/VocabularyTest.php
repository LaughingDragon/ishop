<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_office\Unit;

use Drupal\ishop_office\Vocabulary;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests matching keys and labels.
 */
#[CoversClass(Vocabulary::class)]
#[Group('ishop_office')]
final class VocabularyTest extends UnitTestCase {

  /**
   * Tests matching by key and by label.
   */
  public function testKeysAndLabelsMatch(): void {
    $this->assertSame('in_progress', Vocabulary::normalize(Vocabulary::PHASE_STATUS, 'In progress'));
    $this->assertSame('in_progress', Vocabulary::normalize(Vocabulary::PHASE_STATUS, 'in-progress'));
    $this->assertSame('not_started', Vocabulary::normalize(Vocabulary::PHASE_STATUS, 'not_started'));
    $this->assertSame('cody', Vocabulary::normalize(Vocabulary::PEOPLE, 'Cody'));
    $this->assertSame('site_foreman', Vocabulary::normalize(Vocabulary::PEOPLE, 'site-foreman'));
    $this->assertSame('claude', Vocabulary::normalize(Vocabulary::PEOPLE, 'Claude (review)'));
    $this->assertSame('claude', Vocabulary::normalize(Vocabulary::PEOPLE, 'claude'));
    $this->assertSame('site_foreman', Vocabulary::ACCOUNTS['site-foreman']);
  }

  /**
   * Tests that unknown values are rejected.
   */
  public function testUnknownValueThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    Vocabulary::normalize(Vocabulary::PEOPLE, 'Bob');
  }

}
