<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ishop_items\ListingImport\Columns;
use Drupal\ishop_items\ListingImport\ImportPlan;
use Drupal\ishop_items\ListingImport\ListingImporter;
use Drupal\ishop_items\ListingImport\SheetReader;
use Drupal\ishop_items\ListingImport\Values;
use Drupal\ishop_items\Platforms;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import listings (Phase 10).
 *
 * Upload, match columns, match categories, preview, Import. Nothing is saved
 * before Import.
 *
 * The uploaded sheet waits in private storage (ListingImporter::UPLOADS)
 * and is read again at each step, so the form keeps only the matching, not
 * the sheet's contents. It is deleted after the import or "Start over"
 * (cron deletes any left after a day).
 */
final class ListingImportForm extends FormBase {

  /**
   * Largest sheet accepted.
   */
  public const MAX_BYTES = 10 * 1024 * 1024;

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected ListingImporter $importer,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_listing_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'rm-import';
    $form['#attached']['library'][] = 'ishop_items/import';
    $step = $form_state->get('step') ?? 'upload';
    if ($step !== 'upload') {
      try {
        $sheets = $this->sheets($form_state);
      }
      catch (\RuntimeException $e) {
        $this->messenger()->addError($e->getMessage());
        $step = 'upload';
        $form_state->set('step', 'upload');
      }
    }
    $form['steps'] = $this->stepsLine($step);
    return match ($step) {
      'upload' => $this->uploadStep($form),
      'match' => $this->matchStep($form, $form_state, $sheets),
      'values' => $this->valuesStep($form, $form_state, $sheets),
      'preview' => $this->previewStep($form, $form_state, $sheets),
    };
  }

  /**
   * The step line: "1 Upload · 2 Columns · 3 Categories · 4 Check and import".
   */
  private function stepsLine(string $step): array {
    $names = [
      'upload' => $this->t('Upload'),
      'match' => $this->t('Columns'),
      'values' => $this->t('Categories'),
      'preview' => $this->t('Check and import'),
    ];
    $items = [];
    $n = 1;
    foreach ($names as $key => $name) {
      $items[] = ['n' => $n++, 'name' => $name, 'current' => $key === $step];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<ol class="rm-import__steps">{% for s in items %}<li{% if s.current %} aria-current="step" class="is-current"{% endif %}><span>{{ s.n }}</span> {{ s.name }}</li>{% endfor %}</ol>',
      '#context' => ['items' => $items],
      '#weight' => -100,
    ];
  }

  /**
   * Step 1: choose the file.
   */
  private function uploadStep(array $form): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Bring in everything you have listed now from your spreadsheet. Nothing on Poshmark, Mercari or anywhere else is touched: the site only reads your file. You will see every item before anything is saved, and an import can be undone for @days days.', ['@days' => ListingImporter::UNDO_DAYS]) . '</p>',
    ];
    $form['sheet'] = [
      '#type' => 'file',
      '#title' => $this->t('Your spreadsheet'),
      '#description' => $this->t('Excel (.xlsx) or .csv, as it is. Up to 10 MB.'),
      '#attributes' => ['accept' => '.xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read my spreadsheet'),
      '#button_type' => 'primary',
      '#submit' => ['::uploadSubmit'],
      '#validate' => ['::uploadValidate'],
    ];
    $form['history'] = $this->history();
    return $form;
  }

  /**
   * Checks and keeps the upload.
   */
  public function uploadValidate(array &$form, FormStateInterface $form_state): void {
    $upload = $this->getRequest()->files->get('files')['sheet'] ?? NULL;
    if (!$upload instanceof UploadedFile || !$upload->isValid()) {
      $form_state->setErrorByName('sheet', $this->t('Choose your spreadsheet first.'));
      return;
    }
    $extension = strtolower(pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION));
    if (!in_array($extension, SheetReader::EXTENSIONS, TRUE)) {
      $form_state->setErrorByName('sheet', $this->t('That is a .@ext file. Choose the Excel (.xlsx) or .csv file.', ['@ext' => $extension ?: '?']));
      return;
    }
    if ($upload->getSize() > self::MAX_BYTES) {
      $form_state->setErrorByName('sheet', $this->t('The file is bigger than 10 MB. Is it the right one?'));
      return;
    }
    try {
      SheetReader::read($upload->getRealPath(), $extension);
    }
    catch (\RuntimeException $e) {
      $form_state->setErrorByName('sheet', $e->getMessage());
      return;
    }
    $form_state->set('upload', [
      'uri' => $this->importer->keepUpload($upload->getRealPath(), $extension),
      'name' => $upload->getClientOriginalName(),
      'extension' => $extension,
    ]);
  }

  /**
   * On to the column matching.
   */
  public function uploadSubmit(array &$form, FormStateInterface $form_state): void {
    $sheets = $this->sheets($form_state);
    $best = $this->importer->bestTab($sheets);
    $this->useSheet($form_state, $best, $sheets);
    $form_state->set('step', 'match');
    $form_state->setRebuild();
  }

  /**
   * Chooses a tab and the site's guess for its columns.
   */
  private function useSheet(FormStateInterface $form_state, int $index, array $sheets): void {
    $sheet = $sheets[$index];
    $form_state->set('sheet_index', $index);
    $form_state->set('mapping', $this->importer->guessColumns($sheet));
    $form_state->set('value_maps', NULL);
  }

  /**
   * Step 2: what each column is.
   */
  private function matchStep(array $form, FormStateInterface $form_state, array $sheets): array {
    $index = (int) $form_state->get('sheet_index');
    $sheet = $sheets[$index];
    $mapping = $form_state->get('mapping');
    $upload = $form_state->get('upload');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('<strong>@file</strong>: @rows rows. The site guessed what each column is. Change any that are wrong, or set a column to "Ignore this column". Columns with buyer details are never read.', [
        '@file' => $upload['name'],
        '@rows' => count($sheet['rows']),
      ]) . '</p>',
    ];
    if (count($sheets) > 1) {
      $form['tab'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['rm-import__tab']],
      ];
      $form['tab']['sheet_index'] = [
        '#type' => 'select',
        '#title' => $this->t('Tab to read'),
        '#options' => array_map(fn ($s) => $s['name'] . ' (' . count($s['rows']) . ' rows)', $sheets),
        '#default_value' => $index,
        '#description' => $this->t('Your file has @n tabs. Only one is read.', ['@n' => count($sheets)]),
      ];
      $form['tab']['change'] = [
        '#type' => 'submit',
        '#value' => $this->t('Use this tab'),
        '#submit' => ['::changeTab'],
        '#limit_validation_errors' => [['sheet_index']],
        '#attributes' => ['class' => ['rm-button--ghost']],
      ];
    }
    $form['columns'] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['class' => ['rm-import__columns']]];
    $samples = array_slice(array_filter($sheet['rows'], fn ($r) => array_filter($r['cells'], fn ($c) => Values::text($c) !== '')), 0, 3);
    foreach ($sheet['headers'] as $i => $header) {
      $examples = array_values(array_filter(array_map(fn ($r) => mb_strimwidth(Values::text($r['cells'][$i] ?? NULL), 0, 40, '…'), $samples), fn ($v) => $v !== ''));
      $private = Columns::isPrivate($header);
      $form['columns'][$i] = [
        '#type' => 'select',
        '#title' => $this->t('Column @letter: @header', ['@letter' => SheetReader::letter($i), '@header' => $header]),
        '#options' => Columns::options(),
        '#default_value' => $private ? Columns::IGNORE : ($mapping[$i] ?? Columns::IGNORE),
        '#disabled' => $private,
        '#description' => $private
          ? $this->t('Buyer details: never read or kept.')
          : ($examples ? $this->t('For example: @list', ['@list' => implode(' · ', $examples)]) : $this->t('Empty in the first rows.')),
        '#wrapper_attributes' => ['class' => [$mapping[$i] ?? '' ? 'is-matched' : 'is-ignored']],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
      '#validate' => ['::matchValidate'],
      '#submit' => ['::matchSubmit'],
    ];
    $form['actions']['over'] = $this->startOverButton();
    return $form;
  }

  /**
   * Switches to another tab.
   */
  public function changeTab(array &$form, FormStateInterface $form_state): void {
    $sheets = $this->sheets($form_state);
    $index = (int) $form_state->getValue('sheet_index');
    if (isset($sheets[$index])) {
      $this->useSheet($form_state, $index, $sheets);
    }
    $form_state->setRebuild();
  }

  /**
   * A title column is needed; each other kind of column only once.
   */
  public function matchValidate(array &$form, FormStateInterface $form_state): void {
    $sheet = $this->sheets($form_state)[(int) $form_state->get('sheet_index')];
    $mapping = $this->submittedMapping($form_state, $sheet['headers']);
    $used = [];
    foreach ($mapping as $i => $target) {
      if (!Columns::isTarget($target)) {
        $form_state->setErrorByName('columns][' . $i, $this->t('Choose what this column is.'));
      }
      elseif ($target !== Columns::IGNORE && isset($used[$target])) {
        $form_state->setErrorByName('columns][' . $i, $this->t('Two columns are set to "@target" (columns @a and @b). Set one of them to Ignore.', [
          '@target' => Columns::label($target),
          '@a' => SheetReader::letter($used[$target]),
          '@b' => SheetReader::letter($i),
        ]));
      }
      $used[$target] ??= $i;
    }
    if (!isset($used['title'])) {
      $form_state->setErrorByName('columns', $this->t('Which column has the titles? Set one column to "Title".'));
    }
  }

  /**
   * On to the category matching, or straight to the preview.
   */
  public function matchSubmit(array &$form, FormStateInterface $form_state): void {
    $sheet = $this->sheets($form_state)[(int) $form_state->get('sheet_index')];
    $form_state->set('mapping', $this->submittedMapping($form_state, $sheet['headers']));
    $form_state->set('value_maps', $this->importer->proposeValueMaps($sheet, $form_state->get('mapping')));
    $form_state->set('step', $form_state->get('value_maps') ? 'values' : 'preview');
    $form_state->setRebuild();
  }

  /**
   * The matching as submitted (buyer-detail columns always ignored).
   */
  private function submittedMapping(FormStateInterface $form_state, array $headers): array {
    $mapping = [];
    foreach ($headers as $i => $header) {
      $mapping[$i] = Columns::isPrivate($header) ? Columns::IGNORE : (string) ($form_state->getValue(['columns', $i]) ?? Columns::IGNORE);
    }
    return $mapping;
  }

  /**
   * Step 3: the sheet's categories and conditions against the site's.
   */
  private function valuesStep(array $form, FormStateInterface $form_state, array $sheets): array {
    $sheet = $sheets[(int) $form_state->get('sheet_index')];
    $mapping = $form_state->get('mapping');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Your sheet uses its own names for some things. Check what each one becomes on the site. The site remembers your choices for next time.') . '</p>',
    ];
    $form['values'] = ['#type' => 'container', '#tree' => TRUE];
    foreach ($form_state->get('value_maps') as $field => $map) {
      $column = array_search($field, $mapping, TRUE);
      $counts = array_count_values(array_filter(array_map(fn ($r) => mb_strtolower(Values::text($r['cells'][$column] ?? NULL)), $sheet['rows'])));
      $originals = [];
      foreach ($sheet['rows'] as $r) {
        $text = Values::text($r['cells'][$column] ?? NULL);
        $originals[mb_strtolower($text)] ??= $text;
      }
      $form['values'][$field] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $field === 'category' ? $this->t('Categories') : $this->t('Conditions'),
      ];
      foreach ($map as $key => $tid) {
        $form['values'][$field][md5($key)] = [
          '#type' => 'select',
          '#title' => $this->t('"@value" (@n items)', ['@value' => $originals[$key] ?? $key, '@n' => $counts[$key] ?? 0]),
          '#options' => [0 => $this->t('Leave blank')] + $this->importer->terms($field),
          '#default_value' => $tid,
        ];
      }
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next: check everything'),
      '#button_type' => 'primary',
      '#submit' => ['::valuesSubmit'],
    ];
    $form['actions']['back'] = $this->backButton('match');
    $form['actions']['over'] = $this->startOverButton();
    return $form;
  }

  /**
   * Keeps the category and condition choices.
   */
  public function valuesSubmit(array &$form, FormStateInterface $form_state): void {
    $maps = $form_state->get('value_maps');
    foreach ($maps as $field => $map) {
      foreach (array_keys($map) as $key) {
        $maps[$field][$key] = (int) $form_state->getValue(['values', $field, md5($key)]);
      }
    }
    $form_state->set('value_maps', $maps);
    $form_state->set('step', 'preview');
    $form_state->setRebuild();
  }

  /**
   * The plan for the current choices.
   */
  public function plan(FormStateInterface $form_state, array $sheets): array {
    $sheet = $sheets[(int) $form_state->get('sheet_index')];
    $context = $this->importer->context($form_state->get('value_maps') ?? [], (bool) ($form_state->get('fill') ?? TRUE));
    return ImportPlan::build($sheet['rows'], $form_state->get('mapping'), $context);
  }

  /**
   * Step 4: every item before anything is saved.
   */
  private function previewStep(array $form, FormStateInterface $form_state, array $sheets): array {
    $plan = $this->plan($form_state, $sheets);
    $counts = ImportPlan::counts($plan);
    $sold_choice = $form_state->get('sold') ?? NULL;
    $form['summary'] = $this->summary($counts, $sheets[(int) $form_state->get('sheet_index')]);

    $form['options'] = ['#type' => 'container', '#attributes' => ['class' => ['rm-card', 'rm-import__options']]];
    if ($counts['kinds'][ImportPlan::KIND_SOLD]) {
      $form['options']['sold'] = [
        '#type' => 'radios',
        '#title' => $this->t('The @n sold items ($@total)', [
          '@n' => $counts['kinds'][ImportPlan::KIND_SOLD],
          '@total' => number_format($counts['sold_total'], 2),
        ]),
        '#options' => [
          ListingImporter::SOLD_DELIVERED => $this->t('Bring them in as sold, already shipped and delivered (they count on the Money page)'),
          ListingImporter::SOLD_TO_SHIP => $this->t('Bring them in as sold, still to ship (they go to the Shipping room)'),
          ListingImporter::SOLD_SKIP => $this->t('Leave them out'),
        ],
        '#default_value' => $sold_choice,
        '#description' => $this->t('Choose one. The sheet has no shipping dates, so "already delivered" uses the date sold.'),
      ];
    }
    $records = $counts['records'];
    if ($records) {
      $form['options']['fill'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Fill in descriptions and details from the old Crosslister records (@n items match)', ['@n' => $records]),
        '#description' => $this->t('Description, flaws, size, condition, color, brand and price range, only where your sheet leaves them empty, plus the @photos photos that were kept. Never changes what your sheet says about where it is posted or sold.', ['@photos' => $this->recordPhotoCount($plan)]),
        '#default_value' => $form_state->get('fill') ?? TRUE,
      ];
    }
    if (isset($form['options']['sold']) || isset($form['options']['fill'])) {
      $form['options']['update'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update the list'),
        '#submit' => ['::updatePreview'],
        '#limit_validation_errors' => [['sold'], ['fill']],
        '#attributes' => ['class' => ['rm-button--ghost']],
      ];
    }

    $form['items'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['rm-import__items']],
    ];
    $skip_sold = $sold_choice === ListingImporter::SOLD_SKIP;
    foreach ($plan as $n => $item) {
      $ticked = $item['tick'] && !($skip_sold && $item['kind'] === ImportPlan::KIND_SOLD);
      $form['items'][$n] = [
        '#type' => 'checkbox',
        '#title' => $item['title'] !== '' ? $item['title'] : $this->t('(no title)'),
        '#default_value' => $ticked,
        '#disabled' => !$item['importable'] || ($skip_sold && $item['kind'] === ImportPlan::KIND_SOLD),
        '#description' => $this->describe($item),
        '#wrapper_attributes' => [
          'class' => array_filter([
            'rm-import__item',
            'is-' . $item['kind'],
            $item['problems'] ? 'has-problems' : NULL,
          ]),
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import the ticked items'),
      '#button_type' => 'primary',
      '#validate' => ['::importValidate'],
      '#submit' => ['::importSubmit'],
    ];
    $form['actions']['back'] = $this->backButton($form_state->get('value_maps') ? 'values' : 'match');
    $form['actions']['over'] = $this->startOverButton();
    return $form;
  }

  /**
   * The summary: what the import would create, per platform.
   */
  private function summary(array $counts, array $sheet): array {
    $statuses = [
      Platforms::POSTED,
      Platforms::SOLD_HERE,
      Platforms::TAKE_DOWN,
      Platforms::TO_POST,
      Platforms::NOT_NEEDED_SOLD,
      Platforms::NOT_ALLOWED,
    ];
    $rows = [];
    foreach (Platforms::PLATFORMS as $platform => $name) {
      $c = $counts['platforms'][$platform];
      $other = array_sum($c) - $c['blank'] - array_sum(array_intersect_key($c, array_flip($statuses)));
      $rows[] = [
        'name' => $name,
        'cells' => array_merge(array_map(fn ($s) => $c[$s], $statuses), [$other, $c['blank']]),
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="rm-card rm-import__summary"><h2>{{ heading }}</h2><p>{{ line }}</p><p>{{ problems }}</p><p class="rm-import__check">{{ check }}</p><div class="rm-import__scroll"><table><thead><tr><th scope="col">{{ platform }}</th>{% for h in heads %}<th scope="col">{{ h }}</th>{% endfor %}</tr></thead><tbody>{% for r in rows %}<tr><th scope="row">{{ r.name }}</th>{% for c in r.cells %}<td>{{ c }}</td>{% endfor %}</tr>{% endfor %}</tbody></table></div></section>',
      '#context' => [
        'heading' => $this->t('What this would bring in'),
        'line' => $this->t('@rows rows from the "@tab" tab become @items items: @listed listed, @sold sold ($@total), @ready not posted yet, @ended ended.', [
          '@rows' => $counts['rows'],
          '@tab' => $sheet['name'],
          '@items' => $counts['items'],
          '@listed' => $counts['kinds'][ImportPlan::KIND_LISTED],
          '@sold' => $counts['kinds'][ImportPlan::KIND_SOLD],
          '@total' => number_format($counts['sold_total'], 2),
          '@ready' => $counts['kinds'][ImportPlan::KIND_READY],
          '@ended' => $counts['kinds'][ImportPlan::KIND_ENDED],
        ]),
        'problems' => $counts['problems']
          ? $this->formatPlural($counts['problems'], '1 item has something to check (marked below).', '@count items have something to check (marked below).')
          : $this->t('No problems found.'),
        'check' => $this->t('Before you import: do these numbers match what you see live on each site? For example, are about @n listings really up on Poshmark?', ['@n' => $counts['platforms']['poshmark'][Platforms::POSTED]]),
        'platform' => $this->t('Platform'),
        'heads' => [
          $this->t('Posted'),
          $this->t('Sold here'),
          $this->t('Take down'),
          $this->t('To post'),
          $this->t('Not needed - sold'),
          $this->t('Not allowed'),
          $this->t('Other'),
          $this->t('Not in sheet'),
        ],
        'rows' => $rows,
      ],
    ];
  }

  /**
   * One item's line in the preview: what it becomes and any problems.
   */
  private function describe(array $item): array {
    $platforms = [];
    foreach ($item['listings'] as $platform => $listing) {
      $platforms[] = Platforms::PLATFORMS[$platform] . ': ' . Platforms::STATUSES[$listing['status']]
        . ($listing['price'] !== NULL ? ' $' . self::money($listing['price']) : '')
        . ($listing['url'] ? ' (' . $this->t('link') . ')' : '');
    }
    $kind = match ($item['kind']) {
      ImportPlan::KIND_SOLD => $this->t('Sold on @p for @price@date', [
        '@p' => Platforms::PLATFORMS[$item['sold_on']] ?? '?',
        '@price' => $item['sold_price'] !== NULL ? '$' . self::money($item['sold_price']) : $this->t('(no price)'),
        '@date' => $item['date_sold'] ? ', ' . $item['date_sold'] : '',
      ]),
      ImportPlan::KIND_LISTED => $this->t('Listed'),
      ImportPlan::KIND_READY => $this->t('Ready (not posted yet)'),
      ImportPlan::KIND_ENDED => $this->t('Ended'),
    };
    $extra = [];
    if ($item['filled']) {
      $extra[] = $this->t('From Crosslister: @fields', [
        '@fields' => implode(', ', array_map(
          fn ($f) => str_replace(['ai_price_min', 'ai_price_max', '_'], ['price range', '', ' '], $f),
          array_diff($item['filled'], ['ai_price_max'])
        )),
      ]);
    }
    if ($item['photos']) {
      $extra[] = $this->formatPlural(count($item['photos']), '1 photo', '@count photos');
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="rm-import__rows">{{ rows }}</span> <strong>{{ kind }}</strong>{% if platforms %}<span class="rm-import__platforms">{{ platforms|join(" · ") }}</span>{% endif %}{% if extra %}<span class="rm-import__extra">{{ extra|join(" · ") }}</span>{% endif %}{% if problems %}<ul class="rm-import__problems">{% for p in problems %}<li class="is-{{ p.0 }}">{{ p.1 }}</li>{% endfor %}</ul>{% endif %}',
      '#context' => [
        'rows' => $this->formatPlural(count($item['rows']), 'Row @rows', 'Rows @rows', ['@rows' => implode(', ', $item['rows'])]),
        'kind' => $kind,
        'platforms' => $platforms,
        'extra' => $extra,
        'problems' => $item['problems'],
      ],
    ];
  }

  /**
   * Photos the Crosslister records would bring (whether or not ticked).
   */
  private function recordPhotoCount(array $plan): int {
    $records = $this->importer->records();
    $n = 0;
    foreach ($plan as $item) {
      if ($item['record'] !== NULL) {
        $n += count($records[$item['record']]['photos'] ?? []);
      }
    }
    return $n;
  }

  /**
   * Keeps the preview options and shows the list again.
   */
  public function updatePreview(array &$form, FormStateInterface $form_state): void {
    $form_state->set('sold', $form_state->getValue('sold'));
    if ($form_state->hasValue('fill')) {
      $form_state->set('fill', (bool) $form_state->getValue('fill'));
    }
    $form_state->setRebuild();
  }

  /**
   * Sold items need a choice; at least one item must be ticked.
   */
  public function importValidate(array &$form, FormStateInterface $form_state): void {
    $plan = $this->plan($form_state, $this->sheets($form_state));
    $sold = (string) $form_state->getValue('sold');
    $ticked = array_keys(array_filter((array) $form_state->getValue('items')));
    $ticked = array_filter($ticked, fn ($n) => isset($plan[$n]) && $plan[$n]['importable']);
    if (in_array(ImportPlan::KIND_SOLD, array_map(fn ($n) => $plan[$n]['kind'], $ticked), TRUE)
      && !in_array($sold, [ListingImporter::SOLD_DELIVERED, ListingImporter::SOLD_TO_SHIP, ListingImporter::SOLD_SKIP], TRUE)) {
      $form_state->setErrorByName('sold', $this->t('Choose what to do with the sold items.'));
    }
    if ($sold === ListingImporter::SOLD_SKIP) {
      $ticked = array_filter($ticked, fn ($n) => $plan[$n]['kind'] !== ImportPlan::KIND_SOLD);
    }
    if (!$ticked) {
      $form_state->setErrorByName('items', $this->t('No items are ticked.'));
    }
  }

  /**
   * Imports the ticked items (in batches, so a big sheet doesn't time out).
   */
  public function importSubmit(array &$form, FormStateInterface $form_state): void {
    $sheets = $this->sheets($form_state);
    $sheet = $sheets[(int) $form_state->get('sheet_index')];
    $form_state->set('fill', (bool) ($form_state->getValue('fill') ?? FALSE));
    $plan = $this->plan($form_state, $sheets);
    $sold = (string) ($form_state->getValue('sold') ?: ListingImporter::SOLD_SKIP);
    $ticked = [];
    foreach (array_keys(array_filter((array) $form_state->getValue('items'))) as $n) {
      if (isset($plan[$n]) && $plan[$n]['importable'] && !($sold === ListingImporter::SOLD_SKIP && $plan[$n]['kind'] === ImportPlan::KIND_SOLD)) {
        $ticked[] = $plan[$n];
      }
    }
    $upload = $form_state->get('upload');
    $this->importer->remember($sheet['headers'], $form_state->get('mapping'), $form_state->get('value_maps') ?? []);
    $id = $this->importer->start($upload['name'], $sheet['name'], [
      'sold' => $sold,
      'fill' => $form_state->get('fill'),
      'ticked' => count($ticked),
      'items' => count($plan),
    ]);
    $this->importer->deleteUpload($upload['uri']);

    $operations = [];
    foreach (array_chunk($ticked, 10) as $chunk) {
      $operations[] = [[self::class, 'batchImport'], [$id, $chunk, $sold]];
    }
    batch_set([
      'title' => $this->t('Bringing in your listings'),
      'init_message' => $this->t('Starting…'),
      'progress_message' => $this->t('@current of @total groups of 10 done.'),
      'error_message' => $this->t('The import stopped. Items already brought in are listed on the next page and can be undone.'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
    ]);
    $form_state->setRedirect('ishop_items.import_listings', [], ['query' => ['import' => $id]]);
  }

  /**
   * Batch step: imports up to ten items.
   */
  public static function batchImport(int $id, array $items, string $sold, array &$context): void {
    $importer = \Drupal::service(ListingImporter::class);
    $context['results']['id'] = $id;
    foreach ($items as $item) {
      try {
        $importer->importItem($id, $item, $sold);
        $context['results']['done'] = ($context['results']['done'] ?? 0) + 1;
      }
      catch (\Throwable $e) {
        \Drupal::logger('ishop_items')->error('Import @id: row @row failed: @message', [
          '@id' => $id,
          '@row' => $item['rows'][0],
          '@message' => $e->getMessage(),
        ]);
        $context['results']['failed'][] = $item['rows'][0];
      }
    }
  }

  /**
   * Batch end: the import is finished.
   */
  public static function batchFinished(bool $success, array $results): void {
    if (isset($results['id'])) {
      \Drupal::service(ListingImporter::class)->finish((int) $results['id']);
    }
    $messenger = \Drupal::messenger();
    $messenger->addStatus(\Drupal::translation()->formatPlural($results['done'] ?? 0, 'Brought in 1 item.', 'Brought in @count items.'));
    if (!empty($results['failed'])) {
      $messenger->addError(t('These rows could not be brought in: @rows. Tell site-foreman.', ['@rows' => implode(', ', $results['failed'])]));
    }
  }

  /**
   * The past imports, with Undo while it's allowed.
   */
  private function history(): array {
    $rows = [];
    foreach (array_slice($this->importer->all(), 0, 10) as $import) {
      $rows[] = [
        'when' => \Drupal::service('date.formatter')->format($import['created'], 'custom', 'M j, g:i a'),
        'what' => $this->formatPlural(count($import['nids']), '1 item from @file', '@count items from @file', ['@file' => $import['file']]),
        'state' => $import['undone'] ? $this->t('Undone') : '',
        'undo' => $this->importer->canUndo($import) ? Url::fromRoute('ishop_items.import_listings_undo', ['import' => $import['id']])->toString() : NULL,
      ];
    }
    if (!$rows) {
      return [];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="rm-card rm-import__history"><h2>{{ heading }}</h2><ul>{% for r in rows %}<li><span>{{ r.when }}: {{ r.what }}{% if r.state %} ({{ r.state }}){% endif %}</span>{% if r.undo %} <a class="rm-button rm-button--ghost" href="{{ r.undo }}">{{ undo }}</a>{% endif %}</li>{% endfor %}</ul><p>{{ note }}</p></section>',
      '#context' => [
        'heading' => $this->t('Earlier imports'),
        'rows' => $rows,
        'undo' => $this->t('Undo'),
        'note' => $this->t('Undo deletes the items an import brought in (only those), for @days days after it.', ['@days' => ListingImporter::UNDO_DAYS]),
      ],
      '#weight' => 50,
    ];
  }

  /**
   * The "Back" button, to an earlier step.
   */
  private function backButton(string $step): array {
    return [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#submit' => ['::goBack'],
      '#limit_validation_errors' => [],
      '#back_to' => $step,
      '#attributes' => ['class' => ['rm-button--ghost']],
    ];
  }

  /**
   * Goes back a step.
   */
  public function goBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', $form_state->getTriggeringElement()['#back_to']);
    $form_state->setRebuild();
  }

  /**
   * The "Start over" button: forgets the file.
   */
  private function startOverButton(): array {
    return [
      '#type' => 'submit',
      '#value' => $this->t('Start over'),
      '#name' => 'over',
      '#submit' => ['::startOver'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['rm-button--ghost']],
    ];
  }

  /**
   * Deletes the kept file and goes back to the upload.
   */
  public function startOver(array &$form, FormStateInterface $form_state): void {
    $this->importer->deleteUpload($form_state->get('upload')['uri'] ?? NULL);
    $form_state->setRedirect('ishop_items.import_listings');
  }

  /**
   * The uploaded sheet's tabs (read again at each step).
   */
  private function sheets(FormStateInterface $form_state): array {
    $upload = $form_state->get('upload');
    $path = $upload ? $this->fileSystem->realpath($upload['uri']) : FALSE;
    if (!$path || !is_file($path)) {
      throw new \RuntimeException((string) $this->t('The uploaded file is gone (it is kept for a day). Choose it again.'));
    }
    return SheetReader::read($path, $upload['extension']);
  }

  /**
   * A price without a pointless ".00".
   */
  private static function money(float $value): string {
    return str_replace('.00', '', number_format($value, 2));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Each button has its own submit handler.
  }

}
