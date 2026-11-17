<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

/**
 * Which item belongs on which marketplace, and why (Phase 3).
 *
 * Ported from the old crosslister's app/eligibility.py. Every rule and reason
 * there was checked against the live platforms on 2026-08-17; they are kept
 * word for word. Do not "improve" them from memory: open the platform's real
 * page first.
 *
 * Three verdicts: yes (post it), check (on the boundary: read the reason),
 * no (not allowed). The item's KIND (from its category, else title and
 * description) picks the verdict; FLAGS (battery/mains power, chemicals, too
 * big to ship) can make it stricter; a manual override on the listing wins.
 *
 * Adapted for this site's categories: "Electronics", "Office & Craft" and
 * "Shoes" were added to the category phrases (marked below).
 */
final class Eligibility {

  public const YES = 'yes';
  public const CHECK = 'check';
  public const NO = 'no';

  /**
   * Chip label per verdict.
   */
  public const VERDICT_LABELS = [
    self::YES => 'Post it',
    self::NO => 'Not allowed',
    self::CHECK => 'Check first',
  ];

  public const KIND_LABELS = [
    'mens_wear' => 'Men\'s clothing / shoes / accessories',
    'womens_wear' => 'Women\'s clothing / shoes / accessories',
    'kids_wear' => 'Kids\' clothing',
    'unisex_wear' => 'Unisex clothing / accessory / jewellery',
    'beauty' => 'Beauty / personal care',
    'electronics' => 'Electronic device',
    'toy' => 'Toy / game / collectible',
    'home' => 'Home goods',
    'office' => 'Office / desk / craft',
    'sporting' => 'Sporting / outdoor gear',
    'auto' => 'Automotive accessory',
    'supplies' => 'Bulk / industrial supplies',
    'other' => 'Other',
  ];

  public const FLAG_LABELS = [
    'powered' => 'runs on a battery or mains power',
    'hazmat' => 'a chemical or hazardous material',
    'bulky' => 'big or heavy to ship',
  ];

  /**
   * Category phrases, most specific first: [phrases, kind].
   */
  private const CATEGORY_RULES = [
      [
        [
          'women\'s clothing',
          'womens clothing',
          'women\'s shoes',
          'womens shoes',
          'women\'s accessories',
          'womens accessories',
          'womenswear',
          'women\'s bags',
          'women\'s jewelry',
        ],
        'womens_wear',
      ],
      [
        [
          'men\'s clothing',
          'mens clothing',
          'men\'s shoes',
          'mens shoes',
          'men\'s accessories',
          'mens accessories',
          'menswear',
          'men\'s bags',
          'men\'s jewelry',
        ],
        'mens_wear',
      ],
      [
        ['kids', 'kid\'s', 'children\'s', 'baby', 'toddler', 'girls\' ', 'boys\' ', 'juniors'],
        'kids_wear',
      ],
      [
        [
          'business & industrial',
          'janitorial',
          'cleaning & janitorial',
          'cleaning supplies',
          'cleaning chemicals',
        ],
        'supplies',
      ],
      [
        ['health & beauty', 'hair care', 'beauty & personal care', 'cosmetics'],
        'beauty',
      ],
      [
        [
          'consumer electronics',
          // Added for this site's top-level "Electronics" category.
          'electronics',
          'cell phones',
          'computers & tablets',
          'cameras & photo',
          'security & surveillance',
          'audio & video',
        ],
        'electronics',
      ],
      [
        ['toys & hobbies', 'toys & games', 'building toys', 'collectibles', 'action figures', 'lego'],
        'toy',
      ],
      [
        ['automotive', 'auto parts', 'vehicle'],
        'auto',
      ],
      [
        [
          'sporting goods',
          'sports & outdoors',
          'outdoor recreation',
          'camping & hiking',
          'exercise & fitness',
        ],
        'sporting',
      ],
      [
        [
          'office & craft supplies',
          // Added for this site's top-level "Office & Craft" category.
          'office & craft',
          'office & desk accessories',
          'office supplies',
          'desk accessories',
          'arts & crafts',
          'craft supplies',
          'binders & photo albums',
          'stationery',
        ],
        'office',
      ],
      [
        [
          'home & garden',
          'home & kitchen',
          'home decor',
          'furniture',
          'bedding',
          'kitchen & dining',
          'tools & home improvement',
          'home improvement',
        ],
        'home',
      ],
      [
        [
          'clothing shoes & accessories',
          // Added for this site's top-level "Shoes" category (no gender).
          'shoes',
          'clothing, shoes & accessories',
          'unisex accessories',
          'jewelry & accessories',
          'jewellery',
          'bags & backpacks',
          'handbags',
          'watches',
        ],
        'unisex_wear',
      ],
  ];

  private const APPAREL_WORDS = [
    't shirt',
    'tee',
    'shirt',
    'jeans',
    'trousers',
    'pants',
    'shorts',
    'jacket',
    'coat',
    'hoodie',
    'sweatshirt',
    'sweater',
    'cardigan',
    'dress',
    'skirt',
    'blouse',
    'sneakers',
    'boots',
    'slippers',
    'sandals',
    'hat',
    'cap',
    'beanie',
    'scarf',
    'gloves',
    'belt',
    'socks',
    'pajama',
    'pajamas',
    'anorak',
    'parka',
    'vest',
    'blazer',
    'polo',
  ];
  private const TOY_WORDS = [
    'lego',
    'plush',
    'action figure',
    'board game',
    'puzzle',
    'building block',
    'building blocks',
    'figurine',
    'doll',
  ];
  private const HOME_WORDS = [
    'mattress',
    'headboard',
    'pillow',
    'duvet',
    'cushion',
    'photo album',
    'picture frame',
    'cookware',
    'bedding',
  ];
  private const OFFICE_WORDS = [
    'binder',
    'stapler',
    'notebook',
    'planner',
    'receipt spike',
    'bill holder',
    'desk organizer',
  ];
  private const SPORT_WORDS = [
    'compass',
    'tent',
    'sleeping bag',
    'camp stool',
    'carabiner',
    'avalanche',
    'transceiver',
    'backpacking',
  ];
  private const AUTO_WORDS = ['car magnet', 'car magnets', 'bumper sticker', 'license plate', 'windshield'];
  private const POWERED_WORDS = [
    'rechargeable',
    'battery',
    'batteries',
    'power bank',
    'powerbank',
    'charger',
    'charging',
    'usb',
    'bluetooth',
    'wireless',
    'cordless',
    'hardwired',
    'plug in',
    'electric',
    'electronic',
    'electronics',
    'camera',
    'speaker',
    'headphones',
    'earbuds',
    'smartwatch',
    'tablet',
    'laptop',
    'console',
    'transceiver',
    'beacon',
    'hair dryer',
    'blow dryer',
    'flat iron',
    'curling iron',
    'clippers',
    'trimmer',
    'appliance',
    'led',
    'mah',
  ];
  private const HAZMAT_WORDS = [
    'cleaner',
    'cleaning chemical',
    'cleaning chemicals',
    'detergent',
    'solvent',
    'bleach',
    'aerosol',
    'pesticide',
    'degreaser',
    'disinfectant',
    'paint thinner',
    'flammable',
    'corrosive',
    'ammonia',
    'acetone',
  ];
  private const BULKY_WORDS = [
    'headboard',
    'mattress',
    'sofa',
    'couch',
    'dresser',
    'armchair',
    'bed frame',
    'wardrobe',
    'bookcase',
    'twin xl',
    'queen size',
    'king size',
    'case lot',
    'pallet',
    'chest freezer',
  ];

  /**
   * Per platform: display name, summary, detail, verdicts by kind, flag rules.
   */
  public const RULES = [
    'grailed' => [
      'display_name' => 'Grailed',
      'summary' => 'Clothing only — menswear and womenswear.',
      'detail' => 'Grailed\'s sell form has exactly two departments, Menswear and Womenswear, with six categories under each: Tops, Bottoms, Outerwear, Footwear, Tailoring and Accessories. There is no way to list anything else — no home goods, no toys, no electronics, no tools. If it isn\'t wearable, it doesn\'t go here.',
      'verdicts' => [
        'mens_wear' => ['yes', 'Menswear is Grailed\'s core market — this is the best place for it.'],
        'womens_wear' => ['yes', 'Grailed has a full Womenswear department.'],
        'unisex_wear' => ['yes', 'Lists under Menswear or Womenswear → Accessories.'],
        'kids_wear' => ['no', 'Grailed has only Menswear and Womenswear — there is no kids\' department.'],
        'beauty' => ['no', 'Grailed sells clothing only.'],
        'electronics' => ['no', 'Grailed sells clothing only — there is no category for electronics.'],
        'toy' => ['no', 'Grailed sells clothing only — there is no category for toys.'],
        'home' => ['no', 'Grailed sells clothing only — there is no category for home goods.'],
        'office' => ['no', 'Grailed sells clothing only.'],
        'sporting' => [
          'no',
          'Grailed sells clothing only. Technical outerwear is welcome; hard gear is not.',
        ],
        'auto' => ['no', 'Grailed sells clothing only.'],
        'supplies' => ['no', 'Grailed sells clothing only.'],
        'other' => [
          'check',
          'Grailed only takes clothing, footwear and fashion accessories. If it isn\'t wearable, skip it.',
        ],
      ],
      'flag_rules' => [
        'hazmat' => ['no', 'Grailed sells clothing only, and this is a chemical.'],
      ],
    ],
    'depop' => [
      'display_name' => 'Depop',
      'summary' => 'Clothing and fashion accessories. No electronics, no chemicals.',
      'detail' => 'Depop is fashion-first and will take small homeware and decor, but it bans consumer electronics by name — including hair stylers and clippers, home devices and appliances, and battery banks and chargers — plus hazardous materials. Clothing gets far more traffic here than anything else.',
      'verdicts' => [
        'mens_wear' => ['yes', 'Clothing is exactly what Depop\'s audience shops for.'],
        'womens_wear' => ['yes', 'Clothing is exactly what Depop\'s audience shops for.'],
        'kids_wear' => ['yes', 'Depop has a Kids category.'],
        'unisex_wear' => ['yes', 'Fashion accessories and jewellery do well on Depop.'],
        'beauty' => [
          'check',
          'Non-powered beauty items are fine. Anything with a motor or a heating element is banned.',
        ],
        'electronics' => [
          'no',
          'Depop bans consumer electronics — home devices, appliances, chargers and battery banks are all named in its prohibited list.',
        ],
        'toy' => [
          'check',
          'Allowed as a collectible, but Depop\'s audience is here for clothes — expect it to move slowly.',
        ],
        'home' => [
          'check',
          'Depop takes homeware and decor, but it\'s a small corner of the app. Worth listing, not worth waiting on.',
        ],
        'office' => ['check', 'Small desk and stationery pieces are allowed; traffic is low.'],
        'sporting' => ['check', 'Sportswear does well. Hard gear is allowed but barely browsed.'],
        'auto' => ['check', 'Not banned, but there is essentially no automotive audience on Depop.'],
        'supplies' => ['no', 'Depop is a fashion marketplace — bulk industrial supplies don\'t belong here.'],
        'other' => [
          'check',
          'Read Depop\'s prohibited-items list before posting — it bans more than you\'d expect.',
        ],
      ],
      'flag_rules' => [
        'powered' => [
          'no',
          'Depop bans battery banks, chargers and consumer electronics — anything with a battery or a plug gets pulled.',
        ],
        'hazmat' => [
          'no',
          'Depop bans hazardous materials and toxic substances, which includes cleaning chemicals.',
        ],
      ],
    ],
    'poshmark' => [
      'display_name' => 'Poshmark',
      'summary' => 'Clothing plus Home, Kids, Pets, Beauty and some Electronics.',
      'detail' => 'Poshmark started as clothing and has grown into Home, Electronics, Pets and Beauty & Wellness. It still excludes furniture, appliances, cell phones, DVDs, perfumes and aerosols. Women\'s clothing is by far its biggest market.',
      'verdicts' => [
        'mens_wear' => ['yes', 'Poshmark has a Men\'s department.'],
        'womens_wear' => ['yes', 'Women\'s clothing is Poshmark\'s biggest market by a wide margin.'],
        'kids_wear' => ['yes', 'Poshmark has a Kids department.'],
        'unisex_wear' => ['yes', 'Accessories and jewellery sell well on Poshmark.'],
        'beauty' => ['yes', 'Beauty & Wellness is a Poshmark category.'],
        'electronics' => [
          'check',
          'Poshmark has an Electronics category but bans cell phones and appliances — smart-home gear is a grey area.',
        ],
        'toy' => ['yes', 'Kids\' toys are explicitly supported.'],
        'home' => [
          'check',
          'Home goods are supported, but furniture is banned. Soft goods and decor are safe; anything large is not.',
        ],
        'office' => [
          'check',
          'Small desk and craft items list under Home — there\'s no dedicated office category.',
        ],
        'sporting' => ['check', 'Activewear is core. Hard outdoor gear is a stretch for this audience.'],
        'auto' => [
          'check',
          'No automotive category — it would have to go under Other, where nobody will see it.',
        ],
        'supplies' => ['check', 'No category really fits bulk supplies. Possible, but it won\'t get traffic.'],
        'other' => ['check', 'Find the closest Poshmark category first; if there isn\'t one, skip it.'],
      ],
      'flag_rules' => [
        'hazmat' => ['no', 'Poshmark bans aerosols and hazardous household chemicals.'],
        'bulky' => [
          'check',
          'Poshmark bans furniture and everything ships by mail — check this is small enough to post.',
        ],
        'powered' => [
          'check',
          'Poshmark takes some electronics but bans appliances and cell phones — check yours isn\'t on the wrong side of that line.',
        ],
      ],
    ],
    'mercari' => [
      'display_name' => 'Mercari',
      'summary' => 'Almost anything — by far the widest catalogue of the five.',
      'detail' => 'Mercari\'s top navigation covers Women, Men, Electronics, Toys, Gaming, Handbags, Home, Vintage, Beauty, Kids, Sports, Handmade, Office, Pet, Outdoor, Arts & Crafts, Tools, Books and Other. If an item doesn\'t fit anywhere else, it fits here.',
      'verdicts' => [
        'mens_wear' => ['yes', 'Mercari has a Men\'s category.'],
        'womens_wear' => ['yes', 'Mercari has a Women\'s category.'],
        'kids_wear' => ['yes', 'Mercari has a Kids category.'],
        'unisex_wear' => ['yes', 'Accessories and Handbags both have their own categories.'],
        'beauty' => ['yes', 'Beauty is a top-level Mercari category.'],
        'electronics' => ['yes', 'Electronics is one of Mercari\'s biggest categories.'],
        'toy' => ['yes', 'Toys and Gaming are both top-level categories.'],
        'home' => ['yes', 'Home is a top-level category.'],
        'office' => ['yes', 'Office is a top-level category.'],
        'sporting' => ['yes', 'Sports and Outdoor are both top-level categories.'],
        'auto' => ['yes', 'Mercari doesn\'t restrict car accessories — they list under Other.'],
        'supplies' => ['yes', 'Tools and Other both accept bulk supplies.'],
        'other' => ['yes', 'Mercari has a literal \'Other\' category — everything has a home here.'],
      ],
      'flag_rules' => [
        'hazmat' => [
          'check',
          'Allowed, but Mercari\'s prepaid labels don\'t cover flammable or combustible liquids — you\'d have to buy and print your own shipping label.',
        ],
      ],
    ],
    'vinted' => [
      'display_name' => 'Vinted',
      'summary' => 'Almost anything except cleaning chemicals. No seller fees.',
      'detail' => 'Vinted\'s catalogue covers Women, Men, Designer, Kids, Home, Electronics, Books & Media, Hobbies & collectibles and Sports. Home explicitly includes tools, office supplies, DIY, garden and pet care. The one ban that matters for this inventory is cleaning chemicals. Sellers pay no fee — the buyer covers protection — so your listed price is close to your payout.',
      'verdicts' => [
        'mens_wear' => ['yes', 'Vinted has a Men\'s category.'],
        'womens_wear' => ['yes', 'Women\'s is Vinted\'s largest category.'],
        'kids_wear' => ['yes', 'Vinted has a Kids category.'],
        'unisex_wear' => ['yes', 'Accessories list under Women\'s or Men\'s.'],
        'beauty' => ['yes', 'Vinted allows non-professional beauty and personal-care items.'],
        'electronics' => ['yes', 'Electronics is a top-level Vinted category and includes smart home devices.'],
        'toy' => ['yes', 'Lists under Kids\' toys or Hobbies & collectibles.'],
        'home' => ['yes', 'Home is a top-level category covering textiles, decor and kitchen.'],
        'office' => ['yes', 'Office supplies are explicitly allowed under Home.'],
        'sporting' => ['yes', 'Sports is a top-level category.'],
        'auto' => [
          'check',
          'No automotive category. Small accessories can go under Home; larger car parts aren\'t allowed.',
        ],
        'supplies' => ['check', 'Tools and DIY are allowed, but industrial or factory equipment is not.'],
        'other' => ['check', 'Vinted takes most things — check its catalogue rules if it isn\'t obvious.'],
      ],
      'flag_rules' => [
        'hazmat' => ['no', 'Vinted bans detergents, washing and cleaning chemicals outright.'],
      ],
    ],
    'facebook' => [
      'display_name' => 'Facebook Marketplace',
      'summary' => 'Big or heavy local items only — you post these by hand.',
      'detail' => 'In this workflow Facebook Marketplace is reserved for items too big or heavy to ship, which a buyer collects in person. That\'s a judgement about size, not category, so the app doesn\'t pretend to decide — it flags the likely candidates and leaves the call to you. Nothing here is exported for copy-paste.',
      'verdicts' => [],
      'flag_rules' => [],
    ],
  ];

  /**
   * Lower-cased, punctuation flattened to spaces, padded at both ends.
   *
   * Padding lets " men's " match as a word instead of the tail of
   * " women's ".
   */
  public static function words(string $text): string {
    return ' ' . trim((string) preg_replace("/[^a-z0-9']+/", ' ', mb_strtolower($text))) . ' ';
  }

  /**
   * Whether any phrase appears as whole words in a padded string.
   */
  private static function has(string $hay, array $phrases): bool {
    foreach ($phrases as $phrase) {
      if (str_contains($hay, self::words($phrase))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Facts that override the category on some platforms.
   *
   * @return string[]
   *   Sorted subset of powered, hazmat, bulky.
   */
  public static function flags(array $item): array {
    $hay = self::words(implode(' ', [$item['category'] ?? '', $item['title'] ?? '', $item['description'] ?? '']));
    $found = [];
    if (self::has($hay, self::POWERED_WORDS)) {
      $found[] = 'powered';
    }
    if (self::has($hay, self::HAZMAT_WORDS)) {
      $found[] = 'hazmat';
    }
    if (self::has($hay, self::BULKY_WORDS)) {
      $found[] = 'bulky';
    }
    sort($found);
    return $found;
  }

  /**
   * Which shelf the item belongs on (a KIND_LABELS key); never fails.
   */
  public static function classify(array $item): string {
    $category = self::words($item['category'] ?? '');
    foreach (self::CATEGORY_RULES as [$phrases, $kind]) {
      if (self::has($category, $phrases)) {
        return $kind;
      }
    }
    $text = self::words(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''));
    foreach ([
      'toy' => self::TOY_WORDS,
      'auto' => self::AUTO_WORDS,
      'sporting' => self::SPORT_WORDS,
      'office' => self::OFFICE_WORDS,
      'home' => self::HOME_WORDS,
      'unisex_wear' => self::APPAREL_WORDS,
    ] as $kind => $words) {
      if (self::has($text, $words)) {
        return $kind;
      }
    }
    return 'other';
  }

  /**
   * Should this item go on this platform?
   *
   * @param array $item
   *   Keys: category (path "A > B"), title, description, and optionally
   *   override (yes/no/check) and override_note for this platform.
   * @param string $platform
   *   A Platforms::PLATFORMS key.
   *
   * @return array{verdict: string, label: string, reason: string, kind: string, flags: string[], overridden: bool}
   *   The verdict and why.
   */
  public static function evaluate(array $item, string $platform): array {
    $rules = self::RULES[$platform];
    $kind = self::classify($item);
    $flags = self::flags($item);
    if ($platform === 'facebook') {
      [$verdict, $reason] = in_array('bulky', $flags, TRUE)
        ? [
          self::YES,
          "Looks too big or heavy to ship \u{2014} this is exactly what you're keeping Facebook Marketplace for.",
        ]
        : [
          self::NO,
          'Small enough to ship, so post it on the shipping platforms instead. Facebook is only for things that need collecting in person.',
        ];
    }
    else {
      [$verdict, $reason] = $rules['verdicts'][$kind] ?? [
        self::CHECK,
        "No rule for this kind of item yet \u{2014} use your judgement.",
      ];
      foreach ($flags as $flag) {
        if (isset($rules['flag_rules'][$flag]) && self::severity($rules['flag_rules'][$flag][0]) > self::severity($verdict)) {
          [$verdict, $reason] = $rules['flag_rules'][$flag];
        }
      }
    }
    $override = $item['override'] ?? NULL;
    $overridden = in_array($override, [self::YES, self::NO, self::CHECK], TRUE);
    if ($overridden) {
      $verdict = $override;
      $note = trim((string) ($item['override_note'] ?? ''));
      $reason = $note !== '' ? $note : "You set this by hand, overriding the site's rule.";
    }
    return [
      'verdict' => $verdict,
      'label' => self::VERDICT_LABELS[$verdict],
      'reason' => $reason,
      'kind' => $kind,
      'flags' => $flags,
      'overridden' => $overridden,
    ];
  }

  /**
   * How restrictive a verdict is, so the strictest one wins.
   */
  private static function severity(string $verdict): int {
    return [self::YES => 0, self::CHECK => 1, self::NO => 2][$verdict];
  }

}
