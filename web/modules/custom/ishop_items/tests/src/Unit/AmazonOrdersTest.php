<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_items\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ishop_items\AmazonOrders;

/**
 * Tests reading Amazon's order history export.
 *
 * @group ishop_items
 * @coversDefaultClass \Drupal\ishop_items\AmazonOrders
 */
final class AmazonOrdersTest extends UnitTestCase {

  /**
   * A small file in Amazon's layout (most columns left out).
   */
  private const CSV = "\xEF\xBB\xBF" . '"Website","Order ID","Order Date","Currency","Unit Price","Unit Price Tax","ASIN","Product Condition","Quantity","Order Status","Shipping Option","Product Name"
"Amazon.com","112-1111111-1111111","2026-10-05T03:13:44Z","USD","24.99","2.06","B0AAAAAAAA","New","1","Closed","std-us","Wooden Puzzle, 1000 Pieces"
"Amazon.com","112-1111111-1111111","2026-10-05T03:13:44Z","USD","24.99","2.06","B0AAAAAAAA","New","1","Closed","std-us","Wooden Puzzle, 1000 Pieces"
"Amazon.com","112-2222222-2222222","2026-11-20T18:00:00.000Z","USD","1,049.00","Not Available","B0BBBBBBBB","New","2","Closed","next-1dc","Big ""TV"" Stand"
"Amazon.com","112-3333333-3333333","2026-09-01T12:00:00Z","USD","9.99","0.82","B0CCCCCCCC","New","1","Cancelled","std-us","Never Came"
"Amazon.com","D01-4444444-4444444","2026-09-02T12:00:00Z","USD","4.99","0","B0DDDDDDDD","New","1","Closed","Not Available","A Kindle Book"
';

  /**
   * Reads, merges shipments, skips cancelled and digital.
   *
   * @covers ::parse
   */
  public function testParse(): void {
    $result = ['rows' => [], 'skipped' => ['cancelled' => 0, 'digital' => 0]];
    AmazonOrders::parse(self::CSV, 'America/Los_Angeles', $result);
    $this->assertSame(['cancelled' => 1, 'digital' => 1], $result['skipped']);
    $this->assertCount(2, $result['rows']);
    $puzzle = $result['rows']['112-1111111-1111111|B0AAAAAAAA'];
    // 03:13 UTC on Oct 5 is still Oct 4 in California.
    $this->assertSame('2026-10-04', $puzzle['date']);
    $this->assertSame(2, $puzzle['quantity']);
    $this->assertSame(24.99, $puzzle['price']);
    $this->assertSame(2.06, $puzzle['tax']);
    $this->assertSame('Wooden Puzzle, 1000 Pieces', $puzzle['name']);
    $stand = $result['rows']['112-2222222-2222222|B0BBBBBBBB'];
    $this->assertSame(1049.0, $stand['price']);
    $this->assertSame(0.0, $stand['tax']);
    $this->assertSame('Big "TV" Stand', $stand['name']);
    $this->assertSame(2, $stand['quantity']);
  }

  /**
   * A file without the needed columns is refused.
   *
   * @covers ::parse
   */
  public function testNotAnOrderHistory(): void {
    $this->expectException(\InvalidArgumentException::class);
    $result = ['rows' => [], 'skipped' => ['cancelled' => 0, 'digital' => 0]];
    AmazonOrders::parse("Name,Email\nA,b@c.d\n", 'UTC', $result);
  }

  /**
   * A zip (with the CSV in a folder, and a digital file left alone).
   *
   * @covers ::read
   */
  public function testZip(): void {
    $path = tempnam(sys_get_temp_dir(), 'amz');
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::OVERWRITE);
    $zip->addFromString('Retail.OrderHistory.1/Retail.OrderHistory.1.csv', self::CSV);
    $zip->addFromString('Digital-Ordering.1/Digital Items.csv', 'x');
    $zip->close();
    try {
      $this->assertCount(2, AmazonOrders::read($path)['rows']);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Dates, digital checks and titles.
   *
   * @covers ::date
   * @covers ::isDigital
   * @covers ::title
   */
  public function testHelpers(): void {
    $this->assertSame('2026-03-05', AmazonOrders::date('03/05/2026', 'UTC'));
    $this->assertNull(AmazonOrders::date('soon', 'UTC'));
    $this->assertTrue(AmazonOrders::isDigital('D01-1234567-1234567'));
    $this->assertTrue(AmazonOrders::isDigital('112-1', 'Amazon.com', 'Digital delivery'));
    $this->assertFalse(AmazonOrders::isDigital('112-1', 'Amazon.com', 'std-us', 'New'));
    $long = 'Ultra Soft Throw Blanket for Couch and Bed, Lightweight Fuzzy Cozy Plush Fleece Blanket, 50x60 Inches, Grey';
    $title = AmazonOrders::title($long);
    $this->assertLessThanOrEqual(80, mb_strlen($title));
    $this->assertSame('Ultra Soft Throw Blanket for Couch and Bed, Lightweight Fuzzy Cozy Plush Fleece', $title);
    $this->assertSame('Short', AmazonOrders::title('  Short '));
  }

}
