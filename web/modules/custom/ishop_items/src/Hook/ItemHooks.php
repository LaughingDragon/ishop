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
use Drupal\ishop_items\Cleared;
use Drupal\ishop_items\EbaySold;
use Drupal\ishop_items\ListingManager;
use Drupal\ishop_items\PhotoPrep;
use Drupal\ishop_office\DiskStatus;
use Drupal\ishop_items\ReadyCheck;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

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
   * Implements hook_preprocess_HOOK() for page: "Add item" for the headers.
   *
   * The Construction theme (site office) shows it beside My account, so a
   * site office page is never a dead end on the phone.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $variables['#cache']['contexts'][] = 'user.permissions';
    $access = \Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess('item');
    $variables['ishop_add_item_url'] = $access ? '/add' : NULL;
  }

  /**
   * Implements hook_theme(): the "Post this item" page.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ishop_post_page' => [
        'variables' => [
          'item' => [],
          'platform' => [],
          'tabs' => [],
          'eligibility' => [],
          'missing' => [],
          'fields' => [],
          'photos' => [],
          'photo_note' => '',
          'photos_left_out' => 0,
          'zip_url' => NULL,
          'tips' => [],
          'queue' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_page_attachments(): the home-screen app tags.
   *
   * On every page, the login gate included, so "Add to Home Screen" works
   * from wherever Cody is: the manifest, the 180 px Apple touch icon that
   * iPhone uses for the home-screen icon, and the app title and colours.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $icons = '/' . \Drupal::moduleHandler()->getModule('ishop_items')->getPath() . '/icons';
    $tags = [
      'ishop_manifest' => ['link', ['rel' => 'manifest', 'href' => '/manifest.webmanifest']],
      'ishop_apple_touch_icon' => ['link', [
        'rel' => 'apple-touch-icon',
        'sizes' => '180x180',
        'href' => "$icons/apple-touch-icon.png",
      ],
      ],
      'ishop_icon_192' => ['link', [
        'rel' => 'icon',
        'type' => 'image/png',
        'sizes' => '192x192',
        'href' => "$icons/icon-192.png",
      ],
      ],
      'ishop_theme_color' => ['meta', ['name' => 'theme-color', 'content' => '#161312']],
      'ishop_apple_capable' => ['meta', ['name' => 'apple-mobile-web-app-capable', 'content' => 'yes']],
      'ishop_mobile_capable' => ['meta', ['name' => 'mobile-web-app-capable', 'content' => 'yes']],
      'ishop_apple_title' => ['meta', ['name' => 'apple-mobile-web-app-title', 'content' => 'Reseller Matrix']],
      'ishop_apple_status' => ['meta', ['name' => 'apple-mobile-web-app-status-bar-style', 'content' => 'black']],
    ];
    foreach ($tags as $key => [$tag, $attributes]) {
      $attachments['#attached']['html_head'][] = [['#tag' => $tag, '#attributes' => $attributes], $key];
    }
  }

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
    if ($view_mode === 'full') {
      $this->itemLayout($build, $node);
    }
    if ($view_mode === 'full' && $this->currentUser->hasPermission('manage platform listings')) {
      $build['ishop_listings'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'listings'],
        '#weight' => 90,
        'post' => Cleared::isCleared($node) ? [
          '#type' => 'link',
          '#title' => $this->t('Post this item'),
          '#url' => Url::fromRoute('ishop_items.post_start', ['node' => $node->id()]),
          '#attributes' => ['class' => ['rm-button', 'rm-button--gold', 'ishop-post-link']],
        ] : [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t("Check it's OK to sell first: tick Cleared to sell on the item's Edit page. Then you can post it."),
          '#attributes' => ['class' => ['ishop-not-cleared']],
        ],
        // Real sold prices, checked by Cody on eBay itself (decision 264).
        'sold' => ($sold = EbaySold::url((string) $node->label())) ? [
          '#type' => 'link',
          '#title' => $this->t('See eBay sold prices'),
          '#url' => Url::fromUri($sold),
          '#attributes' => [
            'class' => ['rm-button', 'rm-button--ghost', 'ishop-sold-link'],
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
          ],
        ] : [],
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
   * Fields shown in the details panel, grouped: group => [field => label].
   */
  private const DETAIL_GROUPS = [
    'Listing' => [
      'field_brand' => 'Brand',
      'field_size' => 'Size',
      'field_color' => 'Color',
      'field_category' => 'Category',
      'field_condition' => 'Condition',
      'field_quantity' => 'Quantity',
      'field_item_status' => 'Status',
    ],
    'Money' => [
      'field_asking_price' => 'Asking price',
      'ai_range' => 'AI price range',
      'field_original_price' => 'Original price',
      'field_cost_paid' => 'What I paid',
      'field_donation_value' => 'Estimated donation value',
    ],
    'Shipping' => [
      'field_weight_oz' => 'Weight',
      'package' => 'Package',
    ],
    'Storage' => [
      'field_storage_location' => 'Location',
      'field_show_in_shop' => 'Show in shop',
    ],
  ];

  /**
   * Long text fields shown as their own sections: field => heading.
   */
  private const TEXT_SECTIONS = [
    'field_description' => 'Description',
    'field_flaws' => 'Flaws and condition notes',
    'field_internal_notes' => 'Internal notes',
  ];

  /**
   * The item page: photo gallery, a tidy details panel, text sections.
   *
   * Replaces the default one-field-per-line output (and photo file names).
   */
  private function itemLayout(array &$build, NodeInterface $node): void {
    $build['ishop_gallery'] = $this->gallery($node) + ['#weight' => -40];

    $groups = [];
    foreach (self::DETAIL_GROUPS as $group => $fields) {
      $rows = [];
      foreach ($fields as $field => $label) {
        $value = $this->detailValue($node, $field);
        if ($value !== NULL && $value !== '') {
          $rows[] = ['label' => $this->t($label), 'value' => $value];
        }
      }
      if ($rows) {
        $groups[] = ['title' => $this->t($group), 'rows' => $rows];
      }
    }
    $build['ishop_details'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="rm-card ishop-details" aria-label="{{ label }}">{% for g in groups %}<div class="ishop-details__group"><h3>{{ g.title }}</h3><dl>{% for r in g.rows %}<dt>{{ r.label }}</dt><dd>{{ r.value }}</dd>{% endfor %}</dl></div>{% endfor %}</section>',
      '#context' => ['groups' => $groups, 'label' => $this->t('Item details')],
      '#weight' => -30,
    ];

    $weight = -20;
    foreach (self::TEXT_SECTIONS as $field => $heading) {
      $text = trim((string) ($node->get($field)->value ?? ''));
      if ($text !== '') {
        $build['ishop_text_' . $field] = [
          '#type' => 'inline_template',
          '#template' => '<section class="ishop-text"><h2>{{ heading }}</h2><p>{{ text|nl2br }}</p></section>',
          '#context' => ['heading' => $this->t($heading), 'text' => $text],
          '#weight' => $weight++,
        ];
      }
    }

    foreach (array_merge(...array_values(array_map('array_keys', self::DETAIL_GROUPS))) as $field) {
      unset($build[$field]);
    }
    $replaced = [
      'field_photos', 'field_ai_price_min', 'field_ai_price_max',
      'field_package_length', 'field_package_width', 'field_package_height',
      ...array_keys(self::TEXT_SECTIONS),
    ];
    foreach ($replaced as $field) {
      unset($build[$field]);
    }
    $build['#attached']['library'][] = 'ishop_items/item_page';
  }

  /**
   * The photo gallery: a large cover and a row of thumbnails, no file names.
   */
  private function gallery(NodeInterface $node): array {
    $photos = [];
    $wide = ImageStyle::load('wide');
    $thumb = ImageStyle::load('thumbnail');
    foreach ($node->get('field_photos') as $reference) {
      $file = $reference->entity?->get('field_media_image_1')->entity;
      if ($file) {
        $photos[] = [
          'large' => $wide->buildUrl($file->getFileUri()),
          'thumb' => $thumb->buildUrl($file->getFileUri()),
        ];
      }
    }
    if (!$photos) {
      return ['#markup' => '<p class="ishop-gallery__none">' . $this->t('No photos yet.') . '</p>'];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<section class="ishop-gallery" aria-label="{{ label }}"><figure class="ishop-gallery__cover"><img src="{{ photos[0].large }}" alt="{{ cover_alt }}"></figure>{% if photos|length > 1 %}<ul class="ishop-gallery__thumbs">{% for p in photos %}<li><a class="ishop-gallery__thumb{% if loop.first %} is-current{% endif %}" href="{{ p.large }}" data-large="{{ p.large }}" aria-label="{{ show }} {{ loop.index }}"><img src="{{ p.thumb }}" alt=""></a></li>{% endfor %}</ul>{% endif %}</section>',
      '#context' => [
        'photos' => $photos,
        'label' => $this->t('Photos'),
        'cover_alt' => $this->t('@title, photo 1 of @n', ['@title' => $node->label(), '@n' => count($photos)]),
        'show' => $this->t('Show photo'),
      ],
    ];
  }

  /**
   * A details panel value as plain text, or NULL when empty.
   */
  private function detailValue(NodeInterface $node, string $field): ?string {
    $money = fn ($v) => $v === NULL || $v === '' ? NULL : '$' . number_format((float) $v, 2);
    switch ($field) {
      case 'ai_range':
        $min = $money($node->get('field_ai_price_min')->value);
        $max = $money($node->get('field_ai_price_max')->value);
        return $min && $max ? "$min – $max" : ($min ?? $max);

      case 'package':
        $dim_fields = ['field_package_length', 'field_package_width', 'field_package_height'];
        $dims = array_map(fn ($f) => $node->get($f)->value, $dim_fields);
        return in_array(NULL, $dims, TRUE) ? NULL : implode(' × ', array_map(fn ($d) => rtrim(rtrim((string) $d, '0'), '.'), $dims)) . ' in';

      case 'field_weight_oz':
        $w = $node->get($field)->value;
        return $w === NULL ? NULL : rtrim(rtrim((string) $w, '0'), '.') . ' oz';

      case 'field_item_status':
        $item = $node->get($field)->first();
        return $item ? (string) ($item->getPossibleOptions()[$item->value] ?? $item->value) : NULL;

      case 'field_show_in_shop':
        return $node->get($field)->value ? (string) $this->t('Yes') : NULL;

      case 'field_asking_price':
      case 'field_original_price':
      case 'field_cost_paid':
      case 'field_donation_value':
        return $money($node->get($field)->value);
    }
    $items = $node->get($field);
    if ($items->isEmpty()) {
      return NULL;
    }
    $term = $items->entity ?? NULL;
    if ($term instanceof TermInterface) {
      // "Game room > Table": the full path for hierarchical terms.
      $parents = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadAllParents($term->id());
      return implode(' > ', array_map(fn ($t) => $t->label(), array_reverse($parents)));
    }
    return (string) $items->value;
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
