<?php

declare(strict_types=1);

namespace Drupal\ishop_office\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Keeps the site office in the Construction theme ("that's us").
 *
 * Cody's Reseller Matrix is the default front-end theme; the office, its
 * nodes (reports, punch items, decisions, phases, the plans page) and Cody's
 * style questionnaire stay in hard hats. From the Reseller Matrix README.
 */
final class OfficeThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Content types that belong to the site office.
   */
  public const OFFICE_BUNDLES = ['office_report', 'office_decision', 'office_phase', 'office_plan', 'office_punch'];

  /**
   * Webforms that belong to the site office.
   */
  public const OFFICE_WEBFORMS = ['ask_cody'];

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();
    // Admin pages (editing an office node, for example) stay in Gin.
    if (!$route || $route->getOption('_admin_route')) {
      return FALSE;
    }
    if (str_starts_with($route->getPath(), '/site-office')) {
      return TRUE;
    }
    $node = $route_match->getParameter('node');
    if ($node instanceof NodeInterface && in_array($node->bundle(), self::OFFICE_BUNDLES, TRUE)) {
      return TRUE;
    }
    $type = $route_match->getParameter('node_type');
    if ($type instanceof NodeTypeInterface && in_array($type->id(), self::OFFICE_BUNDLES, TRUE)) {
      return TRUE;
    }
    $webform = $route_match->getParameter('webform');
    $id = is_object($webform) ? $webform->id() : $webform;
    return is_string($id) && in_array($id, self::OFFICE_WEBFORMS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return 'construction';
  }

}
