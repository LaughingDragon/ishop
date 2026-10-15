<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\ishop_items\DepopBulk;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\PostPack;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Depop bulk upload: everything still to post on Depop, as one CSV.
 */
final class DepopBulkController extends ControllerBase {

  public function __construct(
    private readonly ListingManager $listings,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Rows for the CSV and the items left out, in queue order.
   *
   * @return array{rows: array, included: array, skipped: array}
   *   CSV rows; [item, folder] per row; [item, reason] per skipped item.
   */
  private function build(): array {
    $rows = $included = $skipped = [];
    foreach ($this->listings->toPostQueue('depop') as $nid => $item) {
      $folder = DepopBulk::sku($nid);
      $result = DepopBulk::row(PostController::listingValues($item), $folder);
      if ($result['row'] === NULL) {
        $skipped[] = ['item' => $item, 'reason' => $result['reason']];
        continue;
      }
      $rows[] = $result['row'];
      $included[] = ['item' => $item, 'folder' => $folder];
    }
    return ['rows' => $rows, 'included' => $included, 'skipped' => $skipped];
  }

  /**
   * The page: what's in the CSV, what's left out and why, how to upload.
   */
  public function page(): array {
    $bulk = $this->build();
    $post = fn ($item) => Url::fromRoute('ishop_items.post', ['node' => $item->id(), 'platform' => 'depop'])->toString();
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="ishop-bulk">
<p>{{ intro }}</p>
{% if included %}<p class="ishop-bulk__actions"><a class="rm-button rm-button--gold" href="{{ csv_url }}">{{ csv_label }}</a> <a class="rm-button rm-button--ghost" href="{{ zip_url }}">{{ zip_label }}</a></p>
<h2>{{ how_title }}</h2><ol>{% for step in how %}<li>{{ step }}</li>{% endfor %}</ol>
<h2>{{ in_title }}</h2><div class="rm-table-wrap"><table><thead><tr><th>{{ folder_label }}</th><th>{{ item_label }}</th></tr></thead><tbody>{% for r in included %}<tr><td>{{ r.folder }}</td><td><a href="{{ r.url }}">{{ r.title }}</a></td></tr>{% endfor %}</tbody></table></div>{% else %}<p><strong>{{ nothing }}</strong></p>{% endif %}
{% if skipped %}<h2>{{ out_title }}</h2><ul>{% for s in skipped %}<li><a href="{{ s.url }}">{{ s.title }}</a>: {{ s.reason }}</li>{% endfor %}</ul>{% endif %}
<p class="ishop-bulk__note">{{ note }}</p></div>',
      '#context' => [
        'intro' => $this->formatPlural(count($bulk['included']), '1 item is ready for Depop\'s bulk upload.', '@count items are ready for Depop\'s bulk upload.'),
        'csv_url' => Url::fromRoute('ishop_items.depop_bulk_csv')->toString(),
        'csv_label' => $this->t('Download the CSV'),
        'zip_url' => Url::fromRoute('ishop_items.depop_bulk_photos')->toString(),
        'zip_label' => $this->t('Download the photos (ZIP)'),
        'how_title' => $this->t('How to upload'),
        'how' => [
          $this->t('On a computer, open Depop, go to Selling Hub, then Drafts.'),
          $this->t('Use its bulk upload (it offers a Google Sheet template) and upload the CSV from here. Each row becomes a draft.'),
          $this->t('Unzip the photos. Each folder is named like a draft\'s SKU (item-...): add that folder\'s photos to that draft, in order. (Depop\'s picture columns are left empty: our photos are private, so Depop can\'t fetch them.)'),
          $this->t('Publish each draft, then paste its link into the item\'s Listings and set Depop to Posted. It then leaves this list.'),
        ],
        'in_title' => $this->t('In the CSV'),
        'folder_label' => $this->t('SKU and photo folder'),
        'item_label' => $this->t('Item'),
        'included' => array_map(fn ($r) => [
          'folder' => $r['folder'],
          'title' => $r['item']->label(),
          'url' => $post($r['item']),
        ], $bulk['included']),
        'nothing' => $this->t('Nothing is waiting to go on Depop.'),
        'out_title' => $this->t('Left out (fix these on the item first)'),
        'skipped' => array_map(fn ($s) => [
          'title' => $s['item']->label(),
          'reason' => $s['reason'],
          'url' => $post($s['item']),
        ], $bulk['skipped']),
        'note' => $this->t('Only items still "To post" on Depop that Depop allows are included. The columns match Depop\'s template as of October 2026; if Depop rejects the file, its template may have changed: tell the site office.'),
      ],
      '#attached' => ['library' => ['ishop_items/post']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The CSV download.
   */
  public function csv(): Response {
    $response = new Response(DepopBulk::csv($this->build()['rows']), 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Cache-Control' => 'private, no-store',
    ]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'depop-bulk-' . date('Y-m-d') . '.csv'));
    return $response;
  }

  /**
   * The photos, one folder per CSV row, resized for Depop (first 4).
   */
  public function photos(): BinaryFileResponse {
    $included = $this->build()['included'];
    if (!$included) {
      throw new NotFoundHttpException();
    }
    $style = ImageStyle::load('post_depop');
    $max = PostPack::PHOTO_SPECS['depop']['max_photos'];
    $path = $this->fileSystem->realpath($this->fileSystem->tempnam('temporary://', 'ishop-depop-'));
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException('Could not create the photo ZIP.');
    }
    foreach ($included as $entry) {
      $zip->addEmptyDir($entry['folder']);
      foreach (array_slice(PostController::photoUris($entry['item']), 0, $max) as $i => $uri) {
        $derivative = $style->buildUri($uri);
        if (!file_exists($derivative) && !$style->createDerivative($uri, $derivative)) {
          continue;
        }
        $name = sprintf('%s/%02d.jpg', $entry['folder'], $i + 1);
        $zip->addFile($this->fileSystem->realpath($derivative), $name);
        $zip->setCompressionName($name, \ZipArchive::CM_STORE);
      }
    }
    $zip->close();
    $response = new BinaryFileResponse($path, 200, [
      'Content-Type' => 'application/zip',
      'Cache-Control' => 'private, no-store',
    ]);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'depop-bulk-photos-' . date('Y-m-d') . '.zip');
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

}
