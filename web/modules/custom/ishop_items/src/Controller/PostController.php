<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\Cleared;
use Drupal\ishop_items\Eligibility;
use Drupal\ishop_items\PlatformCategories;
use Drupal\ishop_items\Form\ListingsGridForm;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\Platforms;
use Drupal\ishop_items\PostPack;
use Drupal\ishop_items\ReadyCheck;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "Post this item" page: paste-ready fields and photos (Phase 3).
 */
final class PostController extends ControllerBase {

  /**
   * Reseller Matrix chip modifier per listing status.
   */
  private const STATUS_CHIPS = [
    Platforms::TO_POST => 'todo',
    Platforms::POSTED => 'live',
    Platforms::AT_RISK => 'live',
    Platforms::SOLD_HERE => 'sold',
    Platforms::TAKE_DOWN => 'alert',
  ];

  public function __construct(
    private readonly ListingManager $listings,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Without a platform: the first one still to post (and allowed).
   */
  public function start(NodeInterface $node): RedirectResponse {
    if (!Cleared::isCleared($node)) {
      $this->messenger()->addWarning($this->t("Check it's OK to sell first, then tick Cleared to sell on the item."));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }
    $facts = ListingsGridForm::eligibilityFacts($node);
    $first = array_key_first(Platforms::PLATFORMS);
    foreach ($this->listings->forItem($node) as $platform => $listing) {
      if ($listing->getStatus() === Platforms::TO_POST && $this->eligibility($facts, $listing, $platform)['verdict'] !== Eligibility::NO) {
        $first = $platform;
        break;
      }
    }
    return $this->redirect('ishop_items.post', ['node' => $node->id(), 'platform' => $first]);
  }

  /**
   * Title callback.
   */
  public function title(NodeInterface $node, string $platform): string {
    return (string) $this->t('Post on @platform: @title', [
      '@platform' => Platforms::PLATFORMS[$platform] ?? $platform,
      '@title' => $node->label(),
    ]);
  }

  /**
   * One platform's tab.
   */
  public function page(NodeInterface $node, string $platform): array {
    $this->checkPlatform($platform);
    if (!Cleared::isCleared($node)) {
      return [
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t("Check it's OK to sell first, then tick Cleared to sell on the item."),
        ],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Edit the item'),
          '#url' => $node->toUrl('edit-form'),
          '#attributes' => ['class' => ['rm-button', 'rm-button--gold']],
        ],
        '#cache' => ['tags' => $node->getCacheTags()],
      ];
    }
    $facts = ListingsGridForm::eligibilityFacts($node);
    $listings = $this->listings->forItem($node);

    $tabs = [];
    foreach (Platforms::PLATFORMS as $key => $name) {
      $listing = $listings[$key] ?? NULL;
      $status = $listing?->getStatus();
      $tabs[] = [
        'name' => $name,
        'url' => Url::fromRoute('ishop_items.post', ['node' => $node->id(), 'platform' => $key])->toString(),
        'current' => $key === $platform,
        'status' => $status ? Platforms::STATUSES[$status] : $this->t('No listing'),
        'chip' => self::STATUS_CHIPS[$status] ?? '',
        'verdict' => $this->eligibility($facts, $listing, $key)['verdict'],
      ];
    }

    $pack = PostPack::build($platform, self::listingValues($node));
    $spec = $pack['photo_spec'];
    $style = ImageStyle::load('post_' . $platform);
    $all_photos = self::photoUris($node);
    $photos = [];
    foreach (array_slice($all_photos, 0, $spec['max_photos']) as $i => $uri) {
      $photos[] = [
        'url' => $style->buildUrl($uri),
        'name' => sprintf('%02d.jpg', $i + 1),
      ];
    }

    [$previous, $next, $queue_size] = $this->queueNeighbours($node, $platform);
    $listing = $listings[$platform] ?? NULL;

    return [
      '#theme' => 'ishop_post_page',
      '#item' => [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'listings_url' => $node->toUrl()->setOption('fragment', 'listings')->toString(),
      ],
      '#platform' => [
        'key' => $platform,
        'name' => Platforms::PLATFORMS[$platform],
        'display_name' => $pack['display_name'],
        'status' => $listing ? Platforms::STATUSES[$listing->getStatus()] : NULL,
        'chip' => $listing ? (self::STATUS_CHIPS[$listing->getStatus()] ?? '') : '',
      ],
      '#tabs' => $tabs,
      '#eligibility' => $this->eligibility($facts, $listing, $platform),
      '#missing' => ReadyCheck::missing(ItemHooks::checkValues($node)),
      '#fields' => $pack['fields'],
      '#photos' => $photos,
      '#photo_note' => $spec['note'],
      '#photos_left_out' => max(count($all_photos) - $spec['max_photos'], 0),
      '#zip_url' => $photos ? Url::fromRoute('ishop_items.post_zip', ['node' => $node->id(), 'platform' => $platform])->toString() : NULL,
      '#tips' => $pack['tips'],
      '#queue' => [
        'previous' => $previous ? Url::fromRoute('ishop_items.post', ['node' => $previous, 'platform' => $platform])->toString() : NULL,
        'next' => $next ? Url::fromRoute('ishop_items.post', ['node' => $next, 'platform' => $platform])->toString() : NULL,
        'size' => $queue_size,
      ],
      '#attached' => ['library' => ['ishop_items/post']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The platform's photos, resized, as a ZIP (01.jpg, 02.jpg, ...).
   */
  public function zip(NodeInterface $node, string $platform): BinaryFileResponse {
    $this->checkPlatform($platform);
    if (!Cleared::isCleared($node)) {
      throw new AccessDeniedHttpException(Cleared::MESSAGE);
    }
    $spec = PostPack::PHOTO_SPECS[$platform];
    $style = ImageStyle::load('post_' . $platform);
    $uris = array_slice(self::photoUris($node), 0, $spec['max_photos']);
    if (!$uris) {
      throw new NotFoundHttpException();
    }
    $path = $this->fileSystem->realpath($this->fileSystem->tempnam('temporary://', 'ishop-post-'));
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException('Could not create the photo ZIP.');
    }
    foreach ($uris as $i => $uri) {
      $derivative = $style->buildUri($uri);
      if (!file_exists($derivative) && !$style->createDerivative($uri, $derivative)) {
        continue;
      }
      // Already-compressed JPEGs: store them, don't spend CPU deflating.
      $name = sprintf('%02d.jpg', $i + 1);
      $zip->addFile($this->fileSystem->realpath($derivative), $name);
      $zip->setCompressionName($name, \ZipArchive::CM_STORE);
    }
    $zip->close();

    $response = new BinaryFileResponse($path, 200, [
      'Content-Type' => 'application/zip',
      'Cache-Control' => 'private, no-store',
    ]);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('%s-item-%d.zip', $platform, $node->id()));
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  /**
   * Plain values the packs are built from.
   */
  public static function listingValues(NodeInterface $node): array {
    $price = $node->get('field_asking_price')->value;
    $category = ListingsGridForm::eligibilityFacts($node)['category'] ?: NULL;
    // Size is for clothing and shoes only (item 274): an older AI draft put
    // "5000mAh" in a power bank's Size, so it stays out of the paste text.
    $size = $category === NULL || PlatformCategories::hasSize($category) ? $node->get('field_size')->value : NULL;
    return [
      'title' => (string) $node->label(),
      'description' => (string) ($node->get('field_description')->value ?? ''),
      'brand' => $node->get('field_brand')->entity?->label(),
      'size' => $size,
      'color' => $node->get('field_color')->value,
      'condition' => $node->get('field_condition')->entity?->label(),
      'condition_notes' => $node->get('field_flaws')->value,
      'category' => $category,
      'price' => $price === NULL || $price === '' ? NULL : (float) $price,
    ];
  }

  /**
   * The item's photo file URIs, in order.
   *
   * @return string[]
   *   URIs such as private://items/2026-10/image.jpg.
   */
  public static function photoUris(NodeInterface $node): array {
    $uris = [];
    foreach ($node->get('field_photos') as $reference) {
      if ($file = $reference->entity?->get('field_media_image_1')->entity) {
        $uris[] = $file->getFileUri();
      }
    }
    return $uris;
  }

  /**
   * Previous and next items to post on this platform, and how many there are.
   *
   * The queue is every item with a "To post" listing here that the rules
   * don't rule out, oldest first.
   *
   * @return array{0: ?int, 1: ?int, 2: int}
   *   Previous item ID, next item ID, queue size.
   */
  private function queueNeighbours(NodeInterface $node, string $platform): array {
    $queue = array_keys($this->listings->toPostQueue($platform));
    $current = (int) $node->id();
    $before = array_filter($queue, fn ($id) => $id < $current);
    $after = array_filter($queue, fn ($id) => $id > $current);
    return [$before ? max($before) : NULL, $after ? min($after) : NULL, count($queue)];
  }

  /**
   * The eligibility verdict, with the listing's manual override if any.
   */
  private function eligibility(array $facts, $listing, string $platform): array {
    return PlatformCategories::evaluate($facts + [
      'override' => $listing?->get('eligibility_override')->value,
      'override_note' => $listing?->get('eligibility_note')->value,
    ], $platform);
  }

  /**
   * Not found for anything that isn't one of the site's platforms.
   */
  private function checkPlatform(string $platform): void {
    if (!isset(Platforms::PLATFORMS[$platform])) {
      throw new NotFoundHttpException();
    }
  }

}
