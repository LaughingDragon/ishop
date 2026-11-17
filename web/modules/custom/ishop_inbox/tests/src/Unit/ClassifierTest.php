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
      'grailed sold' => ['grailed', 'You made the sale for Vintage Pyrex Bowl', 'Ship by Oct 19', FALSE, Classifier::SOLD],
      'vinted sold' => ['vinted', 'You sold an item on Vinted', "Hello,\nbuyer has bought\nNike Hoodie\n\$16.00", FALSE, Classifier::SOLD],
      'depop sale with label' => ['depop', 'You’ve made a sale on Depop! Here’s your USPS shipping label for @someone', '', FALSE, Classifier::SOLD],
      'vinted shipped' => ['vinted', 'Order update for Nike Hoodie', 'Your package is on its way to the buyer!', FALSE, Classifier::SHIPPED],
      'mercari shipped' => ['mercari', 'Your item is on its way: Nike Hoodie', '', FALSE, Classifier::SHIPPED],
      'mercari delivered' => ['mercari', 'Your package has arrived: Nike Hoodie', '', FALSE, Classifier::DELIVERED],
      'vinted completed' => ['vinted', 'This order is completed', 'Your sale of Nike Hoodie was completed successfully.', FALSE, Classifier::DELIVERED],
      'listing live' => ['mercari', 'Your listing is live', 'https://www.mercari.com/us/item/m12345678901/', FALSE, Classifier::LISTED],
      'vinted favorited is not a sale' => ['vinted', 'Your Nike Hoodie was just favorited', 'Someone liked it', FALSE, Classifier::OTHER],
      'mercari sold' => ['mercari', 'Congrats! Your item sold', 'Item: Nike Air Max 90', FALSE, Classifier::SOLD],
      'poshmark sold' => ['poshmark', 'Your item sold!', 'Ship within 7 days', FALSE, Classifier::SOLD],
      'depop sold' => ['depop', 'You sold an item!', '', FALSE, Classifier::SOLD],
      'offer' => ['mercari', 'You have a new offer', 'offered $20.00', FALSE, Classifier::OFFER],
      'return outranks sold' => ['poshmark', 'Return requested: the item you sold', '', FALSE, Classifier::RETURN],
      'case' => ['mercari', 'A case was opened: item not received', '', FALSE, Classifier::CASE],
      'delay' => ['mercari', "Your package hasn't been scanned", '', FALSE, Classifier::DELAY],
      'payout' => ['poshmark', 'Your payout is on its way', 'Payout of $42.10', FALSE, Classifier::PAYOUT],
      'label' => ['poshmark', 'Your shipping label', '', FALSE, Classifier::LABEL],
      'pdf only' => ['grailed', 'Order update', 'See attached', TRUE, Classifier::LABEL],
      'ship reminder' => ['mercari', 'Reminder to ship your item', '', FALSE, Classifier::SHIP_BY],
      'buyer message' => ['depop', 'New message from a buyer', 'Is this still available?', FALSE, Classifier::MESSAGE],
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
   * Titles, prices, tracking and deadlines from real-shaped emails (2026-11-17).
   */
  public function testRealShapes(): void {
    $vinted = Classifier::classify('vinted', 'You sold an item on Vinted', "Hello reseller,\nbuyer9 has bought\nNike Sportswear Hoodie\n\$16.00\nPlease send this order within 5 days.", FALSE, '2026-11-14');
    $this->assertSame(['Nike Sportswear Hoodie', 16.0, '2026-11-19'], [$vinted['title'], $vinted['price'], $vinted['ship_by']]);
    $depop = Classifier::classify('depop', 'You’ve made a sale on Depop! Here’s your USPS shipping label for @buyer1', "Sale details\nIgloo Cooler Vintage...\nIgloo Cooler Vintage...\n\$10.00\nItem price\n\$10.00", FALSE, '2026-11-16');
    $this->assertSame(['Igloo Cooler Vintage', 10.0], [$depop['title'], $depop['price']]);
    $this->assertSame('Orthofeet 832 Shoes Size 7.5 Wide', Classifier::classify('mercari', "You've made a sale: Orthofeet 832 Shoes Size 7.5 Wide", '', FALSE, '2026-11-16')['title']);
    $done = Classifier::classify('vinted', 'This order is completed', "Your sale of Mesh Caddy was completed successfully.\nItem price:\n\$6.00\nTransferred to your Vinted Wallet:\n\$5.40", FALSE, '2026-11-14');
    $this->assertSame(['Mesh Caddy', 5.4], [$done['title'], $done['price']]);
    $this->assertSame('9400111899223344556677', Classifier::classify('mercari', 'Your item is on its way: Lamp', 'Tracking number: 9400111899223344556677', FALSE, '2026-11-16')['tracking']);
    $return = Classifier::classify('poshmark', 'Return requested for Lamp', 'Please respond within 3 days.', FALSE, '2026-11-16');
    $this->assertSame([Classifier::RETURN, '2026-11-19'], [$return['kind'], $return['deadline']]);
    $this->assertSame('https://www.mercari.com/us/item/m12345678901', Classifier::classify('mercari', 'Your listing is live', 'See https://www.mercari.com/us/item/m12345678901/', FALSE, '2026-11-16')['url']);
  }

  /**
   * Listing IDs from plain and tracked links.
   */
  public function testListingRef(): void {
    // eBay is not a selling platform since 2026-11-17.
    $this->assertNull(ListingRef::find('ebay', 'https://www.ebay.com/itm/123456789012?hash=x'));
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
    // eBay mail is not read since eBay stopped being a selling platform (2026-11-17).
    $this->assertNull(Senders::platform('eBay <ebay@ebay.com>'));
    $this->assertSame('mercari', Senders::platform('no-reply@mail.mercari.com'));
    $this->assertSame('facebook', Senders::platform('Facebook <notification@facebookmail.com>'));
    $this->assertNull(Senders::platform('mercari@mercari.com.evil.example'));
    $this->assertNull(Senders::platform('not an address'));

    $good = ['mx.google.com; dkim=pass header.i=@poshmark.com header.s=s1; spf=pass; dmarc=pass (p=REJECT) header.from=poshmark.com'];
    $this->assertTrue(Senders::authentic($good, 'poshmark'));
    $this->assertFalse(Senders::authentic($good, 'mercari'));
    $this->assertFalse(Senders::authentic(['mx.google.com; dkim=fail header.i=@poshmark.com; dmarc=fail header.from=poshmark.com'], 'poshmark'));
    // A header the sender added itself doesn't count.
    $this->assertFalse(Senders::authentic(['evil.example; dkim=pass header.i=@poshmark.com'], 'poshmark'));
    $this->assertTrue(Senders::authentic(['mx.google.com; dkim=pass header.i=@mail.mercari.com header.s=x'], 'mercari'));

    $query = Senders::gmailQuery(14);
    $this->assertStringContainsString('from:(poshmark.com OR mercari.com', $query);
    $this->assertStringNotContainsString('ebay.com', $query);
    $this->assertStringContainsString('newer_than:14d -category:promotions', $query);
  }

}
