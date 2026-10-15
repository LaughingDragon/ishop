<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix\Hook;

use Drupal\block\Entity\Block;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ishop_matrix\Birthday;

/**
 * Hooks for the Reseller Matrix front of house.
 */
final class MatrixHooks {

  use StringTranslationTrait;

  /**
   * Cody's front-end theme.
   */
  public const THEME = 'reseller_matrix';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ThemeManagerInterface $themeManager,
    private readonly Birthday $birthday,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Whether this is a Reseller Matrix page in birthday mode (TEMPORARY).
   */
  private function isParty(): bool {
    return $this->isMatrix() && $this->birthday->isActive($this->currentUser);
  }

  /**
   * Implements hook_page_attachments(): birthday mode styles and script.
   *
   * TEMPORARY (birthday mode): see TEMPORARY.md.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$page): void {
    $cache = $this->birthday->cacheability();
    $page['#cache']['contexts'] = array_merge($page['#cache']['contexts'] ?? [], $cache['contexts']);
    $page['#cache']['tags'] = array_merge($page['#cache']['tags'] ?? [], $cache['tags']);
    if ($this->isParty()) {
      $page['#attached']['library'][] = 'ishop_matrix/birthday';
    }
  }

  /**
   * Implements hook_mail(): the birthday email and its test.
   *
   * TEMPORARY (birthday mode): see TEMPORARY.md.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if (in_array($key, ['birthday', 'birthday_test'], TRUE)) {
      $message['subject'] = $params['subject'];
      $message['body'][] = $params['body'];
    }
  }

  /**
   * Implements hook_page_bottom(): the song and "Resume the party".
   *
   * No autoplay (browsers block it): the dashboard's "Start the party"
   * button starts it, and other pages offer "Resume the party" if it was
   * playing. TEMPORARY (birthday mode): see TEMPORARY.md.
   */
  #[Hook('page_bottom')]
  public function pageBottom(array &$page_bottom): void {
    $page_bottom['#cache'] = $this->birthday->cacheability();
    if (!$this->isParty()) {
      return;
    }
    // The first page of the day launches the party: it plays at once where
    // the browser allows, otherwise a full-screen sign waits for one tap.
    $launch = $this->birthday->claimLaunch($this->currentUser);
    $page_bottom['#cache']['max-age'] = 0;
    $page_bottom['ishop_birthday'] = [
      '#type' => 'inline_template',
      '#template' => '<audio id="rm-party-song" src="{{ src }}" loop preload="{{ launch ? \'auto\' : \'none\' }}"{% if launch %} data-autolaunch{% endif %}></audio><button type="button" class="rm-button rm-button--gold rm-party-resume" hidden data-start="{{ start }}" data-resume="{{ resume }}" data-pause="{{ pause }}">{{ resume }}</button>
{% if launch %}<div class="rm-party-gate" role="dialog" aria-modal="true" aria-labelledby="rm-party-gate-title" hidden><div class="rm-confetti" aria-hidden="true"></div><div class="rm-party-gate__sign"><span class="rm-badge rm-badge--party">{{ badge }}</span><h2 id="rm-party-gate-title">{{ hello }}</h2><p>{{ tap }}</p><button type="button" class="rm-button rm-button--gold rm-party-gate__start">{{ start }}</button> <button type="button" class="rm-button rm-button--ghost rm-party-gate__later">{{ later }}</button></div></div>{% endif %}',
      '#context' => [
        'src' => Url::fromRoute('ishop_matrix.birthday_song')->toString(),
        'start' => $this->t('Start the party'),
        'resume' => $this->t('Resume the party'),
        'pause' => $this->t('Pause the party'),
        'launch' => $launch,
        'badge' => $this->t('Today is a very big deal'),
        'hello' => $this->t('Happy birthday, boss!'),
        'tap' => $this->t('Tap anywhere to start the party.'),
        'later' => $this->t('Not now'),
      ],
    ];
  }

  /**
   * Whether this page is in the Reseller Matrix theme.
   */
  private function isMatrix(): bool {
    return $this->themeManager->getActiveTheme()->getName() === self::THEME;
  }

  /**
   * Implements hook_page_attachments_alter(), after Gin Toolbar's.
   *
   * Without the sidebar, its styles (body padding, the grey bar) go too.
   */
  #[Hook('page_attachments_alter', order: new OrderAfter(modules: ['gin_toolbar', 'navigation']))]
  public function pageAttachmentsAlter(array &$page): void {
    if (!$this->isMatrix() || empty($page['#attached']['library'])) {
      return;
    }
    $page['#attached']['library'] = array_values(array_filter(
      $page['#attached']['library'],
      fn (string $library) => !preg_match('#^(gin|gin_toolbar|navigation|toolbar)/#', $library),
    ));
  }

  /**
   * Implements hook_preprocess_HOOK() for html.
   *
   * Front-end (Reseller Matrix) pages have no admin sidebar or top bar, for
   * everyone; admin pages and the site office keep them. Crew reach admin
   * pages through the "Admin" button in the header. Navigation adds them in
   * hook_page_top() with Order::Last and there is no page_top alter, so they
   * are removed here, together with Gin Toolbar's body classes.
   */
  #[Hook('preprocess_html', order: new OrderAfter(modules: ['gin_toolbar']))]
  public function preprocessHtml(array &$variables): void {
    if (!$this->isMatrix()) {
      return;
    }
    unset($variables['page_top']['navigation'], $variables['page_top']['top_bar']);
    // TEMPORARY (birthday mode): see TEMPORARY.md.
    if ($this->birthday->isActive($this->currentUser)) {
      $variables['attributes']['class'][] = 'rm-birthday';
    }
    if (!empty($variables['attributes']['class'])) {
      $variables['attributes']['class'] = array_values(array_filter(
        (array) $variables['attributes']['class'],
        fn ($class) => !str_starts_with((string) $class, 'gin--'),
      ));
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for page: the header's "Admin" button.
   *
   * Shown only to crew who can use the admin pages.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $variables['#cache']['contexts'][] = 'user.permissions';
    // TEMPORARY (birthday mode): see TEMPORARY.md.
    if ($this->isParty()) {
      $variables['wordmark'] = $this->t('Reseller Matrix · birthday edition');
    }
    $variables['admin_url'] = \Drupal::currentUser()->hasPermission('access administration pages')
      ? Url::fromRoute('system.admin')->toString()
      : NULL;
  }

  /**
   * Implements hook_block_access().
   *
   * The dashboard's greeting is its h1, so the page title block is hidden
   * there (one h1 per page).
   */
  #[Hook('block_access')]
  public function blockAccess(Block $block, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view' || $block->getPluginId() !== 'page_title_block') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf($this->routeMatch->getRouteName() === 'ishop_matrix.dashboard')
      ->addCacheContexts(['route']);
  }

}
