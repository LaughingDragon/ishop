<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_life\Form\DocumentForm;
use Drupal\ishop_life\LifeStructure;
use Drupal\ishop_life\Vault;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Mailroom: the private document vault (Phase 5 step 5).
 */
final class MailroomController extends ControllerBase {

  public function __construct(
    private readonly Vault $vault,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * The add form, then the documents (filter by kind).
   */
  public function page(): array {
    $kind = (string) $this->requestStack->getCurrentRequest()->query->get('kind', '');
    $kind = isset(LifeStructure::DOC_KINDS[$kind]) ? $kind : '';
    $return = Url::fromRoute('ishop_life.mailroom')->toString();
    $docs = array_map(fn ($doc) => $this->vault->describe($doc, $return), $this->vault->documents($kind ?: NULL));
    $tabs = [['label' => $this->t('All'), 'url' => $return, 'current' => $kind === '']];
    foreach (LifeStructure::DOC_KINDS as $key => $label) {
      $tabs[] = [
        'label' => $this->t($label),
        'url' => Url::fromRoute('ishop_life.mailroom', [], ['query' => ['kind' => $key]])->toString(),
        'current' => $kind === $key,
      ];
    }
    return [
      'form' => $this->formBuilder()->getForm(DocumentForm::class),
      'docs' => [
        '#type' => 'inline_template',
        '#template' => '<section class="rm-vault" aria-labelledby="rm-vault-title"><h2 id="rm-vault-title">{{ title }}</h2><nav class="rm-money__periods" aria-label="{{ kinds_label }}">{% for t in tabs %}<a href="{{ t.url }}" class="rm-money__period{{ t.current ? \' is-current\' }}"{% if t.current %} aria-current="page"{% endif %}>{{ t.label }}</a>{% endfor %}</nav>
{% for d in docs %}<article class="rm-card rm-vault__doc"><h3>{{ d.title }}</h3><p class="rm-vault__meta">{{ d.kind }}{% if d.item %} · <a href="{{ d.item.url }}">{{ d.item.title }}</a>{% endif %}{% if d.until %} · {{ until_label }} {{ d.until }}{% endif %}</p>{% if d.note %}<p>{{ d.note }}</p>{% endif %}<p class="rm-log__links">{% for f in d.files %}<a class="rm-button rm-button--ghost" href="{{ f.url }}">{{ f.name }}{% if f.pdf %} (PDF){% endif %}</a>{% endfor %}<a href="{{ d.edit }}">{{ edit }}</a><a href="{{ d.delete }}">{{ delete }}</a></p></article>{% else %}<p>{{ empty }}</p>{% endfor %}</section>',
        '#context' => [
          'title' => $this->t('In the vault'),
          'kinds_label' => $this->t('Kind'),
          'tabs' => $tabs,
          'docs' => $docs,
          'until_label' => $this->t('warranty until'),
          'edit' => $this->t('Edit'),
          'delete' => $this->t('Delete'),
          'empty' => $this->t('Nothing here yet.'),
        ],
      ],
      '#attached' => ['library' => ['ishop_life/log', 'ishop_matrix/matrix']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
