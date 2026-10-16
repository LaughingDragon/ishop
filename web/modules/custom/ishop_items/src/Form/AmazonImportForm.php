<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_items\AmazonOrders;
use Drupal\ishop_items\Hook\ItemHooks;
use Drupal\ishop_items\ReturnWindow;

/**
 * Import from Amazon (Phase 6): ticked purchases become draft items.
 *
 * Step 1 reads the uploaded "Your Orders" file and deletes it at once; only
 * the needed fields of each purchase are kept, in the form's own state.
 * Step 2 lists the purchases not already in the site, newest first, with
 * those still inside their return window ticked (Liam, decision 241).
 */
final class AmazonImportForm extends FormBase {

  /**
   * Most purchases listed at once when showing older ones too.
   */
  public const MAX_ROWS = 1000;

  /**
   * Cody's time zone, for order dates and "today".
   */
  public const ZONE = 'America/Los_Angeles';

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ReturnWindow $returns,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_items_amazon_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'amazon-import';
    return $form_state->get('rows') === NULL ? $this->uploadStep($form) : $this->pickStep($form, $form_state);
  }

  /**
   * Step 1: the file.
   */
  private function uploadStep(array $form): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Upload the <strong>Your Orders</strong> file Amazon emailed after "Request your data" (the .zip as it came, or the Retail.OrderHistory .csv inside it). Next you tick which purchases to add as draft items.') . '</p><p>' . $this->t("The site reads only each purchase's name, date, price, tax, quantity, order number and ASIN, and deletes the uploaded file straight away. Digital and cancelled orders are left out.") . '</p>',
    ];
    $form['amazon'] = [
      '#type' => 'file',
      '#title' => $this->t('Amazon order file'),
      '#attributes' => ['accept' => '.zip,.csv,application/zip,text/csv'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['read'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read the file'),
      '#button_type' => 'primary',
      '#validate' => ['::readFile'],
      '#submit' => ['::rebuild'],
    ];
    return $form;
  }

  /**
   * Reads the upload into the form state and deletes it.
   */
  public function readFile(array &$form, FormStateInterface $form_state): void {
    $upload = $this->getRequest()->files->get('files')['amazon'] ?? NULL;
    if (!$upload || !$upload->isValid()) {
      $form_state->setErrorByName('amazon', $this->t('Choose the file first. (If it is bigger than 32 MB, upload the .csv inside it instead.)'));
      return;
    }
    $path = $upload->getRealPath();
    try {
      $result = AmazonOrders::read($path, self::ZONE);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('amazon', $e->getMessage());
      return;
    }
    finally {
      // Decision 241: the uploaded file isn't kept.
      @unlink($path);
    }
    if (!$result['rows']) {
      $form_state->setErrorByName('amazon', $this->t('No purchases were found in that file.'));
      return;
    }
    $rows = array_values($result['rows']);
    usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));
    $form_state->set('rows', $rows);
    $form_state->set('skipped', $result['skipped']);
  }

  /**
   * Rebuilds the form for its next step.
   */
  public function rebuild(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Step 2: tick the purchases to add.
   */
  private function pickStep(array $form, FormStateInterface $form_state): array {
    $rows = $form_state->get('rows');
    $already = $this->alreadyIn($rows);
    $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE)))->format('Y-m-d');
    $show_all = (bool) $form_state->get('show_all');
    $options = [];
    $ticked = [];
    $open = 0;
    $older = 0;
    foreach ($rows as $i => $row) {
      if (isset($already[self::key($row)])) {
        continue;
      }
      $by = $this->returns->defaultFor($row['date']);
      $is_open = $by >= $today;
      $is_open ? $open++ : $older++;
      if (!$is_open && !$show_all) {
        continue;
      }
      if (count($options) >= self::MAX_ROWS) {
        continue;
      }
      // Keys never "0": a checkbox valued 0 reads as unticked.
      $key = 'p' . $i;
      $options[$key] = '<strong>' . Html::escape(AmazonOrders::title($row['name'])) . '</strong><span class="amazon-import__meta">' . $this->t('Ordered @date · $@paid each with tax · Qty @qty · Return by @by', [
        '@date' => $row['date'],
        '@paid' => number_format($row['price'] + $row['tax'], 2),
        '@qty' => $row['quantity'],
        '@by' => $is_open ? $this->t('about @date', ['@date' => $by]) : $this->t('closed'),
      ]) . '</span>';
      if ($is_open) {
        $ticked[] = $key;
      }
    }
    $skipped = $form_state->get('skipped');
    $notes = [
      $open
        ? $this->formatPlural($open, '1 purchase is still inside its return window: it is ticked.', '@count purchases are still inside their return window: they are ticked.')
        : $this->t('No new purchases are still inside their return window.'),
    ];
    if ($already) {
      $notes[] = $this->formatPlural(count($already), '1 purchase is already in the site and is left out.', '@count purchases are already in the site and are left out.');
    }
    if ($skipped['cancelled'] || $skipped['digital']) {
      $notes[] = $this->t('Left out: @c cancelled and @d digital.', [
        '@c' => $skipped['cancelled'],
        '@d' => $skipped['digital'],
      ]);
    }
    $notes[] = $this->t('Return-by dates are about @days days from the order date, which is usually a few days before delivery, so they are on the early side.', ['@days' => $this->returns->settings()['days']]);
    $form['summary'] = [
      '#theme' => 'item_list',
      '#items' => $notes,
      '#attributes' => ['class' => ['amazon-import__notes']],
    ];
    if ($older && !$show_all) {
      $form['older'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural($older, 'Show 1 older purchase too', 'Show @count older purchases too'),
        '#submit' => ['::showAll'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['rm-button--ghost']],
      ];
    }
    if ($show_all && $older + $open > self::MAX_ROWS) {
      $form['cap'] = ['#markup' => '<p>' . $this->t('Showing the newest @max.', ['@max' => self::MAX_ROWS]) . '</p>'];
    }
    $form['items'] = $options ? [
      '#type' => 'checkboxes',
      '#title' => $this->t('Purchases to add'),
      '#options' => $options,
      '#default_value' => $ticked,
      '#attributes' => ['class' => ['amazon-import__list']],
    ] : ['#markup' => '<p>' . $this->t('Nothing new to add from this file.') . '</p>'];
    $form['#attached']['library'][] = 'ishop_items/amazon';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add the ticked ones as drafts'),
      '#button_type' => 'primary',
      '#access' => (bool) $options,
    ];
    $form['actions']['again'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start over'),
      '#submit' => ['::startOver'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button--ghost']],
    ];
    return $form;
  }

  /**
   * Shows the purchases whose return window has closed too.
   */
  public function showAll(array &$form, FormStateInterface $form_state): void {
    $form_state->set('show_all', TRUE);
    $form_state->setRebuild();
  }

  /**
   * Forgets the file's purchases and goes back to step 1.
   */
  public function startOver(array &$form, FormStateInterface $form_state): void {
    $form_state->set('rows', NULL);
    $form_state->set('show_all', FALSE);
    $form_state->setRebuild();
  }

  /**
   * The key that marks a purchase as already imported.
   */
  public static function key(array $row): string {
    return $row['order_id'] . '|' . ($row['asin'] !== '' ? $row['asin'] : mb_strtolower(AmazonOrders::title($row['name'])));
  }

  /**
   * Keys of the given purchases that already have an item.
   *
   * Matched on order ID and ASIN (or title when there's no ASIN).
   */
  private function alreadyIn(array $rows): array {
    $order_ids = array_values(array_unique(array_column($rows, 'order_id')));
    $storage = $this->entityTypeManager->getStorage('node');
    $found = [];
    foreach (array_chunk($order_ids, 500) as $chunk) {
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('type', 'item')
        ->condition('field_order_id', $chunk, 'IN')
        ->execute();
      foreach ($storage->loadMultiple($ids) as $item) {
        $asin = strtoupper((string) $item->get('field_asin')->value);
        $found[self::key([
          'order_id' => (string) $item->get('field_order_id')->value,
          'asin' => $asin,
          'name' => (string) $item->label(),
        ])] = TRUE;
      }
    }
    return $found;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('rows') !== NULL && !array_filter((array) $form_state->getValue('items'))) {
      $form_state->setErrorByName('items', $this->t('Tick at least one purchase.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $rows = $form_state->get('rows');
    $already = $this->alreadyIn($rows);
    $storage = $this->entityTypeManager->getStorage('node');
    $source = $this->amazonTerm();
    $created = 0;
    foreach (array_filter((array) $form_state->getValue('items')) as $key) {
      $row = $rows[(int) substr((string) $key, 1)] ?? NULL;
      if (!$row || isset($already[self::key($row)])) {
        continue;
      }
      $item = $storage->create([
        'type' => 'item',
        'title' => AmazonOrders::title($row['name'], ItemHooks::TITLE_MAX),
        'uid' => $this->currentUser()->id(),
        'status' => 1,
        'field_item_status' => 'draft',
        'field_purchase_date' => $row['date'],
        'field_order_id' => mb_substr($row['order_id'], 0, 255),
        'field_asin' => mb_substr($row['asin'], 0, 255) ?: NULL,
        'field_quantity' => $row['quantity'],
        'field_cost_paid' => $row['price'] > 0 ? round($row['price'] + $row['tax'], 2) : NULL,
        'field_original_price' => $row['price'] > 0 ? round($row['price'], 2) : NULL,
        'field_purchase_source' => $source,
      ]);
      // Return-by fills in on save (ItemHooks::nodePresave).
      $item->setRevisionLogMessage('Imported from the Amazon order history.');
      $item->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $item->save();
      $already[self::key($row)] = TRUE;
      $created++;
    }
    // The purchases aren't kept once the items are made.
    $form_state->set('rows', NULL);
    $this->messenger()->addStatus($this->formatPlural($created, 'Added 1 draft item from Amazon. Add its photos and price when you have it in hand.', 'Added @count draft items from Amazon. Add their photos and prices when you have them in hand.'));
    $form_state->setRedirect('ishop_matrix.stockroom', [], ['query' => ['status' => 'draft']]);
  }

  /**
   * The "Amazon" purchase source term (created if missing).
   */
  private function amazonTerm(): int {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => 'purchase_source', 'name' => 'Amazon']);
    if ($found) {
      return (int) reset($found)->id();
    }
    $term = $terms->create(['vid' => 'purchase_source', 'name' => 'Amazon']);
    $term->save();
    return (int) $term->id();
  }

}
