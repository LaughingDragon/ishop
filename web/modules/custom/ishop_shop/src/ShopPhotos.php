<?php

declare(strict_types=1);

namespace Drupal\ishop_shop;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Public copies of shop items' photos (item photos are private files).
 *
 * A copy exists only while its item is in the shop AND the switch is on;
 * sync() makes missing copies and deletes every other file in DIR. Copies
 * are made from the prepared photo (orientation fixed, metadata already
 * stripped on upload) and stripped again, sized for the web. Names are a
 * keyed hash, so they give away nothing (no file IDs to count through).
 */
final class ShopPhotos {

  public const DIR = 'public://shop-photos';

  /**
   * Long edge of a public copy, in pixels.
   */
  public const EDGE = 1600;

  public const QUALITY = 82;

  private const MAGICK = '/usr/bin/magick';

  /**
   * Whether something changed in this request that may change the copies.
   */
  private bool $dirty = FALSE;

  public function __construct(
    private readonly Shop $shop,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    #[Autowire(service: 'logger.channel.ishop_shop')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The copy's file name for a photo.
   */
  public static function nameFor(int $fid, string $uri, int $changed, string $key): string {
    return substr(hash_hmac('sha256', $fid . '|' . $uri . '|' . $changed, $key), 0, 32) . '.jpg';
  }

  /**
   * The copy's URI for a photo.
   */
  public function uri(FileInterface $file): string {
    return self::DIR . '/' . self::nameFor(
      (int) $file->id(),
      $file->getFileUri(),
      (int) $file->getChangedTime(),
      Settings::getHashSalt() . 'ishop_shop_photos',
    );
  }

  /**
   * Where a page shows the photo.
   *
   * The public copy while the shop is on; the private photo (which only
   * signed-in Cody and Liam can open) in a preview. NULL if the copy is
   * missing (the next sync makes it).
   */
  public function url(FileInterface $file): ?string {
    if (!$this->shop->isOn()) {
      return $this->fileUrlGenerator->generateString($file->getFileUri());
    }
    $uri = $this->uri($file);
    return is_file($this->fileSystem->realpath($uri) ?: '') ? $this->fileUrlGenerator->generateString($uri) : NULL;
  }

  /**
   * Notes that the copies need checking at the end of this request.
   */
  public function markDirty(): void {
    $this->dirty = TRUE;
  }

  /**
   * Syncs if anything was marked dirty in this request.
   */
  public function syncIfDirty(): void {
    if ($this->dirty) {
      $this->dirty = FALSE;
      $this->sync();
    }
  }

  /**
   * Makes the missing copies and deletes every copy that shouldn't exist.
   *
   * @return array{made: int, removed: int, failed: int, kept: int}
   */
  public function sync(): array {
    $wanted = [];
    if ($this->shop->isOn()) {
      foreach ($this->shop->items() as $item) {
        foreach ($this->shop->photos($item) as $file) {
          $wanted[basename($this->uri($file))] = $file;
        }
      }
    }
    $counts = ['made' => 0, 'removed' => 0, 'failed' => 0, 'kept' => 0];
    $dir = self::DIR;
    if ($wanted) {
      $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    $path = $this->fileSystem->realpath($dir);
    $present = $path && is_dir($path) ? array_values(array_diff(scandir($path) ?: [], ['.', '..'])) : [];

    foreach ($present as $name) {
      if (!isset($wanted[$name])) {
        @unlink($path . '/' . $name);
        $counts['removed']++;
      }
    }
    foreach ($wanted as $name => $file) {
      if (in_array($name, $present, TRUE)) {
        $counts['kept']++;
      }
      elseif ($this->copy($file, $path . '/' . $name)) {
        $counts['made']++;
      }
      else {
        $counts['failed']++;
      }
    }
    if ($counts['made'] || $counts['removed'] || $counts['failed']) {
      $this->logger->info('Shop photos: @made made, @removed removed, @failed failed, @kept kept.', [
        '@made' => $counts['made'],
        '@removed' => $counts['removed'],
        '@failed' => $counts['failed'],
        '@kept' => $counts['kept'],
      ]);
    }
    return $counts;
  }

  /**
   * Writes one web-sized, metadata-free copy.
   */
  private function copy(FileInterface $file, string $target): bool {
    $source = $this->fileSystem->realpath($file->getFileUri());
    if (!$source || !is_file($source)) {
      $this->logger->warning('Shop photo source @uri not found.', ['@uri' => $file->getFileUri()]);
      return FALSE;
    }
    $tmp = $target . '.part.jpg';
    $process = new Process([
      self::MAGICK, $source . '[0]',
      '-auto-orient',
      '-strip',
      '-resize', self::EDGE . 'x' . self::EDGE . '>',
      '-sampling-factor', '4:2:0',
      '-interlace', 'JPEG',
      '-quality', (string) self::QUALITY,
      'jpeg:' . $tmp,
    ]);
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful() || !is_file($tmp)) {
      @unlink($tmp);
      $this->logger->error('Could not copy shop photo @uri: @error', [
        '@uri' => $file->getFileUri(),
        '@error' => trim($process->getErrorOutput()),
      ]);
      return FALSE;
    }
    rename($tmp, $target);
    $this->fileSystem->chmod($target);
    return TRUE;
  }

}
