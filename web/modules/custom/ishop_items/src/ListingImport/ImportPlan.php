<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

use Drupal\ishop_items\Cascade;
use Drupal\ishop_items\Platforms;

/**
 * Turns spreadsheet rows into the items an import would create (Phase 10).
 *
 * Pure: the form passes in the rows, the column matching and what the site
 * already has (links, titles, locations, conditions, categories, the old
 * Crosslister records), and shows the result as the preview. Nothing is
 * saved here.
 */
final class ImportPlan {

  /**
   * Problem levels.
   *
   * ERROR: can't be imported. WARN: not ticked by default.
   * NOTE: imported, with something left out or assumed.
   */
  public const ERROR = 'error';
  public const WARN = 'warn';
  public const NOTE = 'note';

  /**
   * What an item becomes.
   */
  public const KIND_LISTED = 'listed';
  public const KIND_SOLD = 'sold';
  public const KIND_READY = 'ready';
  public const KIND_ENDED = 'ended';

  /**
   * Longest title the site keeps.
   */
  public const TITLE_MAX = 255;

  /**
   * Condition shorthand resellers use.
   */
  public const CONDITION_WORDS = [
    'nwt' => 'new with tags',
    'new' => 'new with tags',
    'bnwt' => 'new with tags',
    'nwot' => 'new without tags',
    'euc' => 'like new',
    'excellent' => 'like new',
    'excellent used condition' => 'like new',
    'guc' => 'good',
    'used' => 'good',
    'good used condition' => 'good',
    'vguc' => 'good',
  ];

  /**
   * Builds the plan.
   *
   * @param array<int, array{row: int, cells: array<int, mixed>}> $rows
   *   Data rows (sheet row number and cells by column index).
   * @param array<int, string> $mapping
   *   Column index => target (Columns).
   * @param array $context
   *   What the site has: 'urls' (Values::urlKey => nid), 'titles'
   *   (Values::titleKey => nid), 'locations', 'conditions', 'categories'
   *   (lower-case name or "parent > child" path => term ID),
   *   'default_location' (term ID or NULL), 'value_maps' (['category' =>
   *   [lower-case sheet value => term ID, 0 = leave blank], 'condition' =>
   *   ...]), 'records' (old Crosslister records by number) and 'fill'
   *   (TRUE to fill in details from them).
   *
   * @return array<int, array>
   *   One entry per item, in sheet order. See item().
   */
  public static function build(array $rows, array $mapping, array $context): array {
    $context += [
      'urls' => [],
      'titles' => [],
      'locations' => [],
      'conditions' => [],
      'categories' => [],
      'default_location' => NULL,
      'value_maps' => [],
      'records' => [],
      'fill' => FALSE,
    ];
    $groups = [];
    foreach ($rows as $row) {
      $read = self::readRow($row, $mapping);
      if ($read === NULL) {
        continue;
      }
      // One row per platform: rows with the same SKU (or title) whose
      // platforms don't overlap are the same item.
      $placed = FALSE;
      if ($read['key'] !== '' && $read['platforms']) {
        foreach ($groups as $g => $group) {
          if ($group[0]['key'] === $read['key'] && $group[0]['platforms']
            && !array_intersect_key(array_merge(...array_column($group, 'platforms')), $read['platforms'])) {
            $groups[$g][] = $read;
            $placed = TRUE;
            break;
          }
        }
      }
      if (!$placed) {
        $groups[] = [$read];
      }
    }
    $items = [];
    $seen_urls = [];
    foreach ($groups as $group) {
      $item = self::item($group, $context);
      foreach ($item['listings'] as $platform => $listing) {
        $key = $listing['url'] !== NULL ? Values::urlKey($listing['url']) : '';
        if ($key === '') {
          continue;
        }
        if (isset($seen_urls[$key])) {
          $item['problems'][] = [
            self::WARN,
            sprintf('The %s link is also on row %d.', Platforms::PLATFORMS[$platform], $seen_urls[$key]),
          ];
        }
        $seen_urls[$key] ??= $item['rows'][0];
      }
      $item['tick'] = $item['importable'] && !in_array(self::WARN, array_column($item['problems'], 0), TRUE);
      $items[] = $item;
    }
    return $items;
  }

  /**
   * One row read through the matching, or NULL for a blank row.
   */
  private static function readRow(array $row, array $mapping): ?array {
    $values = [];
    $by_platform = [];
    $any = FALSE;
    foreach ($mapping as $i => $target) {
      if ($target === Columns::IGNORE) {
        continue;
      }
      $cell = $row['cells'][$i] ?? NULL;
      $any = $any || Values::text($cell) !== '';
      if (str_contains($target, ':')) {
        [$kind, $platform] = explode(':', $target, 2);
        $by_platform[$platform][$kind] = $cell;
      }
      else {
        $values[$target] = $cell;
      }
    }
    if (!$any) {
      return NULL;
    }
    $text = fn (string $target) => Values::text($values[$target] ?? NULL);
    $problems = [];
    $new = fn () => ['status' => Platforms::POSTED, 'url' => NULL, 'price' => NULL];

    // Platforms named in a "Platform(s)" column are Posted there.
    $named = Values::platforms($values['platform'] ?? NULL);
    foreach ($named['unknown'] as $unknown) {
      $problems[] = [self::WARN, sprintf('Platform "%s" is not one the site knows.', $unknown)];
    }
    $platforms = array_fill_keys($named['platforms'], $new());

    // A platform's own columns: its status, link and price.
    foreach ($by_platform as $platform => $cells) {
      $name = Platforms::PLATFORMS[$platform];
      $status = isset($cells['on']) ? Values::listingStatus($cells['on']) : NULL;
      if ($status === '?') {
        $problems[] = [
          self::NOTE,
          sprintf('%s says "%s": not understood, so left as To post.', $name, Values::text($cells['on'])),
        ];
        $status = Platforms::TO_POST;
      }
      $has_link = Values::text($cells['link'] ?? NULL) !== '';
      $has_price = Values::text($cells['price'] ?? NULL) !== '';
      if ($status === NULL && !$has_link && !$has_price) {
        continue;
      }
      $platforms[$platform] ??= $new();
      if ($status !== NULL) {
        $platforms[$platform]['status'] = $status;
      }
      foreach (['link', 'on'] as $kind) {
        $link = Values::text($cells[$kind] ?? NULL);
        if ($link === '' || ($kind === 'on' && !Values::isUrl($link))) {
          continue;
        }
        if (!Values::isUrl($link)) {
          $problems[] = [
            self::NOTE,
            sprintf('%s link "%s" is not a link: left out.', $name, mb_strimwidth($link, 0, 40, '…')),
          ];
        }
        elseif (Platforms::fromUrl($link) !== $platform) {
          $problems[] = [
            self::WARN,
            sprintf('The %s link goes to %s, not %s: left out.', $name, parse_url($link, PHP_URL_HOST) ?: 'another site', $name),
          ];
        }
        else {
          $platforms[$platform]['url'] = $link;
        }
      }
      if ($has_price) {
        $price = Values::money($cells['price']);
        if ($price === NULL) {
          $problems[] = [
            self::NOTE,
            sprintf('%s price "%s" is not a price: left out.', $name, Values::text($cells['price'])),
          ];
        }
        $platforms[$platform]['price'] = $price;
      }
    }

    // A general "Listing link" column: its site decides the platform.
    $link = $text('link');
    if ($link !== '') {
      $from = Values::isUrl($link) ? Platforms::fromUrl($link) : NULL;
      if (!Values::isUrl($link)) {
        $problems[] = [self::NOTE, sprintf('Link "%s" is not a link: left out.', mb_strimwidth($link, 0, 40, '…'))];
      }
      elseif ($from === NULL) {
        $problems[] = [
          self::WARN,
          sprintf('The link goes to %s, which is not a platform the site knows: left out.', parse_url($link, PHP_URL_HOST) ?: 'another site'),
        ];
      }
      elseif ($platforms && !isset($platforms[$from])) {
        $problems[] = [
          self::WARN,
          sprintf('The link is a %s link, but the row says %s: left out.', Platforms::PLATFORMS[$from], self::names(array_keys($platforms))),
        ];
      }
      elseif (!($platforms[$from]['url'] ?? NULL)) {
        $platforms[$from] ??= $new();
        $platforms[$from]['url'] = $link;
      }
    }

    $price = NULL;
    if ($text('price') !== '') {
      $price = Values::money($values['price']);
      if ($price === NULL) {
        $problems[] = [self::NOTE, sprintf('Price "%s" is not a price: left out.', $text('price'))];
      }
    }
    foreach ($platforms as $platform => $listing) {
      $platforms[$platform]['price'] ??= $price;
    }

    // Sold? From the status column, a Sold? column or a "Sold here".
    $status = Values::status($values['status'] ?? NULL);
    if ($status === NULL) {
      $problems[] = [self::NOTE, sprintf('Status "%s" not understood: treated as listed.', $text('status'))];
      $status = Values::STATUS_LISTED;
    }
    $sold_here = array_keys(array_filter($platforms, fn ($l) => $l['status'] === Platforms::SOLD_HERE));
    if ($sold_here || Values::isYes($values['sold_flag'] ?? NULL)) {
      $status = Values::STATUS_SOLD;
    }
    // "Sold on" holds a platform name or a date (or both, in some sheets).
    $sold_on = $text('sold_on');
    $sold_on_platform = NULL;
    $sold_on_date = NULL;
    if ($sold_on !== '') {
      $sold_on_date = Values::date($values['sold_on']);
      $sold_named = Values::platforms($values['sold_on']);
      $sold_on_platform = $sold_on_date === NULL && count($sold_named['platforms']) === 1 ? $sold_named['platforms'][0] : NULL;
      if ($sold_on_date === NULL && $sold_on_platform === NULL) {
        $problems[] = [self::NOTE, sprintf('Sold on "%s" not understood.', $sold_on)];
      }
    }

    // An ended row isn't live where its "Platform(s)" column says.
    if ($status === Values::STATUS_ENDED) {
      $platforms = array_diff_key($platforms, array_flip($named['platforms']));
    }

    $key = $text('sku') !== '' ? 'sku:' . mb_strtolower($text('sku')) : 'title:' . Values::titleKey($text('title'));
    return [
      'row' => (int) $row['row'],
      'key' => $key === 'title:' ? '' : $key,
      'title' => $text('title'),
      'status' => $status === '' ? Values::STATUS_LISTED : $status,
      'status_text' => $text('status'),
      'platforms' => $platforms,
      'sold_here' => $sold_here,
      'sold_on_platform' => $sold_on_platform,
      'sold_on_date' => $sold_on_date,
      'price' => $price,
      'values' => $values,
      'problems' => $problems,
    ];
  }

  /**
   * One item from a group of rows.
   *
   * @return array
   *   rows (sheet row numbers), title, sku, kind (KIND_*), sold_on
   *   (platform or NULL), sold_price, date_sold, listings (platform =>
   *   status, url, price, date_posted), fields (asking_price, quantity,
   *   location, brand (name), size, color, condition, category (term IDs),
   *   cost, description, flaws, notes, ai_price_min, ai_price_max), filled
   *   (field names taken from the Crosslister record), photos (record photo
   *   paths), record (Crosslister number or NULL), sheet_note (the
   *   sheet's own note, or NULL), problems ([level, message]), importable.
   */
  private static function item(array $group, array $context): array {
    $first = $group[0];
    $problems = [];
    foreach ($group as $read) {
      foreach ($read['problems'] as $problem) {
        $problems[] = count($group) > 1 ? [$problem[0], sprintf('Row %d: %s', $read['row'], $problem[1])] : $problem;
      }
    }
    // The first non-empty value of each column across the group's rows.
    $value = function (string $target) use ($group) {
      foreach ($group as $read) {
        if (Values::text($read['values'][$target] ?? NULL) !== '') {
          return $read['values'][$target];
        }
      }
      return NULL;
    };
    $text = fn (string $target) => Values::text($value($target));

    $title = $first['title'];
    if ($title === '') {
      $problems[] = [self::ERROR, 'No title.'];
    }
    elseif (mb_strlen($title) > self::TITLE_MAX) {
      $title = mb_substr($title, 0, self::TITLE_MAX);
      $problems[] = [self::NOTE, sprintf('Title shortened to %d characters.', self::TITLE_MAX)];
    }

    $date_listed = NULL;
    if ($text('date_listed') !== '') {
      $date_listed = Values::date($value('date_listed'));
      if ($date_listed === NULL) {
        $problems[] = [self::NOTE, sprintf('Date listed "%s" not understood: left out.', $text('date_listed'))];
      }
    }
    $listings = [];
    foreach ($group as $read) {
      foreach ($read['platforms'] as $platform => $listing) {
        $listings[$platform] = $listing + ['date_posted' => $date_listed];
      }
    }
    $listings = array_intersect_key(array_replace(Platforms::PLATFORMS, $listings), $listings);
    $statuses = array_column($group, 'status');
    $live = array_filter($listings, fn ($l) => in_array($l['status'], [Platforms::POSTED, Platforms::AT_RISK], TRUE));

    // Which platform it sold on: "Sold here", else "Sold on", else the
    // only platform.
    $kind = in_array(Values::STATUS_SOLD, $statuses, TRUE) ? self::KIND_SOLD : NULL;
    $sold_platform = NULL;
    if ($kind === self::KIND_SOLD) {
      $sold_here = array_values(array_unique(array_merge(...array_column($group, 'sold_here'))));
      $sold_on = array_values(array_filter(array_unique(array_column($group, 'sold_on_platform'))));
      if (count($sold_here) === 1) {
        $sold_platform = $sold_here[0];
        if ($sold_on && $sold_on !== $sold_here) {
          $problems[] = [
            self::NOTE,
            sprintf('Sold on says %s, but %s says Sold here: %s used.', self::names($sold_on), Platforms::PLATFORMS[$sold_platform], Platforms::PLATFORMS[$sold_platform]),
          ];
        }
      }
      elseif (count($sold_here) > 1) {
        $problems[] = [self::WARN, sprintf('Sold here on more than one platform (%s).', self::names($sold_here))];
      }
      elseif (count($sold_on) === 1) {
        $sold_platform = $sold_on[0];
      }
      elseif (count($listings) === 1) {
        $sold_platform = array_key_first($listings);
      }
      else {
        $problems[] = [self::WARN, $listings
            ? sprintf('Sold, but on which platform? It has %s.', self::names(array_keys($listings)))
            : 'Sold, but the sheet doesn\'t say where.',
        ];
      }
      if ($sold_platform !== NULL) {
        $listings[$sold_platform] ??= [
          'status' => Platforms::SOLD_HERE,
          'url' => NULL,
          'price' => $first['price'],
          'date_posted' => $date_listed,
        ];
        $listings[$sold_platform]['status'] = Platforms::SOLD_HERE;
        $listings = array_intersect_key(array_replace(Platforms::PLATFORMS, $listings), $listings);
        // The "Sold here" cascade runs on import, as it does on the site.
        $changes = Cascade::plan(array_map(fn ($l) => $l['status'], $listings), $sold_platform);
        foreach ($changes as $platform => $change) {
          $listings[$platform]['status'] = $change['to'];
          if ($change['to'] === Platforms::TAKE_DOWN) {
            $problems[] = [
              self::NOTE,
              sprintf('%s still says %s: it becomes Take down (sold on %s).', Platforms::PLATFORMS[$platform], Platforms::STATUSES[$change['from']], Platforms::PLATFORMS[$sold_platform]),
            ];
          }
        }
      }
    }
    elseif ($live) {
      $kind = self::KIND_LISTED;
    }
    elseif (in_array(Values::STATUS_ENDED, $statuses, TRUE)) {
      $kind = self::KIND_ENDED;
      $problems[] = [self::WARN, sprintf('Marked "%s" in the sheet: not live.', $first['status_text'])];
    }
    elseif ($listings) {
      $kind = self::KIND_READY;
      $problems[] = [self::NOTE, 'Not posted anywhere yet: added as Ready.'];
    }
    else {
      $kind = self::KIND_READY;
      $problems[] = [self::WARN, 'No platform: it would be added as Ready, not posted anywhere.'];
    }

    $sold_price = NULL;
    $date_sold = NULL;
    if ($kind === self::KIND_SOLD) {
      if ($text('sold_price') !== '') {
        $sold_price = Values::money($value('sold_price'));
        if ($sold_price === NULL) {
          $problems[] = [self::NOTE, sprintf('Sold price "%s" is not a price: left out.', $text('sold_price'))];
        }
      }
      else {
        // Never guessed from the listed price: the Money page and the tax
        // export show it as missing until Cody adds it.
        $problems[] = [self::NOTE, 'No sold price: left blank (add it on the item).'];
      }
      if ($text('date_sold') !== '') {
        $date_sold = Values::date($value('date_sold'));
        if ($date_sold === NULL) {
          $problems[] = [self::NOTE, sprintf('Date sold "%s" not understood.', $text('date_sold'))];
        }
      }
      $date_sold ??= array_values(array_filter(array_column($group, 'sold_on_date')))[0] ?? NULL;
      if ($date_sold === NULL) {
        $problems[] = [self::NOTE, 'No date sold: counted as sold on the day of the import.'];
      }
    }

    // Details.
    $fields = [
      'asking_price' => $first['price'] ?? (array_values(array_filter(array_column($listings, 'price'), fn ($p) => $p !== NULL))[0] ?? NULL),
      'quantity' => NULL,
      'location' => $context['default_location'],
      'brand' => $text('brand') ?: NULL,
      'size' => $text('size') !== '' ? self::short($text('size')) : NULL,
      'color' => $text('color') !== '' ? self::short($text('color')) : NULL,
      'condition' => NULL,
      'category' => NULL,
      'cost' => NULL,
      'description' => $text('description') ?: NULL,
      'flaws' => NULL,
      'notes' => NULL,
      'ai_price_min' => NULL,
      'ai_price_max' => NULL,
    ];
    if ($text('quantity') !== '') {
      $fields['quantity'] = Values::quantity($value('quantity'));
      if ($fields['quantity'] === NULL) {
        $problems[] = [self::NOTE, sprintf('Quantity "%s" not understood: 1 used.', $text('quantity'))];
      }
    }
    if ($text('location') !== '') {
      $tid = self::term($text('location'), $context['locations']);
      if ($tid === NULL) {
        $problems[] = [
          self::NOTE,
          sprintf('Location "%s" is not one of your places: put in the default place.', $text('location')),
        ];
      }
      $fields['location'] = $tid ?? $context['default_location'];
    }
    foreach (['condition' => 'conditions', 'category' => 'categories'] as $field => $list) {
      if ($text($field) !== '') {
        $fields[$field] = self::matchValue($field, $text($field), $context[$list], $context['value_maps'][$field] ?? []);
        if ($fields[$field] === NULL) {
          $problems[] = [self::NOTE, sprintf('%s "%s" left blank.', ucfirst($field), $text($field))];
        }
      }
    }
    if ($text('cost') !== '') {
      $fields['cost'] = Values::money($value('cost'));
      if ($fields['cost'] === NULL) {
        $problems[] = [self::NOTE, sprintf('What you paid "%s" is not a price: left out.', $text('cost'))];
      }
    }
    $sku = $text('sku');
    $notes = array_filter([$text('notes'), $sku !== '' ? 'Item number in the sheet: ' . $sku : '']);
    $fields['notes'] = $notes ? implode("\n", $notes) : NULL;
    foreach ($listings as $platform => $listing) {
      if ($listing['price'] === NULL && in_array($listing['status'], [
        Platforms::POSTED,
        Platforms::AT_RISK,
        Platforms::TO_POST,
      ], TRUE)) {
        $problems[] = [self::NOTE, sprintf('No price for %s.', Platforms::PLATFORMS[$platform])];
      }
    }

    // The old Crosslister record with the same number, if it's the same item.
    $record = NULL;
    $filled = [];
    $photos = [];
    if ($sku !== '' && isset($context['records'][$sku])) {
      $candidate = $context['records'][$sku];
      if (self::sameTitle($title, (string) ($candidate['title'] ?? ''))) {
        $record = $sku;
        if ($context['fill']) {
          [$fields, $filled] = self::fill($fields, $candidate, $context);
          $photos = array_values($candidate['photos'] ?? []);
        }
      }
      else {
        $problems[] = [
          self::NOTE,
          sprintf('Crosslister record %s has a different title ("%s"): not used.', $sku, mb_strimwidth((string) ($candidate['title'] ?? ''), 0, 50, '…')),
        ];
      }
    }

    // Already on the site?
    foreach ($listings as $platform => $listing) {
      $key = $listing['url'] !== NULL ? Values::urlKey($listing['url']) : '';
      if ($key !== '' && isset($context['urls'][$key])) {
        $problems[] = [
          self::WARN,
          sprintf('Already on the site: item %d has this %s link.', $context['urls'][$key], Platforms::PLATFORMS[$platform]),
        ];
      }
    }
    $title_key = Values::titleKey($title);
    if ($title_key !== '' && isset($context['titles'][$title_key])) {
      $problems[] = [
        self::WARN,
        sprintf('Already on the site? Item %d has the same title.', $context['titles'][$title_key]),
      ];
    }

    return [
      'rows' => array_column($group, 'row'),
      'title' => $title,
      'sku' => $sku,
      'kind' => $kind,
      'sold_on' => $sold_platform,
      'sold_price' => $sold_price,
      'date_sold' => $date_sold,
      'listings' => $listings,
      'fields' => $fields,
      'filled' => $filled,
      'photos' => $photos,
      'record' => $record,
      'sheet_note' => $text('notes') !== '' ? $text('notes') : NULL,
      'problems' => $problems,
      'importable' => $title !== '' && !($kind === self::KIND_SOLD && $sold_platform === NULL),
    ];
  }

  /**
   * Fills empty details from an old Crosslister record.
   *
   * Never overwrites what the sheet says.
   *
   * @return array{0: array, 1: string[]}
   *   The fields and the names of the ones filled in.
   */
  private static function fill(array $fields, array $record, array $context): array {
    $filled = [];
    $take = function (string $field, mixed $value) use (&$fields, &$filled) {
      if (($fields[$field] === NULL || $fields[$field] === '') && $value !== NULL && $value !== '') {
        $fields[$field] = $value;
        $filled[] = $field;
      }
    };
    $text = fn (string $key) => trim((string) ($record[$key] ?? ''));
    $take('description', $text('description'));
    $take('flaws', $text('condition_notes'));
    $take('size', self::short($text('size')));
    $take('color', self::short($text('color')));
    $take('brand', $text('brand'));
    if ($text('condition') !== '') {
      $take('condition', self::matchValue('condition', $text('condition'), $context['conditions'], []));
    }
    $min = is_numeric($record['price_min'] ?? NULL) ? round((float) $record['price_min'], 2) : NULL;
    $max = is_numeric($record['price_max'] ?? NULL) ? round((float) $record['price_max'], 2) : NULL;
    if ($min !== NULL && $max !== NULL && $min <= $max) {
      $take('ai_price_min', $min);
      $take('ai_price_max', $max);
    }
    return [$fields, $filled];
  }

  /**
   * Size and color hold at most this many characters (field storage).
   */
  public const SHORT_MAX = 60;

  /**
   * A size or color cut to SHORT_MAX characters, at a word where it can.
   */
  public static function short(string $text): string {
    if (mb_strlen($text) <= self::SHORT_MAX) {
      return $text;
    }
    $cut = mb_substr($text, 0, self::SHORT_MAX - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > self::SHORT_MAX / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;/-') . '…';
  }

  /**
   * Whether two titles are the same item.
   *
   * Most of the shorter one's words are in the longer one (the sheet trims
   * the son's titles).
   */
  public static function sameTitle(string $a, string $b): bool {
    $a = array_unique(explode(' ', Values::titleKey($a)));
    $b = array_unique(explode(' ', Values::titleKey($b)));
    if ($a === [''] || $b === ['']) {
      return FALSE;
    }
    $shorter = count($a) <= count($b) ? $a : $b;
    $longer = count($a) <= count($b) ? $b : $a;
    return count(array_intersect($shorter, $longer)) >= 0.7 * count($shorter);
  }

  /**
   * A category or condition: Cody's own match first, then by name.
   */
  private static function matchValue(string $field, string $value, array $terms, array $map): ?int {
    $key = mb_strtolower(trim($value));
    if (array_key_exists($key, $map)) {
      return $map[$key] ? (int) $map[$key] : NULL;
    }
    return self::term($value, $terms, $field === 'condition' ? self::CONDITION_WORDS : []);
  }

  /**
   * A term ID by name or "parent > child" path (case-insensitive), or NULL.
   */
  private static function term(string $name, array $terms, array $aliases = []): ?int {
    $key = mb_strtolower(trim(preg_replace('/\s*(>|›)\s*/u', ' > ', $name) ?? ''));
    $key = $aliases[$key] ?? $key;
    return isset($terms[$key]) ? (int) $terms[$key] : NULL;
  }

  /**
   * Platform names, comma separated.
   */
  private static function names(array $platforms): string {
    return implode(', ', array_map(fn ($p) => Platforms::PLATFORMS[$p] ?? $p, $platforms));
  }

  /**
   * Counts for the preview's summary.
   *
   * @param array $items
   *   The plan.
   * @param bool $ticked_only
   *   Count only the ticked items.
   *
   * @return array
   *   items, rows, ticked, problems (items with any), kinds (KIND_* =>
   *   count), sold_total, platforms (platform => status => count, with
   *   'blank' for no listing), filled (items with Crosslister details),
   *   photos (photo count) and photo_items.
   */
  public static function counts(array $items, bool $ticked_only = FALSE): array {
    $counts = [
      'items' => 0,
      'rows' => 0,
      'ticked' => 0,
      'problems' => 0,
      'kinds' => array_fill_keys([self::KIND_LISTED, self::KIND_SOLD, self::KIND_READY, self::KIND_ENDED], 0),
      'sold_total' => 0.0,
      'platforms' => [],
      'records' => 0,
      'filled' => 0,
      'photos' => 0,
      'photo_items' => 0,
    ];
    foreach (array_keys(Platforms::PLATFORMS) as $platform) {
      $counts['platforms'][$platform] = array_fill_keys(array_keys(Platforms::STATUSES), 0) + ['blank' => 0];
    }
    foreach ($items as $item) {
      if ($ticked_only && !$item['tick']) {
        continue;
      }
      $counts['items']++;
      $counts['rows'] += count($item['rows']);
      $counts['ticked'] += (int) $item['tick'];
      $counts['problems'] += (int) (bool) $item['problems'];
      $counts['kinds'][$item['kind']]++;
      if ($item['kind'] === self::KIND_SOLD) {
        $counts['sold_total'] += (float) $item['sold_price'];
      }
      foreach (array_keys(Platforms::PLATFORMS) as $platform) {
        $counts['platforms'][$platform][$item['listings'][$platform]['status'] ?? 'blank']++;
      }
      $counts['records'] += (int) ($item['record'] !== NULL);
      $counts['filled'] += (int) (bool) $item['filled'];
      $counts['photos'] += count($item['photos']);
      $counts['photo_items'] += (int) (bool) $item['photos'];
    }
    $counts['sold_total'] = round($counts['sold_total'], 2);
    return $counts;
  }

}
