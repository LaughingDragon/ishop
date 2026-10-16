<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Mail setup shows the inbox's form under the sending one.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('ishop_office.mail_setup')) {
      $route->setDefault('_controller', '\Drupal\ishop_inbox\Controller\InboxController::mailSetup');
      $defaults = $route->getDefaults();
      unset($defaults['_form']);
      $route->setDefaults($defaults);
    }
  }

}
