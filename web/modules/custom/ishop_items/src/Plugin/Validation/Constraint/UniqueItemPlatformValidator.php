<?php

declare(strict_types=1);

namespace Drupal\ishop_items\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Platforms;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks there is no other listing for the same item and platform.
 */
final class UniqueItemPlatformValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof PlatformListing || $value->get('item')->isEmpty()) {
      return;
    }
    $query = $this->entityTypeManager->getStorage('platform_listing')->getQuery()
      ->accessCheck(FALSE)
      ->condition('item', $value->get('item')->target_id)
      ->condition('platform', $value->getPlatform())
      ->range(0, 1);
    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }
    if ($query->execute()) {
      $this->context->addViolation($constraint->message, [
        '%platform' => Platforms::PLATFORMS[$value->getPlatform()] ?? $value->getPlatform(),
      ]);
    }
  }

}
