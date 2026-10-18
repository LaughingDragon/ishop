<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingImport\ListingImporter;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Notes from the old spreadsheet, waiting for Cody (Phase 10).
 *
 * The son's notes are mostly things to check before selling ("measure it
 * before posting"). Each stays here, and in "Needs you now", until Done.
 */
final class SheetNotesController extends ControllerBase {

  public function __construct(
    private readonly ListingImporter $importer,
  ) {}

  /**
   * The list: each item with its note, Open and Done.
   */
  public function page(): array {
    $rows = [];
    foreach ($this->importer->sheetNotes() as $nid => $note) {
      $rows[] = [
        'title' => $note['item']->label(),
        'note' => $note['note'],
        'open' => $note['item']->toUrl()->toString(),
        'done' => Url::fromRoute('ishop_items.sheet_note_done', ['node' => $nid])->toString(),
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<p class="rm-ship__intro">{{ intro }}</p>{% for r in rows %}<section class="rm-card rm-ship__sale"><h3>{{ r.title }}</h3><p class="rm-sheet-note">{{ r.note|nl2br }}</p><p class="rm-ship__buttons"><a class="rm-button rm-button--ghost" href="{{ r.open }}">{{ open }}</a> <a class="rm-button rm-button--gold" href="{{ r.done }}">{{ done }}</a></p></section>{% else %}<p class="rm-ship__empty">{{ empty }}</p>{% endfor %}',
      '#context' => [
        'intro' => $this->t('These notes came with your spreadsheet. Most are things to check before you sell the item. Tap Done when you have dealt with one; the note stays on the item (Edit, Notes).'),
        'rows' => $rows,
        'open' => $this->t('Open the item'),
        'done' => $this->t('Done'),
        'empty' => $this->t('No notes left to check.'),
      ],
      '#attached' => ['library' => ['ishop_items/shipping']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Done with one item's note.
   */
  public function done(NodeInterface $node): RedirectResponse {
    $this->importer->dismissNote((int) $node->id());
    $this->messenger()->addStatus($this->t('Done: @item.', ['@item' => $node->label()]));
    return $this->redirect('ishop_items.sheet_notes');
  }

}
