<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ishop_items\Form\ListingsGridForm;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\PhotoPrep;
use Drupal\ishop_office\DiskStatus;
use Drupal\ishop_items\ReadyCheck;
use Drupal\node\NodeInterface;

/**
 * Hooks for items: photo preparation, the checklist, form helpers.
 */
final class ItemHooks {

  use StringTranslationTrait;

  /**
   * Longest item title: Poshmark and Mercari allow 80 characters.
   */
  public const TITLE_MAX = 80;

  public function __construct(
    private readonly PhotoPrep $photoPrep,
    private readonly MessengerInterface $messenger,
    private readonly ListingManager $listings,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_cron(): deletes abandoned item photos.
   *
   * Photos uploaded on /add but never saved with an item are deleted after a
   * day. A photo referenced by any item, or any item's revision history, is
   * kept.
   */
  #[Hook('cron')]
  public function cron(): void {
    $cutoff = \Drupal::time()->getRequestTime() - 86400;
    $media = \Drupal::entityTypeManager()->getStorage('media');
    $old = $media->getQuery()->accessCheck(FALSE)
      ->condition('bundle', 'item_photo')
      ->condition('created', $cutoff, '<')
      ->execute();
    if (!$old) {
      return;
    }
    $db = \Drupal::database();
    $used = $db->select('node_revision__field_photos', 'p')
      ->fields('p', ['field_photos_target_id'])
      ->condition('field_photos_target_id', $old, 'IN')
      ->distinct()
      ->execute()->fetchCol();
    $orphans = array_diff($old, $used);
    foreach ($media->loadMultiple($orphans) as $photo) {
      $file = $photo->get('field_media_image_1')->entity;
      $photo->delete();
      $file?->delete();
    }
    if ($orphans) {
      \Drupal::logger('ishop_items')->info('Deleted @n abandoned item photos.', ['@n' => count($orphans)]);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for nodes: six "To post" listings.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if ($node->bundle() === 'item') {
      $this->listings->createDefaults($node);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for nodes: listings go too.
   */
  #[Hook('node_predelete')]
  public function nodePredelete(NodeInterface $node): void {
    if ($node->bundle() === 'item') {
      $this->listings->deleteForItem($node);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for files.
   *
   * Prepares new item photos once, before they are saved. If ImageMagick
   * can't read a photo, the original is kept and a warning shown.
   */
  #[Hook('file_presave')]
  public function filePresave(FileInterface $file): void {
    if (!PhotoPrep::applies($file)) {
      return;
    }
    try {
      $this->photoPrep->prepare($file);
    }
    catch (\RuntimeException $e) {
      $this->messenger->addWarning($e->getMessage());
    }
  }

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function extraFieldInfo(): array {
    $extra['node']['item']['display']['ishop_ready'] = [
      'label' => $this->t('Ready to list'),
      'description' => $this->t('Checklist of what the item still needs before listing.'),
      'weight' => -50,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for nodes: the ready-to-list checklist.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'item' || !$display->getComponent('ishop_ready')) {
      return;
    }
    $build['ishop_storage'] = self::offlineNotice() + ['#weight' => -100];
    if ($view_mode === 'full' && $this->currentUser->hasPermission('manage platform listings')) {
      $build['ishop_listings'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'listings'],
        '#weight' => 90,
        'form' => $this->formBuilder->getForm(ListingsGridForm::class, $node),
        '#attached' => ['library' => ['ishop_items/item_form']],
      ];
      $build['#cache']['max-age'] = 0;
    }
    $missing = ReadyCheck::missing(self::checkValues($node));
    $build['ishop_ready'] = [
      '#type' => 'inline_template',
      '#template' => '{% if missing %}<section class="rm-card so-callout so-callout--warn ishop-ready" aria-labelledby="ishop-ready-title"><div class="so-callout__body"><h2 id="ishop-ready-title">{{ title }}</h2><ul>{% for m in missing %}<li>{{ m }}</li>{% endfor %}</ul></div></section>{% else %}<p class="ishop-ready ishop-ready--ok"><strong>{{ ok }}</strong></p>{% endif %}',
      '#context' => [
        'missing' => $missing,
        'title' => $this->t('Before listing, this item still needs'),
        'ok' => $this->t('Ready to list: everything needed is filled in.'),
      ],
    ];
  }

  /**
   * Plain values for ReadyCheck::missing().
   */
  public static function checkValues(NodeInterface $node): array {
    return [
      'photos' => $node->hasField('field_photos') ? count($node->get('field_photos')) : 0,
      'title' => (string) $node->label(),
      'description' => (string) ($node->get('field_description')->value ?? ''),
      'category' => !$node->get('field_category')->isEmpty(),
      'condition' => !$node->get('field_condition')->isEmpty(),
      'price' => $node->get('field_asking_price')->value,
    ];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node forms.
   *
   * Item forms: title limited to 80 characters, with live counters on the
   * title (warnings at Grailed's 60 and Vinted's 70) and the description
   * (1000, most platforms' limit).
   */
  #[Hook('form_node_form_alter')]
  public function nodeFormAlter(array &$form, FormStateInterface $form_state): void {
    $node = $form_state->getFormObject()->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'item') {
      return;
    }
    if (isset($form['title']['widget'][0]['value'])) {
      $title = &$form['title']['widget'][0]['value'];
      $title['#maxlength'] = self::TITLE_MAX;
      $title['#attributes']['data-ishop-counter'] = '60,70,80';
      $title['#description'] = $this->t('Up to 80 characters. Grailed cuts at 60 and Vinted at 70.');
    }
    if (isset($form['field_description']['widget'][0]['value'])) {
      $form['field_description']['widget'][0]['value']['#attributes']['data-ishop-counter'] = '1000';
    }
    $form['#attached']['library'][] = 'ishop_items/item_form';
    $form['ishop_storage'] = self::offlineNotice() + ['#weight' => -100];
  }

  /**
   * A clear notice when the data disk (item photos) is not mounted.
   *
   * The site and database don't depend on that disk, so everything else
   * keeps working; only photos are missing until it is back.
   */
  public static function offlineNotice(): array {
    $notice = ['#cache' => ['max-age' => 0]];
    if (!DiskStatus::photosOnline()) {
      $notice += [
        '#type' => 'inline_template',
        '#template' => '<div class="rm-card so-callout so-callout--warn ishop-photos-offline" role="alert"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p></div></div>',
        '#context' => [
          'title' => t('Photo storage offline'),
          'text' => t("The disk that holds item photos isn't mounted, so photos can't be shown or added right now. Everything else works. Tell site-foreman (punch list)."),
        ],
      ];
    }
    return $notice;
  }

}
