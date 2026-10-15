<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ishop_matrix\Birthday;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the birthday song (TEMPORARY: see TEMPORARY.md).
 *
 * Only while birthday mode is on for the visitor. Byte ranges are supported
 * (BinaryFileResponse), which iPhone Safari needs to play and loop audio.
 */
final class BirthdayController extends ControllerBase {

  public function __construct(
    private readonly Birthday $birthday,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Access: signed in, and birthday mode is on for this account.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($this->birthday->isActive($account))->setCacheMaxAge(0);
  }

  /**
   * The song.
   */
  public function song(): BinaryFileResponse {
    $path = $this->fileSystem->realpath(Birthday::SONG);
    if (!$path || !is_file($path)) {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($path, 200, [
      'Content-Type' => 'audio/mpeg',
      'Cache-Control' => 'private, max-age=3600',
    ]);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'birthday.mp3');
    return $response;
  }

}
