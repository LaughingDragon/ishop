<?php

declare(strict_types=1);

namespace Drupal\Tests\ishop_inbox\Unit;

use Drupal\ishop_inbox\Classifier;
use Drupal\ishop_inbox\ListingRef;
use Drupal\ishop_inbox\Senders;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests reading marketplace emails.
 *
 * The samples are made up in the marketplaces' usual style; the real
 * inbox check (drush ishop:inbox-preview) is what proves the rules.
 */
#[CoversClass(Classifier::class)]
#[CoversClass(ListingRef::class)]
#[CoversClass(Senders::class)]
final class ClassifierTest extends UnitTestCase {

  /**
   * Subject, text, PDF attached => expected kind.
   */
  public static function kinds(): array {
    return [
      'ebay sold' => ['ebay', 'You made the sale for Vintage Pyrex Bowl', 'Ship by Oct 19', FALSE, Classifier::SOLD],
      'mercari sold' => ['mercari', 'Congrats! Your item sold', 'Item: Nike Air Max 90', FALSE, Classifier::SOLD],
      'poshmark sold' => ['poshmark', 'Your item sold!', 'Ship within 7 days', FALSE, Classifier::SOLD],
      'depop sold' => ['depop', 'You sold an item!', '', FALSE, Classifier::SOLD],
      'offer' => ['mercari', 'You have a new offer', 'offered $20.00', FALSE, Classifier::OFFER],
      'return outranks sold' => ['ebay', 'Return requested: the item you sold', '', FALSE, Classifier::RETURN],
      'case' => ['ebay', 'A case was opened: item not received', '', FALSE, Classifier::CASE],
      'delay' => ['mercari', "Your package hasn't been scanned", '', FALSE, Classifier::DELAY],
      'payout' => ['poshmark', 'Your payout is on its way', 'Payout of $42.10', FALSE, Classifier::PAYOUT],
      'label' => ['poshmark', 'Your shipping label', '', FALSE, Classifier::LABEL],
      'pdf only' => ['grailed', 'Order update', 'See attached', TRUE, Classifier::LABEL],
      'ship reminder' => ['mercari', 'Reminder to ship your item', '', FALSE, Classifier::SHIP_BY],
      'other' => ['depop', 'New message from a buyer', 'Is this still available?', FALSE, Classifier::OTHER],
      'facebook not marketplace' => ['facebook', 'You have 3 new notifications', '', FALSE, Classifier::IGNORE],
      'facebook marketplace' => ['facebook', 'Someone bought your item on Marketplace', '', FALSE, Classifier::SOLD],
    ];
  }

  /**
   * Each email gets the right kind.
   */
  #[DataProvider('kinds')]
  public function testKinds(string $platform, string $subject, string $text, bool $pdf, string $kind): void {
    $this->assertSame($kind, Classifier::classify($platform, $subject, $text, $pdf, '2026-10-16')['kind']);
  }

  /**
   * Titles come from the subject, then an "Item:" line.
   */
  public function testTitle(): void {
    $this->assertSame('Vintage Pyrex Bowl', Classifier::title('You made the sale for Vintage Pyrex Bowl', ''));
    $this->assertSame('Wool Scarf', Classifier::title('Your item “Wool Scarf” sold', ''));
    $this->assertSame('Nike Air Max 90', Classifier::title('Congrats! Your item sold', "Hi,\nItem: Nike Air Max 90\nPrice: \$45.00"));
    $this->assertSame('Blue Denim Jacket', Classifier::title('You sold an item!', "Item: Blue Denim Jacket\r\nThanks"));
    $this->assertNull(Classifier::title('Your item sold!', 'Nothing here'));
  }

  /**
   * Prices: the sale price, the offer, the payout.
   */
  public function testPrice(): void {
    $this->assertSame(45.0, Classifier::price(Classifier::SOLD, 'Sold!', "Item: X\nPrice: \$45.00\nTotal: \$51.20"));
    $this->assertSame(1250.0, Classifier::price(Classifier::SOLD, 'You sold it for $1,250', ''));
    $this->assertSame(20.0, Classifier::price(Classifier::OFFER, 'New offer', 'They offered $20.00 for your item.'));
    $this->assertSame(42.1, Classifier::price(Classifier::PAYOUT, 'Payout sent', 'Payout of $42.10 is on its way'));
    $this->assertNull(Classifier::price(Classifier::SOLD, 'Sold!', 'no money here'));
  }

  /**
   * Ship-by dates in the usual shapes; a year is added when missing.
   */
  public function testShipBy(): void {
    $this->assertSame('2026-10-19', Classifier::shipBy('Please ship by Oct 19.', '2026-10-16'));
    $this->assertSame('2026-10-19', Classifier::shipBy('Ship by: Monday, October 19, 2026', '2026-10-16'));
    $this->assertSame('2026-10-19', Classifier::shipBy('ship by 10/19', '2026-10-16'));
    $this->assertSame('2026-10-23', Classifier::shipBy('Ship within 7 days.', '2026-10-16'));
    $this->assertSame('2026-10-21', Classifier::shipBy('Ship within 3 business days.', '2026-10-16'));
    $this->assertSame('2027-01-04', Classifier::shipBy('Ship by Jan 4', '2026-12-30'));
    $this->assertNull(Classifier::shipBy('Ship by Feb 30', '2026-10-16'));
    $this->assertNull(Classifier::shipBy('Thanks for selling', '2026-10-16'));
  }

  /**
   * Listing IDs from plain and tracked links.
   */
  public function testListingRef(): void {
    $this->assertSame('123456789012', ListingRef::find('ebay', 'https://www.ebay.com/itm/123456789012?hash=x'));
    $this->assertSame('123456789012', ListingRef::find('ebay', 'https://www.ebay.com/itm/Vintage-Bowl/123456789012'));
    $this->assertSame('m12345678901', ListingRef::find('mercari', 'https://www.mercari.com/us/item/m12345678901/'));
    $this->assertSame('5f1e2d3c4b5a69788796a5b4', ListingRef::find('poshmark', 'https://poshmark.com/listing/Wool-Scarf-5f1e2d3c4b5a69788796a5b4'));
    $this->assertSame('m12345678901', ListingRef::find('mercari', 'https://click.example.com/?u=https%3A%2F%2Fwww.mercari.com%2Fus%2Fitem%2Fm12345678901%2F'));
    $this->assertSame('87654321', ListingRef::find('grailed', 'https://www.grailed.com/listings/87654321-nike-air'));
    $this->assertNull(ListingRef::find('depop', 'https://www.ebay.com/itm/123456789012'));
  }

  /**
   * Senders by domain; Gmail's DKIM or DMARC pass for that domain required.
   */
  public function testSenders(): void {
    $this->assertSame('ebay', Senders::platform('eBay <ebay@ebay.com>'));
    $this->assertSame('mercari', Senders::platform('no-reply@mail.mercari.com'));
    $this->assertSame('facebook', Senders::platform('Facebook <notification@facebookmail.com>'));
    $this->assertNull(Senders::platform('ebay@ebay.com.evil.example'));
    $this->assertNull(Senders::platform('not an address'));

    $good = ['mx.google.com; dkim=pass header.i=@ebay.com header.s=s1; spf=pass; dmarc=pass (p=REJECT) header.from=ebay.com'];
    $this->assertTrue(Senders::authentic($good, 'ebay'));
    $this->assertFalse(Senders::authentic($good, 'mercari'));
    $this->assertFalse(Senders::authentic(['mx.google.com; dkim=fail header.i=@ebay.com; dmarc=fail header.from=ebay.com'], 'ebay'));
    // A header the sender added itself doesn't count.
    $this->assertFalse(Senders::authentic(['evil.example; dkim=pass header.i=@ebay.com'], 'ebay'));
    $this->assertTrue(Senders::authentic(['mx.google.com; dkim=pass header.i=@mail.mercari.com header.s=x'], 'mercari'));

    $query = Senders::gmailQuery(14);
    $this->assertStringContainsString('from:(ebay.com OR poshmark.com', $query);
    $this->assertStringContainsString('newer_than:14d -category:promotions', $query);
  }

}
