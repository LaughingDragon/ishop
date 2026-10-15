<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Prepares item photos once, on upload.
 *
 * Every new image under private://items/ becomes a JPEG, turned the right way
 * up (EXIF orientation applied), stripped of metadata (GPS location, camera)
 * and at most MAX_EDGE pixels on its long edge. HEIC/HEIF from iPhones is
 * detected by content, not by name, and renamed to .jpg.
 */
final class PhotoPrep {

  /**
   * Where item photos live.
   */
  public const SCHEME_PREFIX = 'private://items/';

  /**
   * Longest edge kept, in pixels. Never enlarged.
   */
  public const MAX_EDGE = 2560;

  /**
   * JPEG quality.
   */
  public const QUALITY = 88;

  /**
   * ImageMagick 7 binary.
   */
  private const MAGICK = '/usr/bin/magick';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    #[Autowire(service: 'logger.channel.ishop_items')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether a file is an item photo that still needs preparing.
   */
  public static function applies(FileInterface $file): bool {
    return $file->isNew() && str_starts_with($file->getFileUri(), self::SCHEME_PREFIX);
  }

  /**
   * The filename after preparing: the same base name, ending in .jpg.
   */
  public static function jpegName(string $name): string {
    $base = preg_replace('/\.[^.\/]*$/', '', $name);
    return ($base === '' ? 'photo' : $base) . '.jpg';
  }

  /**
   * Whether the first bytes are an HEIC/HEIF container (ISO BMFF "ftyp").
   */
  public static function isHeif(string $head): bool {
    if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
      return FALSE;
    }
    $brands = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];
    return in_array(substr($head, 8, 4), $brands, TRUE);
  }

  /**
   * Prepares the file in place (before it is saved).
   *
   * @throws \RuntimeException
   *   When ImageMagick fails; the upload is then refused.
   */
  public function prepare(FileInterface $file): void {
    $uri = $file->getFileUri();
    $source = $this->fileSystem->realpath($uri);
    if (!$source || !is_file($source)) {
      throw new \RuntimeException(sprintf('Photo file %s not found.', $uri));
    }
    $head = (string) file_get_contents($source, FALSE, NULL, 0, 16);
    $heif = self::isHeif($head);

    $target_uri = $uri;
    if (!str_ends_with(strtolower($uri), '.jpg')) {
      $target_uri = $this->fileSystem->getDestinationFilename(self::jpegName($uri), FileExists::Rename);
    }
    $target = $this->fileSystem->realpath(dirname($target_uri)) . '/' . basename($target_uri);
    $tmp = $target . '.part.jpg';

    // The "[0]" reads only the first frame/image of the file.
    $process = new Process([
      self::MAGICK, $source . '[0]',
      '-auto-orient',
      '-strip',
      '-resize', self::MAX_EDGE . 'x' . self::MAX_EDGE . '>',
      '-sampling-factor', '4:2:0',
      '-interlace', 'JPEG',
      '-quality', (string) self::QUALITY,
      'jpeg:' . $tmp,
    ]);
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful() || !is_file($tmp)) {
      @unlink($tmp);
      $this->logger->error('Could not prepare photo @uri: @error', [
        '@uri' => $uri,
        '@error' => trim($process->getErrorOutput()),
      ]);
      throw new \RuntimeException('This photo could not be read. Try again, or take it as JPEG.');
    }
    rename($tmp, $target);
    // ImageMagick wrote the file, so apply Drupal's file permissions (0664 by
    // default) the way Drupal's own file operations do.
    $this->fileSystem->chmod($target);
    if ($target !== $source) {
      @unlink($source);
    }

    $file->setFileUri($target_uri);
    $file->setFilename(self::jpegName($file->getFilename()));
    $file->setMimeType('image/jpeg');
    clearstatcache(TRUE, $target);
    $file->setSize((int) filesize($target));
    $this->logger->info('Prepared photo @uri (@kind).', [
      '@uri' => $target_uri,
      '@kind' => $heif ? 'HEIC converted' : 'JPEG/PNG/WebP',
    ]);
  }

}
