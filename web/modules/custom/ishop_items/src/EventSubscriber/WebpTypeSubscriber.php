<?php

declare(strict_types=1);

namespace Drupal\ishop_items\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Private WebP thumbnails are served as image/webp.
 *
 * For private files, core's image style controller takes its headers from
 * the original file's hook_file_download() (image/jpeg for an item photo),
 * so a WebP derivative of a JPEG was labelled image/jpeg.
 */
final class WebpTypeSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Fixes the Content-Type of private .webp image style files.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof BinaryFileResponse) {
      return;
    }
    $path = $event->getRequest()->getPathInfo();
    if (str_starts_with($path, '/system/files/styles/') && str_ends_with($path, '.webp')) {
      $response->headers->set('Content-Type', 'image/webp');
    }
  }

}
