<?php

declare(strict_types=1);

namespace Drupal\ishop_ai\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ishop_ai\AiRuns;
use Drupal\ishop_ai\AiStatus;
use Drupal\ishop_ai\Form\SuggestionForm;
use Drupal\node\NodeInterface;

/**
 * Hooks for the AI helpers: the item page panel, /add, mail, status.json.
 */
final class AiHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiStatus $status,
    private readonly AiRuns $runs,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_view() for nodes: the AI panel on items.
   *
   * "Write my listing", the run's status, the suggestion to review, and
   * "Why this price" (the research and its sources).
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'item' || $view_mode !== 'full' || $node->isNew() || !$this->currentUser->hasPermission('manage platform listings')) {
      return;
    }
    $build['#cache']['max-age'] = 0;
    $run = $this->runs->latest((int) $node->id());
    $open = $run && in_array($run['status'], ['queued', 'running'], TRUE);
    $status = match (TRUE) {
      !$run => NULL,
      $run['status'] === 'queued' => $run['reason'] !== '' ? $run['reason'] : (string) $this->t('In the queue: it starts within a minute.'),
      $run['status'] === 'running' => (string) $this->t('Working on it: looking at the photos and searching sold prices. Refresh in a minute.'),
      $run['status'] === 'failed' => (string) $this->t('The last try did not work: @reason', ['@reason' => $run['reason']]),
      default => NULL,
    };
    $draft = $run && $run['status'] === 'done' ? $run['draft'] : NULL;
    $sources = [];
    foreach (preg_split('/\R/', (string) ($draft['sources'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line) {
      $line = trim($line);
      $sources[] = ['text' => $line, 'url' => preg_match('#^https?://#', $line) ? $line : NULL];
    }
    $spent = $this->runs->costByItem()[(int) $node->id()] ?? 0.0;
    $build['ishop_ai'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai', 'class' => ['rm-card', 'ishop-ai']],
      '#weight' => -28,
      '#attached' => ['library' => ['ishop_ai/ai']],
      'head' => [
        '#type' => 'inline_template',
        '#template' => '<h2>{{ title }}</h2>{% if status %}<p class="ishop-ai__status">{{ status }}</p>{% endif %}{% if button %}<p><a class="rm-button rm-button--gold" href="{{ button }}">{{ button_label }}</a></p>{% endif %}',
        '#context' => [
          'title' => $this->t('AI listing helper'),
          'status' => $status,
          'button' => $open ? NULL : Url::fromRoute('ishop_ai.write_listing', ['node' => $node->id()])->toString(),
          'button_label' => $draft ? $this->t('Write it again') : $this->t('Write my listing'),
        ],
      ],
      'cost' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ishop-ai__cost']],
        '#value' => $this->t('AI cost for this item so far: $@cost.', ['@cost' => number_format($spent, 2)]),
        '#access' => $spent > 0,
        '#weight' => 50,
      ],
    ];
    if ($draft && !$run['reviewed']) {
      $build['ishop_ai']['intro'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The AI suggests these. Tick what to use; nothing changes until you do.'),
      ];
      $build['ishop_ai']['form'] = $this->formBuilder->getForm(SuggestionForm::class, $node, $run);
    }
    if ($draft) {
      $build['ishop_ai']['why'] = [
        '#type' => 'inline_template',
        '#template' => '<details class="ishop-ai__why"{% if open %} open{% endif %}><summary>{{ title }}</summary><p><strong>{{ price }}</strong></p>{% if notes %}<p>{{ notes }}</p>{% else %}<p>{{ no_search }}</p>{% endif %}{% if sources %}<ul>{% for s in sources %}<li>{% if s.url %}<a href="{{ s.url }}" rel="noopener noreferrer" target="_blank">{{ s.text }}</a>{% else %}{{ s.text }}{% endif %}</li>{% endfor %}</ul>{% endif %}</details>',
        '#context' => [
          'open' => !$run['reviewed'],
          'title' => $this->t('Why this price'),
          'price' => SuggestionForm::suggested($draft, 'price'),
          'notes' => (string) ($draft['research_notes'] ?? ''),
          'no_search' => $this->t('The price search found nothing useful this time, so this price is an estimate from the photos alone.'),
          'sources' => $sources,
        ],
      ];
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for ishop_items_add: AI checkbox.
   */
  #[Hook('form_ishop_items_add_alter')]
  public function addFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!$this->status->get()['configured']) {
      return;
    }
    $form['ishop_ai_write'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also write the listing with AI (you choose what to use)'),
      '#default_value' => TRUE,
      '#weight' => 90,
    ];
    if (isset($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'][] = [self::class, 'queueAfterAdd'];
    }
    else {
      // Keep the form's own submit handler first (never replace it).
      $form['#submit'] = ($form['#submit'] ?? []) ?: ['::submitForm'];
      $form['#submit'][] = [self::class, 'queueAfterAdd'];
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the bulk photo drop.
   */
  #[Hook('form_ishop_items_bulk_add_alter')]
  public function bulkFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!$this->status->get()['configured']) {
      return;
    }
    $form['ishop_ai_write'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also write each listing with AI (you choose what to use)'),
      '#description' => $this->t('About $0.40 an item, within the $@cap monthly cap.', ['@cap' => (int) AiRuns::MONTHLY_CAP]),
      '#default_value' => TRUE,
      '#weight' => 90,
    ];
    $form['#submit'] = ($form['#submit'] ?? []) ?: ['::submitForm'];
    $form['#submit'][] = [self::class, 'queueAfterBulk'];
  }

  /**
   * After the bulk photo drop: queue "Write my listing" for each new item.
   */
  public static function queueAfterBulk(array &$form, FormStateInterface $form_state): void {
    $nids = $form_state->get('created_nids') ?? [];
    if (!$form_state->getValue('ishop_ai_write') || !$nids) {
      return;
    }
    foreach ($nids as $nid) {
      \Drupal::service(AiRuns::class)->queue((int) $nid);
    }
    \Drupal::messenger()->addStatus(\Drupal::translation()->formatPlural(count($nids), 'The AI is writing its listing: the draft appears on the item page in a few minutes.', 'The AI is writing all @count listings, one after another: drafts appear on the item pages over the next few minutes.'));
  }

  /**
   * After /add saved an item: queue "Write my listing" if ticked.
   */
  public static function queueAfterAdd(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('ishop_ai_write')) {
      return;
    }
    $redirect = $form_state->getRedirect();
    $nid = $redirect instanceof Url && $redirect->isRouted() ? ($redirect->getRouteParameters()['node'] ?? NULL) : NULL;
    if ($nid) {
      \Drupal::service(AiRuns::class)->queue((int) $nid);
      \Drupal::messenger()->addStatus(t('The AI is writing the listing: the draft appears on the item page in a minute or two.'));
    }
  }

  /**
   * Implements hook_mail(): the key expiry reminder.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key === 'key_reminder') {
      $message['subject'] = $params['subject'];
      $message['body'][] = $params['body'];
    }
  }

  /**
   * Implements hook_ishop_office_status_alter(): the "ai" section.
   *
   * Configured, expiry, days left, last success, paused: no key material.
   */
  #[Hook('ishop_office_status_alter')]
  public function statusAlter(array &$data): void {
    $data['ai'] = AiStatus::summary($this->status->get(), AiStatus::today());
  }

}
