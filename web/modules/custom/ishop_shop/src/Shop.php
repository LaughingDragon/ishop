<?php

declare(strict_types=1);

namespace Drupal\ishop_shop;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\ishop_items\Cleared;
use Drupal\ishop_items\Platforms;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The public shop (Phase 8, decision 266): which items show and what of them.
 *
 * An item is in the shop only when it is published, "Show in shop" is ticked,
 * it is Ready or Listed, it is cleared to sell, and it has a price and at
 * least one photo. The public pages show only the fields in PUBLIC_FIELDS:
 * never cost paid, storage location, internal notes, AI notes or anything
 * from the office. Buyers buy through Cody's live marketplace listings
 * (links only: no contact form, his email address is never shown).
 */
final class Shop {

  public const CONFIG = 'ishop_shop.settings';

  /**
   * Who may switch the shop and preview it while it is off: Cody and Liam.
   */
  public const ACCOUNTS = ['theowner', 'webmaster'];

  /**
   * Item statuses that may show in the shop.
   */
  public const STATUSES = ['ready', 'listed'];

  /**
   * The only item fields the public pages read.
   */
  public const PUBLIC_FIELDS = [
    'title',
    'field_photos',
    'field_description',
    'field_flaws',
    'field_size',
    'field_condition',
    'field_category',
    'field_asking_price',
  ];

  /**
   * Cache tags for anything the shop pages show.
   */
  public const CACHE_TAGS = [
    'config:' . self::CONFIG,
    'node_list:item',
    'platform_listing_list',
    'media_list',
    'taxonomy_term_list',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'transliteration')]
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * Whether the switch is on.
   */
  public function isOn(): bool {
    return (bool) $this->configFactory->get(self::CONFIG)->get('enabled');
  }

  /**
   * Whether the account may switch the shop and preview it.
   */
  public static function canManage(AccountInterface $account): bool {
    return $account->isAuthenticated() && in_array($account->getAccountName(), self::ACCOUNTS, TRUE);
  }

  /**
   * Whether the shop pages answer for this account (on, or a preview).
   */
  public function visibleTo(AccountInterface $account): bool {
    return $this->isOn() || self::canManage($account);
  }

  /**
   * The shop rule, on plain facts.
   */
  public static function qualifies(bool $published, bool $show, ?string $status, bool $cleared, ?string $price, int $photos): bool {
    return $published
      && $show
      && in_array($status, self::STATUSES, TRUE)
      && $cleared
      && $price !== NULL && is_numeric($price) && (float) $price > 0
      && $photos > 0;
  }

  /**
   * Whether an item is in the shop (the switch aside).
   */
  public function inShop(NodeInterface $item): bool {
    return $item->bundle() === 'item' && self::qualifies(
      $item->isPublished(),
      (bool) $item->get('field_show_in_shop')->value,
      $item->get('field_item_status')->value,
      Cleared::isCleared($item),
      $item->get('field_asking_price')->value,
      count($this->photos($item)),
    );
  }

  /**
   * IDs of items in the shop, newest first, optionally in one category.
   *
   * @return int[]
   */
  public function itemIds(?int $category = NULL): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_show_in_shop', 1)
      ->condition('field_item_status', self::STATUSES, 'IN')
      ->condition(Cleared::FIELD, 1)
      ->condition('field_asking_price', 0, '>')
      ->exists('field_photos')
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC');
    if ($category) {
      $query->condition('field_category', $category);
    }
    $ids = array_map('intval', array_values($query->execute()));
    // The query is the fast path; inShop() is the rule (a photo's media may
    // have gone).
    return array_values(array_filter($ids, function (int $nid): bool {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      return $node instanceof NodeInterface && $this->inShop($node);
    }));
  }

  /**
   * Loads items in the shop.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function items(?int $category = NULL): array {
    $ids = $this->itemIds($category);
    return $ids ? array_values($this->entityTypeManager->getStorage('node')->loadMultiple($ids)) : [];
  }

  /**
   * The item's photo files, cover first.
   *
   * @return \Drupal\file\FileInterface[]
   */
  public function photos(NodeInterface $item): array {
    $files = [];
    foreach ($item->get('field_photos')->referencedEntities() as $media) {
      if ($media instanceof MediaInterface && $media->isPublished() && $media->hasField('field_media_image_1')) {
        $file = $media->get('field_media_image_1')->entity;
        if ($file instanceof FileInterface) {
          $files[] = $file;
        }
      }
    }
    return $files;
  }

  /**
   * Live marketplace links: Posted listings whose link is that platform's.
   *
   * @return array<string, array{label: string, url: string}>
   *   Keyed by platform, in the platform order.
   */
  public function buyLinks(NodeInterface $item): array {
    $listings = $this->entityTypeManager->getStorage('platform_listing')->loadByProperties([
      'item' => $item->id(),
      'status' => Platforms::POSTED,
    ]);
    $found = [];
    foreach ($listings as $listing) {
      $platform = (string) $listing->get('platform')->value;
      $url = trim((string) $listing->get('url')->value);
      if ($url !== '' && Platforms::fromUrl($url) === $platform) {
        $found[$platform] = $url;
      }
    }
    return self::orderLinks($found);
  }

  /**
   * Orders platform => URL pairs by the platform order, with labels.
   *
   * @return array<string, array{label: string, url: string}>
   */
  public static function orderLinks(array $found): array {
    $links = [];
    foreach (Platforms::PLATFORMS as $platform => $label) {
      if (isset($found[$platform])) {
        $links[$platform] = ['label' => $label, 'url' => $found[$platform]];
      }
    }
    return $links;
  }

  /**
   * The item's address slug, e.g. "wooden-puzzle-1000-pieces-121".
   */
  public function slug(NodeInterface $item): string {
    return self::slugFor($this->transliteration->transliterate((string) $item->label(), 'en'), (int) $item->id());
  }

  /**
   * Slug from an (ASCII) title and the item ID.
   */
  public static function slugFor(string $title, int $nid): string {
    $words = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    if (strlen($words) > 60) {
      $words = rtrim(substr($words, 0, 60), '-');
      $words = str_contains($words, '-') ? substr($words, 0, (int) strrpos($words, '-')) : $words;
    }
    return ($words === '' ? '' : $words . '-') . $nid;
  }

  /**
   * The item ID at the end of a slug, or NULL.
   */
  public static function nidFromSlug(string $slug): ?int {
    return preg_match('/(?:^|-)(\d+)$/', $slug, $m) ? (int) $m[1] : NULL;
  }

  /**
   * Categories that have shop items: term ID => name, by name.
   *
   * @return array<int, string>
   */
  public function categories(): array {
    $names = [];
    foreach ($this->items() as $item) {
      $term = $item->get('field_category')->entity;
      if ($term) {
        $names[(int) $term->id()] = (string) $term->label();
      }
    }
    asort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
  }

  /**
   * Price for display, e.g. "$24" or "$24.50".
   */
  public static function price(?string $value): string {
    $amount = (float) $value;
    return '$' . (fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2));
  }

}
