<?php

declare(strict_types=1);

namespace Drupal\ishop_items;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Listings: managed with "manage platform listings"; never deleted by hand.
 *
 * Every item has exactly one listing per platform, so a listing goes only
 * when its item is deleted (in code, without an access check).
 */
final class ListingAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete') {
      return AccessResult::forbidden('Listings are deleted with their item.');
    }
    return AccessResult::allowedIfHasPermission($account, 'manage platform listings');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'manage platform listings');
  }

}
