<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

/**
 * What a marketplace email is about (Phase 10 B), as pure rules.
 *
 * Keyword rules over the subject and the text, checked in KINDS order (a
 * return or a case outranks the sale it is about). Pulls out what the site
 * needs and nothing else: the item's title, a price, a ship-by date, the
 * listing's ID from its link. Never the buyer's name or address.
 *
 * Tuned on the real emails in the business inbox before switching on.
 */
final class Classifier {

  public const CASE = 'case';
  public const RETURN = 'return';
  public const DELAY = 'delay';
  public const SOLD = 'sold';
  public const LABEL = 'label';
  public const SHIP_BY = 'ship_by';
  public const OFFER = 'offer';
  public const PAYOUT = 'payout';
  public const OTHER = 'other';
  public const IGNORE = 'ignore';

  /**
   * Kinds in the order they are tested, with their patterns.
   */
  private const RULES = [
    self::CASE => '/\b(case (was |has been )?(opened|open)|opened a case|dispute|claim (was |has been )?(opened|filed)|item not received|not as described|escalat)/i',
    self::RETURN => '/\b(return (request|requested|opened|started|is open)|requested a return|wants to return|return label for)/i',
    self::DELAY => '/\b(shipping delay|delayed|late shipment|running late|hasn\'t (been )?(shipped|scanned)|not (yet )?(been )?scanned)/i',
    self::SOLD => '/\b(you (just )?(made (a|the) sale|sold)|(item|order|listing) (has )?(sold|been sold|was sold|been purchased|was purchased)|it sold|sold!|congrat\w*[^.]{0,40}\bsold|someone (bought|purchased)|new order|you have a new order)/i',
    self::LABEL => '/\b(shipping label|prepaid label|print (your|the) label|label (is )?(ready|attached))/i',
    self::SHIP_BY => '/\b(ship by|please ship|reminder to ship|time to ship|ship (your|this|the) (item|order|sale)|ship within)/i',
    self::OFFER => '/\b(new offer|made (you )?an offer|sent (you )?an offer|offer (received|for|of)|counter ?offer)/i',
    self::PAYOUT => '/\b(payout|funds (are )?(available|released)|money is on (its|the) way|you\'ve been paid|earnings (are )?(available|released)|redeemable)/i',
  ];

  /**
   * Kinds people see: key => words.
   */
  public const KINDS = [
    self::SOLD => 'Sold',
    self::OFFER => 'Offer',
    self::SHIP_BY => 'Ship-by date',
    self::LABEL => 'Label',
    self::DELAY => 'Shipping delay',
    self::RETURN => 'Return requested',
    self::CASE => 'Case or dispute',
    self::PAYOUT => 'Payout',
    self::OTHER => 'Message',
  ];

  /**
   * Classifies one email.
   *
   * @param string $platform
   *   Senders::DOMAINS key.
   * @param string $subject
   *   The subject, decoded.
   * @param string $text
   *   The plain text (or the HTML turned into text).
   * @param bool $has_pdf
   *   Whether a PDF is attached.
   * @param string $received
   *   Date received, Y-m-d (for "ship within 7 days" and dates without a year).
   *
   * @return array{kind: string, title: ?string, price: ?float, ship_by: ?string, ref: ?string}
   *   The kind, and what could be read (NULL when not found).
   */
  public static function classify(string $platform, string $subject, string $text, bool $has_pdf, string $received): array {
    $all = $subject . "\n" . $text;
    // Facebook sends everything from one domain: only Marketplace counts.
    if ($platform === 'facebook' && !preg_match('/marketplace/i', $all)) {
      return ['kind' => self::IGNORE, 'title' => NULL, 'price' => NULL, 'ship_by' => NULL, 'ref' => NULL];
    }
    $kind = self::OTHER;
    // The subject decides first; the body only when the subject says nothing.
    foreach ([$subject, $text] as $haystack) {
      foreach (self::RULES as $candidate => $pattern) {
        if (preg_match($pattern, $haystack)) {
          $kind = $candidate;
          break 2;
        }
      }
    }
    if ($kind === self::OTHER && $has_pdf) {
      $kind = self::LABEL;
    }
    return [
      'kind' => $kind,
      'title' => self::title($subject, $text),
      'price' => self::price($kind, $subject, $text),
      'ship_by' => self::shipBy($all, $received),
      'ref' => ListingRef::find($platform, $text),
    ];
  }

  /**
   * The item's title, from the subject or an "Item:" line, or NULL.
   */
  public static function title(string $subject, string $text): ?string {
    $patterns = [
      // Quoted in the subject: "Title", “Title”.
      [$subject, '/["“]([^"”]{3,120})["”]/u'],
      // "... sold: Title", "You made the sale for Title", "Your item Title sold".
      [$subject, '/\b(?:sold|sale for|purchased|offer (?:on|for)|ship|label for|return(?: request)? for)\s*[:!-]?\s+(?!your\b|an?\b|it\b)(.{3,120}?)\s*(?:[!.]|$| has | was | on (?:ebay|poshmark|mercari|depop|grailed|vinted))/iu'],
      [$text, '/^\s*(?:item|item title|listing|title)\s*:\s*(.{3,120})$/imu'],
    ];
    foreach ($patterns as [$haystack, $pattern]) {
      if (preg_match($pattern, $haystack, $m)) {
        $title = trim($m[1], " \t\r\n\"'“”");
        if ($title !== '' && !preg_match('/^\$?[\d.,]+$/', $title)) {
          return $title;
        }
      }
    }
    return NULL;
  }

  /**
   * The amount that matters for this kind, or NULL.
   */
  public static function price(string $kind, string $subject, string $text): ?float {
    $money = '\$\s?([\d,]+(?:\.\d{2})?)';
    $labels = match ($kind) {
      self::OFFER => ['offer(?:ed)?(?: of| for)?', 'counter ?offer(?: of)?'],
      self::PAYOUT => ['payout(?: of)?', 'amount', 'total', 'earnings'],
      default => ['sold for', 'sale price', 'item price', 'price', 'order total', 'item total', 'total', 'subtotal'],
    };
    foreach ($labels as $label) {
      if (preg_match('/\b' . $label . '\b[^$\n]{0,20}' . $money . '/i', $subject . "\n" . $text, $m)) {
        return (float) str_replace(',', '', $m[1]);
      }
    }
    if (preg_match('/' . $money . '/', $subject, $m)) {
      return (float) str_replace(',', '', $m[1]);
    }
    return NULL;
  }

  /**
   * The ship-by date (Y-m-d), or NULL.
   */
  public static function shipBy(string $text, string $received): ?string {
    if (preg_match('/\bship(?: it| the item| your item)? within (\d{1,2}) (business )?days?/i', $text, $m)) {
      return (new \DateTimeImmutable($received))->modify('+' . (int) $m[1] . (($m[2] ?? '') !== '' ? ' weekdays' : ' days'))->format('Y-m-d');
    }
    $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    $pattern = '/\bship(?:ped)?(?: it| the item| your item| this order)? (?:by|before|no later than)\s*:?\s*(?:[a-z]{3,9},?\s+)?(?:(' . $months . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?|(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?)/i';
    if (!preg_match($pattern, $text, $m)) {
      return NULL;
    }
    if (($m[1] ?? '') !== '') {
      $month = (int) date('n', strtotime('1 ' . substr($m[1], 0, 3) . ' 2000'));
      $day = (int) $m[2];
      $year = ($m[3] ?? '') !== '' ? (int) $m[3] : NULL;
    }
    else {
      $month = (int) $m[4];
      $day = (int) $m[5];
      $year = ($m[6] ?? '') !== '' ? (int) $m[6] : NULL;
      $year = $year !== NULL && $year < 100 ? 2000 + $year : $year;
    }
    if (!checkdate($month, $day, $year ?? 2000)) {
      return NULL;
    }
    $received_at = new \DateTimeImmutable($received);
    $year ??= (int) $received_at->format('Y');
    $date = (new \DateTimeImmutable())->setDate($year, $month, $day);
    // No year given and the date is long before the email: it's next year.
    if (($m[3] ?? '') === '' && ($m[6] ?? '') === '' && $date < $received_at->modify('-30 days')) {
      $date = $date->modify('+1 year');
    }
    return $date->format('Y-m-d');
  }

}
