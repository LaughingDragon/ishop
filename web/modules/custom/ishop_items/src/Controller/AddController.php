<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\PhotoPrep;
use Drupal\ishop_office\DiskStatus;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The phone flow's photo upload and the home-screen app manifest.
 */
final class AddController extends ControllerBase {

  /**
   * Photo file types accepted (HEIC/HEIF become JPEG on save).
   */
  public const EXTENSIONS = 'jpg jpeg png webp heic heif';

  /**
   * Largest photo accepted, in bytes (PHP allows 32 MB uploads).
   */
  public const MAX_BYTES = 32 * 1024 * 1024;

  public function __construct(
    private readonly FileUploadHandlerInterface $uploadHandler,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Receives one photo, prepares it and returns its media ID and thumbnail.
   */
  public function uploadPhoto(Request $request): JsonResponse {
    if (!DiskStatus::photosOnline()) {
      return new JsonResponse(['error' => (string) $this->t("Photo storage is offline right now, so photos can't be added. Tell site-foreman.")], 503);
    }
    $upload = $request->files->get('photo');
    if (!$upload instanceof UploadedFile || !$upload->isValid()) {
      return new JsonResponse(['error' => (string) $this->t('The photo did not arrive. Try again.')], 400);
    }
    $media = $this->saveUpload($upload);
    if (is_string($media)) {
      return new JsonResponse(['error' => $media], 422);
    }
    return new JsonResponse(self::describe($media));
  }

  /**
   * Saves an uploaded photo as an Item photo; returns the media or an error.
   */
  public function saveUpload(UploadedFile $upload): Media|string {
    $directory = PhotoPrep::SCHEME_PREFIX . date('Y-m');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $result = $this->uploadHandler->handleFileUpload(new FormUploadedFile($upload), [
      'FileExtension' => ['extensions' => self::EXTENSIONS],
      'FileSizeLimit' => ['fileLimit' => self::MAX_BYTES],
      'FileIsImage' => [],
    ], $directory, FileExists::Rename);
    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = strip_tags((string) $violation->getMessage());
      }
      return implode(' ', $messages);
    }
    $file = $result->getFile();
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'item_photo',
      'uid' => $this->currentUser()->id(),
      'name' => $file->getFilename(),
      'field_media_image_1' => ['target_id' => $file->id()],
      'status' => 1,
    ]);
    $media->save();
    return $media;
  }

  /**
   * ID, thumbnail URL and name of an Item photo, for the phone flow.
   */
  public static function describe(Media $media): array {
    $file = $media->get('field_media_image_1')->entity;
    return [
      'mid' => (int) $media->id(),
      'name' => (string) $media->label(),
      'thumb' => $file ? ImageStyle::load('medium')->buildUrl($file->getFileUri()) : '',
    ];
  }

  /**
   * The web app manifest: installs "Reseller Matrix" to the home screen.
   *
   * Public (browsers fetch it without cookies) and contains nothing private.
   * No service worker and no offline copy of private data.
   */
  public function manifest(): CacheableJsonResponse {
    $icons = '/' . $this->moduleHandler()->getModule('ishop_items')->getPath() . '/icons';
    $response = new CacheableJsonResponse([
      'name' => 'Reseller Matrix',
      'short_name' => 'Reseller Matrix',
      'description' => 'Add items and run the shop.',
      'start_url' => '/add?source=homescreen',
      'scope' => '/',
      'display' => 'standalone',
      'orientation' => 'portrait',
      'background_color' => '#161312',
      'theme_color' => '#161312',
      'icons' => [
        ['src' => "$icons/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$icons/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$icons/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
      ],
    ]);
    $response->headers->set('Content-Type', 'application/manifest+json');
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(86400));
    return $response;
  }

}
