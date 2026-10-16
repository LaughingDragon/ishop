<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\ishop_items\Tracking;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests tracking numbers and carrier links.
 */
#[CoversClass(Tracking::class)]
final class TrackingTest extends UnitTestCase {

  /**
   * Spaces and dashes go; a scanned USPS barcode loses 420 and the ZIP.
   */
  public function testNormalize(): void {
    $this->assertSame('9400111899223344556677', Tracking::normalize(' 9400 1118 9922 3344 5566 77 '));
    $this->assertSame('1Z999AA10123456784', Tracking::normalize('1z999aa1-0123456784'));
    $this->assertSame('9400111899223344556677', Tracking::normalize('420941039400111899223344556677'));
    $this->assertSame('9400111899223344556677', Tracking::normalize('4209410312349400111899223344556677'));
    $this->assertSame('', Tracking::normalize(' - '));
  }

  /**
   * Common formats map to their carrier; anything else is left to Cody.
   */
  public function testGuess(): void {
    $this->assertSame('usps', Tracking::guess('9400 1118 9922 3344 5566 77'));
    $this->assertSame('usps', Tracking::guess('92055901755477000000'));
    $this->assertNull(Tracking::guess('920559017554770000000000150000'));
    $this->assertSame('usps', Tracking::guess('EA123456789US'));
    $this->assertSame('ups', Tracking::guess('1Z999AA10123456784'));
    $this->assertSame('fedex', Tracking::guess('123456789012'));
    $this->assertSame('fedex', Tracking::guess('123456789012345'));
    $this->assertSame('dhl', Tracking::guess('1234567890'));
    $this->assertNull(Tracking::guess('ABC123'));
  }

  /**
   * Links go to the carrier's own page; Other has none.
   */
  public function testUrl(): void {
    $this->assertSame('https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223344556677', Tracking::url('usps', '9400 1118 9922 3344 5566 77'));
    $this->assertSame('https://www.ups.com/track?tracknum=1Z999AA10123456784', Tracking::url('ups', '1Z999AA10123456784'));
    $this->assertNull(Tracking::url('other', '12345'));
    $this->assertNull(Tracking::url('usps', ''));
  }

}
