<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ishop_life\Vault;
use Drupal\node\NodeInterface;

/**
 * Hooks for the daily-life tools.
 */
final class LifeHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Vault $vault,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_view() for nodes: an item's documents.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'item' || $view_mode !== 'full' || $node->isNew()) {
      return;
    }
    $return = $node->toUrl()->toString();
    $build['ishop_documents'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="ishop-docs" aria-labelledby="ishop-docs-title"><h2 id="ishop-docs-title">{{ title }}</h2>{% if docs %}<ul>{% for d in docs %}<li><strong>{{ d.title }}</strong> ({{ d.kind }}{% if d.until %}, {{ until }} {{ d.until }}{% endif %}){% for f in d.files %} <a href="{{ f.url }}">{{ f.name }}</a>{% endfor %}</li>{% endfor %}</ul>{% endif %}<p><a class="rm-button rm-button--ghost" href="{{ add }}">{{ add_label }}</a></p></section>',
      '#context' => [
        'title' => $this->t('Documents'),
        'docs' => array_map(fn ($doc) => $this->vault->describe($doc, $return), $this->vault->documents(NULL, (int) $node->id())),
        'until' => $this->t('warranty until'),
        'add' => Url::fromRoute('ishop_life.mailroom', [], ['query' => ['item' => $node->id()]])->toString(),
        'add_label' => $this->t('Add a receipt, warranty or manual'),
      ],
      '#weight' => 80,
      '#attached' => ['library' => ['ishop_life/log']],
      '#cache' => ['tags' => ['node_list:document']],
    ];
  }

}
