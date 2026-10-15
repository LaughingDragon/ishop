<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\ishop_items\PhotoPrep;
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
  ) {}

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
    $missing = ReadyCheck::missing(self::checkValues($node));
    $build['ishop_ready'] = [
      '#type' => 'inline_template',
      '#template' => '{% if missing %}<section class="so-callout so-callout--warn ishop-ready" aria-labelledby="ishop-ready-title"><div class="so-callout__body"><h2 id="ishop-ready-title">{{ title }}</h2><ul>{% for m in missing %}<li>{{ m }}</li>{% endfor %}</ul></div></section>{% else %}<p class="ishop-ready ishop-ready--ok"><strong>{{ ok }}</strong></p>{% endif %}',
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
  }

}
