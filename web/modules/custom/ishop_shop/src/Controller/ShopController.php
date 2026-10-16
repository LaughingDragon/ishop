<?php

declare(strict_types=1);

namespace Drupal\ishop_shop\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\ishop_shop\Shop;
use Drupal\ishop_shop\ShopPhotos;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The public shop pages: /shop, /shop/{slug} and the sitemap.
 *
 * Everything here answers "not found" while the switch is off, except to
 * Cody and Liam, who see a preview. Only Shop::PUBLIC_FIELDS are read.
 */
final class ShopController extends ControllerBase {

  public function __construct(
    private readonly Shop $shop,
    private readonly ShopPhotos $photos,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Cacheability every shop response shares.
   */
  private function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->setCacheTags(Shop::CACHE_TAGS)
      ->setCacheContexts(['user']);
  }

  /**
   * Refuses unless the shop answers for the current user.
   */
  private function assertVisible(): void {
    if (!$this->shop->visibleTo($this->currentUser())) {
      throw new CacheableNotFoundHttpException($this->cacheability());
    }
  }

  /**
   * The preview notice, or NULL when the shop is on.
   */
  private function preview(): ?string {
    return $this->shop->isOn() ? NULL : (string) $this->t('Preview: the public shop is off. Visitors who are not signed in get "Not found". Switch it in the site office (Public shop).');
  }

  /**
   * /shop: photo cards, newest first, with a category filter.
   */
  public function catalog(Request $request): array {
    $this->assertVisible();
    $category = $request->query->get('category');
    $category = is_numeric($category) ? (int) $category : NULL;
    $categories = $this->shop->categories();
    if ($category !== NULL && !isset($categories[$category])) {
      $category = NULL;
    }
    $cards = [];
    foreach ($this->shop->items($category) as $item) {
      $files = $this->shop->photos($item);
      $cards[] = [
        'title' => $item->label(),
        'price' => Shop::price($item->get('field_asking_price')->value),
        'url' => Url::fromRoute('ishop_shop.item', ['slug' => $this->shop->slug($item)])->toString(),
        'photo' => $files ? $this->photos->url($files[0]) : NULL,
        'condition' => $item->get('field_condition')->entity?->label(),
      ];
    }
    $filters = [['label' => $this->t('All'), 'url' => Url::fromRoute('ishop_shop.catalog')->toString(), 'active' => $category === NULL]];
    foreach ($categories as $tid => $name) {
      $filters[] = [
        'label' => $name,
        'url' => Url::fromRoute('ishop_shop.catalog', [], ['query' => ['category' => $tid]])->toString(),
        'active' => $category === $tid,
      ];
    }
    $build = [
      '#theme' => 'ishop_shop_catalog',
      '#cards' => $cards,
      '#filters' => count($filters) > 2 ? $filters : [],
      '#preview' => $this->preview(),
      '#attached' => [
        'library' => ['ishop_shop/shop'],
        'html_head' => [
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => Url::fromRoute('ishop_shop.catalog', [], ['absolute' => TRUE])->toString()]], 'ishop_shop_canonical'],
          [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => (string) $this->t('Pre-owned finds from @site, each with links to buy on the marketplaces.', ['@site' => $this->config('system.site')->get('name')])]], 'ishop_shop_description'],
        ],
      ],
    ];
    $this->cacheability()->addCacheContexts(['url.query_args:category'])->applyTo($build);
    return $build;
  }

  /**
   * The item in the shop for a slug, or "not found".
   */
  private function load(string $slug): NodeInterface {
    $nid = Shop::nidFromSlug($slug);
    $item = $nid ? $this->entityTypeManager()->getStorage('node')->load($nid) : NULL;
    if (!$item instanceof NodeInterface || !$this->shop->inShop($item)) {
      throw new CacheableNotFoundHttpException($this->cacheability());
    }
    return $item;
  }

  /**
   * Title callback for an item page.
   */
  public function itemTitle(string $slug): string {
    $nid = Shop::nidFromSlug($slug);
    $item = $nid ? $this->entityTypeManager()->getStorage('node')->load($nid) : NULL;
    return $item instanceof NodeInterface && $this->shop->inShop($item) ? (string) $item->label() : (string) $this->t('Shop');
  }

  /**
   * /shop/{slug}: one item.
   */
  public function item(string $slug): array|LocalRedirectResponse {
    $this->assertVisible();
    $item = $this->load($slug);
    $canonical = $this->shop->slug($item);
    if ($slug !== $canonical) {
      $redirect = new LocalRedirectResponse(Url::fromRoute('ishop_shop.item', ['slug' => $canonical])->toString(), 301);
      $redirect->addCacheableDependency($this->cacheability()->addCacheTags($item->getCacheTags()));
      return $redirect;
    }
    $photos = [];
    foreach ($this->shop->photos($item) as $file) {
      if ($url = $this->photos->url($file)) {
        $photos[] = $url;
      }
    }
    $description = trim((string) $item->get('field_description')->value);
    $url = Url::fromRoute('ishop_shop.item', ['slug' => $canonical], ['absolute' => TRUE])->toString();
    $summary = mb_strimwidth((string) preg_replace('/\s+/', ' ', $description ?: (string) $item->label()), 0, 160, '…');
    $head = [
      [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $url]], 'ishop_shop_canonical'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $summary]], 'ishop_shop_description'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:type', 'content' => 'product']], 'ishop_shop_og_type'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:title', 'content' => (string) $item->label()]], 'ishop_shop_og_title'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:description', 'content' => $summary]], 'ishop_shop_og_description'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $url]], 'ishop_shop_og_url'],
    ];
    // Link previews only ever get the public copy (never in a preview).
    if ($this->shop->isOn() && $photos) {
      $head[] = [['#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost() . $photos[0]]], 'ishop_shop_og_image'];
    }
    $build = [
      '#theme' => 'ishop_shop_item',
      '#title_text' => $item->label(),
      '#price' => Shop::price($item->get('field_asking_price')->value),
      '#photos' => $photos,
      '#description' => $description,
      '#flaws' => trim((string) $item->get('field_flaws')->value),
      '#size' => trim((string) $item->get('field_size')->value),
      '#condition' => $item->get('field_condition')->entity?->label(),
      '#links' => array_values($this->shop->buyLinks($item)),
      '#back' => Url::fromRoute('ishop_shop.catalog')->toString(),
      '#preview' => $this->preview(),
      '#attached' => ['library' => ['ishop_shop/shop'], 'html_head' => $head],
    ];
    $this->cacheability()->addCacheTags($item->getCacheTags())->applyTo($build);
    return $build;
  }

  /**
   * /shop/sitemap.xml: the shop pages, and only those (only while on).
   */
  public function sitemap(): CacheableResponse {
    $cache = $this->cacheability();
    if (!$this->shop->isOn()) {
      throw new CacheableNotFoundHttpException($cache);
    }
    $urls = [['loc' => Url::fromRoute('ishop_shop.catalog', [], ['absolute' => TRUE])->toString(), 'lastmod' => NULL]];
    foreach ($this->shop->items() as $item) {
      $urls[] = [
        'loc' => Url::fromRoute('ishop_shop.item', ['slug' => $this->shop->slug($item)], ['absolute' => TRUE])->toString(),
        'lastmod' => date('Y-m-d', (int) $item->getChangedTime()),
      ];
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
      $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>' . ($u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '') . "</url>\n";
    }
    $xml .= "</urlset>\n";
    $response = new CacheableResponse($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    $response->addCacheableDependency($cache);
    return $response;
  }

}
