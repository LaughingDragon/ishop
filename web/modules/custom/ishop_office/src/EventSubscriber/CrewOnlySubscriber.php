<?php

declare(strict_types=1);

namespace Drupal\ishop_office\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ishop_office\OfficeStructure;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers "Page not found" for crew-only office nodes, for anyone not crew.
 *
 * Node access hooks don't apply to administrators ("bypass node access"),
 * and the owner's account is an administrator, so every page about a
 * crew-only node (view, edit, revisions, answer form) is refused here,
 * after routing. Not found rather than access denied: nothing is revealed.
 */
final class CrewOnlySubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32) has upcast the route parameters.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Refuses requests whose route parameters include a crew-only node.
   */
  public function onRequest(RequestEvent $event): void {
    // The "Page not found" page itself is a sub-request carrying the same
    // attributes plus the exception: let it render.
    if (OfficeStructure::isCrew($this->currentUser) || $event->getRequest()->attributes->has('exception')) {
      return;
    }
    foreach ($event->getRequest()->attributes->all() as $value) {
      if ($value instanceof NodeInterface && OfficeStructure::isCrewOnly($value)) {
        throw new NotFoundHttpException();
      }
    }
  }

}
