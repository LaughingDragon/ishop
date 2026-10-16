<?php

declare(strict_types=1);

namespace Drupal\ishop_shop\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ishop_shop\Shop;
use Drupal\ishop_shop\ShopPhotos;

/**
 * Site office > Public shop: the one switch (off by default).
 *
 * Only Cody and Liam can open it. Turning it on or off makes or deletes the
 * public photo copies at once.
 */
final class ShopSwitchForm extends FormBase {

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected Shop $shop,
    protected ShopPhotos $photos,
  ) {}

  /**
   * Access: Cody and the webmaster only.
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(Shop::canManage($account))->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_shop_switch';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $on = $this->shop->isOn();
    $items = $this->shop->items();
    $form['state'] = [
      '#type' => 'inline_template',
      '#template' => '<section class="so-callout{% if not on %} so-callout--warn{% endif %}"><div class="so-callout__body"><h2>{{ title }}</h2><p>{{ text }}</p><p>{{ count }} <a href="{{ shop }}">{{ look }}</a></p></div></section>',
      '#context' => [
        'on' => $on,
        'title' => $on ? $this->t('The public shop is ON') : $this->t('The public shop is OFF'),
        'text' => $on
          ? $this->t('Anyone can see /shop and its item pages, and visitors to the front page are sent there. Search engines may list the shop pages (and only those). Everything else still asks visitors to sign in.')
          : $this->t('Nobody who is not signed in can see it: /shop and its item pages answer "Not found" and no public photos exist. Liam asked that it stays off until he says.'),
        'count' => $this->formatPlural(count($items), '1 item would show.', '@count items would show.'),
        'shop' => Url::fromRoute('ishop_shop.catalog')->toString(),
        'look' => $on ? $this->t('Open the shop') : $this->t('Preview the shop'),
      ],
    ];
    $form['rule'] = [
      '#type' => 'inline_template',
      '#template' => '<h2>{{ h }}</h2><ul>{% for r in rules %}<li>{{ r }}</li>{% endfor %}</ul><p>{{ never }}</p>',
      '#context' => [
        'h' => $this->t('Which items show'),
        'rules' => [
          $this->t('"Show in shop" is ticked on the item.'),
          $this->t('It is Ready or Listed (not Draft, Sold, Donated or Kept).'),
          $this->t('"Cleared to sell" is ticked.'),
          $this->t('It has a price and at least one photo.'),
        ],
        'never' => $this->t('Buyers see photos, title, description, flaws, size, condition, price and links to the live marketplace listings (Posted, with a link). Never cost paid, storage location, internal notes, AI notes or anything from the office.'),
      ],
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $on
        ? $this->t('Yes, turn the public shop off')
        : $this->t('Yes, make the shop public (Liam has said OK)'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $on ? $this->t('Turn the shop off') : $this->t('Turn the shop on'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('Tick the box to confirm.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $on = !$this->shop->isOn();
    $this->configFactory()->getEditable(Shop::CONFIG)->set('enabled', $on)->save();
    $counts = $this->photos->sync();
    $this->logger('ishop_shop')->notice('Public shop switched @state by @name.', [
      '@state' => $on ? 'ON' : 'OFF',
      '@name' => $this->currentUser()->getAccountName(),
    ]);
    $this->messenger()->addStatus($on
      ? $this->t('The public shop is on: @made public photos made.', ['@made' => $counts['made'] + $counts['kept']])
      : $this->t('The public shop is off: @removed public photos deleted.', ['@removed' => $counts['removed']]));
  }

}
