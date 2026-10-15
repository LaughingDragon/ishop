<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Paste-ready fields per platform for the "Post this item" page (Phase 3).
 *
 * Ported from the old crosslister's app/formatters (base.py and one module
 * per platform). Limits, notes, tips, fee figures and condition maps are kept
 * word for word, with the dates they were checked. Platform limits change over
 * time: re-check a platform's live form before changing a number here.
 *
 * Over-limit values are flagged, never silently cut, except titles, which are
 * truncated because a too-long title can't be pasted at all.
 */
final class PostPack {

  /**
   * Photo export per platform: max photos, size in px, square, note.
   *
   * Each platform has an image style post_<platform> with the same size
   * (square ones are padded with white, never cropped).
   */
  public const PHOTO_SPECS = [
    'poshmark' => [
      'max_photos' => 16,
      'size' => 1200,
      'square' => TRUE,
      'note' => "Exported as 1200x1200 squares (white padding) \u{2014} Poshmark displays square photos",
    ],
    'mercari' => [
      'max_photos' => 12,
      'size' => 1080,
      'square' => TRUE,
      'note' => 'Exported as 1080x1080 squares (white padding)',
    ],
    'depop' => [
      'max_photos' => 4,
      'size' => 1280,
      'square' => TRUE,
      'note' => "Depop allows only 4 photos \u{2014} the first 4 are exported as 1280x1280 squares",
    ],
    'grailed' => [
      'max_photos' => 25,
      'size' => 1600,
      'square' => FALSE,
      'note' => "Grailed doesn't force square photos; export at up to 1600px",
    ],
    'vinted' => [
      'max_photos' => 20,
      'size' => 1600,
      'square' => FALSE,
      'note' => "Vinted doesn't force square photos; export at up to 1600px",
    ],
    'facebook' => [
      'max_photos' => 10,
      'size' => 1440,
      'square' => FALSE,
      'note' => "Exported at up to 1440px on the long edge (Facebook doesn't crop square)",
    ],
  ];

  /**
   * Display names (Facebook's is longer than the platform list's).
   */
  public const DISPLAY_NAMES = [
    'poshmark' => 'Poshmark',
    'mercari' => 'Mercari',
    'depop' => 'Depop',
    'grailed' => 'Grailed',
    'vinted' => 'Vinted',
    'facebook' => 'Facebook Marketplace',
  ];

  /**
   * Mercari's five conditions (checked July 2026).
   */
  private const MERCARI_CONDITIONS = [
    'New with tags' => 'New',
    'New without tags' => 'New',
    'Like new' => 'Like New',
    'Good' => 'Good',
    'Fair' => 'Fair',
    'Poor' => 'Poor',
  ];

  /**
   * Grailed's conditions (checked 2026-08).
   */
  private const GRAILED_CONDITIONS = [
    'New with tags' => 'New/Never Worn',
    'New without tags' => 'New/Never Worn',
    'Like new' => 'Gently Used',
    'Good' => 'Used',
    'Fair' => 'Very Worn',
    'Poor' => 'Very Worn',
  ];

  /**
   * Vinted's conditions (checked 2026-08).
   */
  private const VINTED_CONDITIONS = [
    'New with tags' => 'New with tags',
    'New without tags' => 'New without tags',
    'Like new' => 'Very good',
    'Good' => 'Good',
    'Fair' => 'Satisfactory',
    'Poor' => 'Satisfactory',
  ];

  /**
   * Facebook's fixed condition options (checked July 2026).
   */
  private const FACEBOOK_CONDITIONS = [
    'New with tags' => 'New',
    'New without tags' => 'New',
    'Like new' => "Used \u{2013} Like New",
    'Good' => "Used \u{2013} Good",
    'Fair' => "Used \u{2013} Fair",
    'Poor' => "Used \u{2013} Fair",
  ];

  /**
   * Builds one platform's pack.
   *
   * @param string $platform
   *   A Platforms::PLATFORMS key.
   * @param array $listing
   *   Plain values: title, description, brand, size, color, condition,
   *   condition_notes (flaws), category, price (float or NULL).
   *
   * @return array{platform: string, display_name: string, fields: array, photo_spec: array, tips: string[]}
   *   Fields in the order they are pasted.
   */
  public static function build(string $platform, array $listing): array {
    [$fields, $tips] = match ($platform) {
      'poshmark' => self::poshmark($listing),
      'mercari' => self::mercari($listing),
      'depop' => self::depop($listing),
      'grailed' => self::grailed($listing),
      'vinted' => self::vinted($listing),
      'facebook' => self::facebook($listing),
    };
    return [
      'platform' => $platform,
      'display_name' => self::DISPLAY_NAMES[$platform],
      'fields' => $fields,
      'photo_spec' => self::PHOTO_SPECS[$platform],
      'tips' => $tips,
    ];
  }

  /**
   * Poshmark (checked July 2026): title 80, description 1500.
   *
   * Only ~50 title characters show on desktop search, ~22 on mobile, so
   * front-load the important words.
   */
  private static function poshmark(array $l): array {
    return [
      [
        self::field('Title', self::smartTruncate($l['title'] ?? '', 80), 80),
        self::field('Description', self::standardDescription($l, 1500), 1500, multiline: TRUE),
        self::field('Category', $l['category'] ?? NULL, note: "Pick the closest match in Poshmark's category picker"),
        self::field('Brand', $l['brand'] ?? NULL, note: "Start typing in Poshmark's brand box and pick from its directory"),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Listing price', self::priceStr($l['price'] ?? NULL), note: 'Poshmark fee: $2.95 under $15, otherwise 20%'),
      ],
      [
        "The first photo is the 'covershot' \u{2014} make it the best angle.",
        'Poshmark also asks for Original Price; add it if you know it.',
        "New with tags? Also toggle Poshmark's NWT flag when listing.",
      ],
    ];
  }

  /**
   * Mercari (checked July 2026): title 80, description 1000 (hard limit).
   */
  private static function mercari(array $l): array {
    return [
      [
        self::field('Title', self::smartTruncate($l['title'] ?? '', 80), 80),
        self::field('Description', self::standardDescription($l, 1000), 1000, multiline: TRUE),
        self::field('Category', $l['category'] ?? NULL, note: "Pick the closest match in Mercari's category picker"),
        self::field('Brand', $l['brand'] ?? NULL),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Condition', self::MERCARI_CONDITIONS[$l['condition'] ?? ''] ?? '', note: 'Mercari options: New / Like New / Good / Fair / Poor'),
        self::field('Price', self::priceStr($l['price'] ?? NULL), note: self::mercariPayout($l['price'] ?? NULL)),
      ],
      [
        "Mercari weighs recent listings in search \u{2014} relist stale items.",
        "Set shipping (who pays + weight) while listing; have the item's weight handy.",
      ],
    ];
  }

  /**
   * What lands in the bank from Mercari, ignoring shipping.
   *
   * Fees confirmed 2026-08: a flat 10% on (item price + buyer-paid shipping)
   * and a $2.00 direct-deposit payout fee.
   */
  public static function mercariPayout(?float $price): string {
    if (!$price) {
      return '';
    }
    $fee = $price * 0.10;
    $net = $price - $fee - 2.00;
    return sprintf("\$%.2f after Mercari's 10%% fee (\$%.2f) and the \$2.00 direct-deposit payout fee. Buyer-paid shipping is also charged the 10%%, so factor that in if you're not doing free shipping.", $net, $fee);
  }

  /**
   * Depop (checked July 2026): one 1000-character text box, no title field.
   *
   * The first line acts as the title in search. Hashtags go last. The
   * details block (with flaws) must survive truncation, so room for the title
   * and hashtags is reserved first.
   */
  private static function depop(array $l): array {
    $limit = 1000;
    $title = trim((string) ($l['title'] ?? ''));
    $tag_line = implode(' ', self::hashtags($l));
    $reserved = 0;
    if ($title !== '') {
      $reserved += mb_strlen($title) + 2;
    }
    if ($tag_line !== '') {
      $reserved += mb_strlen($tag_line) + 2;
    }
    $body = self::standardDescription($l, max($limit - $reserved, 0));
    $text = implode("\n\n", array_filter([$title, $body, $tag_line], fn ($p) => $p !== ''));
    // Safety net only: the reservation above should make this a no-op.
    if (mb_strlen($text) > $limit) {
      $text = self::smartTruncate($text, $limit);
    }
    return [
      [
        self::field('Listing text (first line acts as the title)', $text, $limit, multiline: TRUE),
        self::field('Category', $l['category'] ?? NULL, note: "Pick the closest match in Depop's category picker"),
        self::field('Brand', $l['brand'] ?? NULL),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Condition', $l['condition'] ?? NULL, note: 'Depop options: Brand new / Like new / Used - Excellent / Used - Good / Used - Fair'),
        self::field('Price', self::priceStr($l['price'] ?? NULL)),
      ],
      [
        "Only your first 4 photos are exported \u{2014} reorder them in the editor if needed.",
        'You can add 1 short video in the Depop app for a big visibility boost.',
      ],
    ];
  }

  /**
   * Grailed (page checked 2026-08; limits are conservative estimates).
   *
   * If Grailed rejects a value as too long, trust its error over these.
   */
  private static function grailed(array $l): array {
    return [
      [
        self::field('Item Name', self::smartTruncate($l['title'] ?? '', 60), 60),
        self::field('Description', self::standardDescription($l, 1000), 1000, multiline: TRUE),
        self::field('Department / Category / Sub-category', $l['category'] ?? NULL, note: "Pick the closest match in Grailed's category tree"),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Color', $l['color'] ?? NULL),
        self::field('Condition (Grailed option)', self::GRAILED_CONDITIONS[$l['condition'] ?? ''] ?? '', note: 'Grailed options: New/Never Worn / Gently Used / Used / Very Worn'),
        self::field('Price', self::priceStr($l['price'] ?? NULL), note: 'Grailed fee: 6% or $1.99, whichever is higher, under $120'),
      ],
      [
        "Grailed skews menswear/streetwear \u{2014} lean into brand and era in the title.",
        'Country of origin is asked for customs; leave blank if unsure.',
        'Smart Pricing (optional) auto-drops price 10%/week toward your floor.',
      ],
    ];
  }

  /**
   * Vinted (page checked 2026-08; limits are conservative estimates).
   */
  private static function vinted(array $l): array {
    return [
      [
        self::field('Title', self::smartTruncate($l['title'] ?? '', 70), 70),
        self::field('Description', self::standardDescription($l, 1000), 1000, multiline: TRUE),
        self::field('Category', $l['category'] ?? NULL, note: "Pick the closest match in Vinted's category picker"),
        self::field('Brand', $l['brand'] ?? NULL),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Condition (Vinted option)', self::VINTED_CONDITIONS[$l['condition'] ?? ''] ?? '', note: 'Vinted options: New with tags / New without tags / Very good / Good / Satisfactory'),
        self::field('Price', self::priceStr($l['price'] ?? NULL), note: 'Vinted charges the buyer a protection fee, not the seller'),
      ],
      [
        'Vinted has strong EU/UK traffic in addition to US.',
        "Buyer pays Vinted's buyer-protection fee, so your listed price is closer to your payout.",
      ],
    ];
  }

  /**
   * Facebook Marketplace (checked July 2026; numbers are conservative).
   *
   * Title ~100 characters (capped at 99), description 5000.
   */
  private static function facebook(array $l): array {
    return [
      [
        self::field('Title', self::smartTruncate($l['title'] ?? '', 99), 99),
        self::field('Description', self::standardDescription($l, 5000), 5000, multiline: TRUE),
        self::field('Category', $l['category'] ?? NULL, note: 'Pick the closest Marketplace category'),
        self::field('Condition (Facebook option)', self::FACEBOOK_CONDITIONS[$l['condition'] ?? ''] ?? '', note: "Facebook options: New / Used \u{2013} Like New / Used \u{2013} Good / Used \u{2013} Fair"),
        self::field('Brand', $l['brand'] ?? NULL),
        self::field('Size', $l['size'] ?? NULL),
        self::field('Price', self::priceStr($l['price'] ?? NULL)),
      ],
      [
        'Local pickup listings have no fees; shipped sales take a small fee.',
        "Answer 'Is this available?' messages fast \u{2014} Marketplace ranks responsive sellers higher.",
      ],
    ];
  }

  /**
   * One copy-paste field with an optional character limit.
   */
  public static function field(string $label, mixed $value, ?int $limit = NULL, ?string $note = NULL, bool $multiline = FALSE): array {
    $value = trim((string) ($value ?? ''));
    $length = mb_strlen($value);
    return [
      'label' => $label,
      'value' => $value,
      'limit' => $limit,
      'length' => $length,
      'over' => $limit !== NULL && $limit > 0 && $length > $limit,
      'note' => $note !== '' ? $note : NULL,
      'multiline' => $multiline,
    ];
  }

  /**
   * Cuts text to the limit at a word boundary when possible. No ellipsis.
   */
  public static function smartTruncate(string $text, int $limit): string {
    $text = trim($text);
    if (mb_strlen($text) <= $limit) {
      return $text;
    }
    $cut = mb_substr($text, 0, max($limit, 0));
    $space = mb_strrpos($cut, ' ');
    if ($space !== FALSE) {
      $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, ' ,.;:-');
  }

  /**
   * The asking price as a plain number string (platforms want no $).
   */
  public static function priceStr(?float $price): string {
    return $price === NULL ? '' : sprintf('%.2f', $price);
  }

  /**
   * Description followed by a details block (brand/size/color/condition).
   *
   * The details block, especially "Flaws", is a disclosure, not filler. If a
   * limit forces a cut, the free-text description is shortened first; the
   * details block is cut only when it alone doesn't fit.
   */
  public static function standardDescription(array $l, ?int $limit = NULL): string {
    $description = trim((string) ($l['description'] ?? ''));
    $details = [];
    foreach (['Brand' => 'brand', 'Size' => 'size', 'Color' => 'color', 'Condition' => 'condition'] as $label => $key) {
      if (!empty($l[$key])) {
        $details[] = $label . ': ' . $l[$key];
      }
    }
    if (!empty($l['condition_notes'])) {
      $details[] = 'Flaws: ' . $l['condition_notes'];
    }
    $details_block = implode("\n", $details);

    if ($limit !== NULL) {
      $separator = ($description !== '' && $details_block !== '') ? 2 : 0;
      $budget = $limit - mb_strlen($details_block) - $separator;
      if ($budget < 0) {
        $details_block = self::smartTruncate($details_block, $limit);
        $description = '';
      }
      elseif (mb_strlen($description) > $budget) {
        $description = self::smartTruncate($description, $budget);
      }
    }
    return implode("\n\n", array_filter([$description, $details_block], fn ($b) => $b !== ''));
  }

  /**
   * Simple hashtags from brand, color and category, e.g. #gap #navy #tshirts.
   *
   * @return string[]
   *   Up to $max tags.
   */
  public static function hashtags(array $l, int $max = 5): array {
    $words = [];
    if (!empty($l['brand'])) {
      $words[] = $l['brand'];
    }
    if (!empty($l['color'])) {
      $words[] = preg_split('/\s+/u', trim($l['color']))[0];
    }
    if (!empty($l['category'])) {
      // Last segment of "Men's Clothing > Shirts & Tops > T-Shirts".
      $parts = explode('>', $l['category']);
      $words = array_merge($words, preg_split('/\s+/u', trim(end($parts)), -1, PREG_SPLIT_NO_EMPTY));
    }
    $tags = [];
    foreach ($words as $word) {
      $tag = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower((string) $word));
      if (mb_strlen($tag) > 1 && !in_array('#' . $tag, $tags, TRUE)) {
        $tags[] = '#' . $tag;
      }
    }
    return array_slice($tags, 0, $max);
  }

}
