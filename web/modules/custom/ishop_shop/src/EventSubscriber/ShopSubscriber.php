<?php

declare(strict_types=1);

namespace Drupal\ishop_shop\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ishop_shop\Shop;
use Drupal\ishop_shop\ShopPhotos;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Front-page redirect for visitors, and photo sync after the switch moves.
 */
final class ShopSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Shop $shop,
    private readonly ShopPhotos $photos,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Before routing (32): routing refuses the front page to visitors.
      KernelEvents::REQUEST => ['frontRedirect', 33],
      KernelEvents::TERMINATE => ['syncPhotos'],
      ConfigEvents::SAVE => ['configSave'],
    ];
  }

  /**
   * While the shop is on, visitors to the front page are sent to /shop.
   *
   * Signed-in users (Cody) still land on the Reseller Matrix.
   */
  public function frontRedirect(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->currentUser->isAuthenticated()
      || !in_array(rtrim($event->getRequest()->getPathInfo(), '/'), ['', rtrim((string) $this->configFactory->get('system.site')->get('page.front'), '/')], TRUE)
      || !$this->shop->isOn()) {
      return;
    }
    $response = new LocalRedirectResponse(Url::fromRoute('ishop_shop.catalog')->toString());
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['config:' . Shop::CONFIG]));
    $event->setResponse($response);
  }

  /**
   * Makes or deletes photo copies once the response has gone.
   */
  public function syncPhotos(): void {
    $this->photos->syncIfDirty();
  }

  /**
   * When the switch moves: resync and drop cached "access denied" pages.
   */
  public function configSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === Shop::CONFIG && $event->isChanged('enabled')) {
      $this->photos->markDirty();
      // The front page's cached 403 for visitors doesn't carry the config tag.
      Cache::invalidateTags(['4xx-response', 'rendered']);
    }
  }

}
