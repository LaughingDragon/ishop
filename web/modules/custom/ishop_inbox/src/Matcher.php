<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ishop_items\Entity\PlatformListing;
use Drupal\ishop_items\Platforms;

/**
 * Finds the listing an email is about (Phase 10 B).
 *
 * First by the listing's ID in the email's links (exact); otherwise by the
 * title's words, only when one listing clearly wins. Never a guess between
 * two close ones: then Cody picks.
 */
final class Matcher {

  /**
   * Share of the email title's words the listing's title must contain.
   */
  public const MIN_SCORE = 0.6;

  /**
   * Words that say nothing about the item.
   */
  private const STOP = ['the', 'and', 'for', 'with', 'new', 'nwt', 'nwot', 'euc', 'size', 'item', 'your', 'of', 'in', 'a', 'an', 'to', 'on'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The listing on $platform an email is about, or NULL.
   *
   * @param string $platform
   *   Platforms::PLATFORMS key.
   * @param string $kind
   *   Classifier kind: sold and offer look at listings not sold yet (and a
   *   sale already marked); the rest look at sales.
   * @param string|null $ref
   *   The listing ID from the email's links.
   * @param string|null $title
   *   The title read from the email.
   */
  public function find(string $platform, string $kind, ?string $ref, ?string $title): ?PlatformListing {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('platform', $platform);
    if (in_array($kind, [Classifier::SOLD, Classifier::OFFER], TRUE)) {
      $query->condition('status', [Platforms::TO_POST, Platforms::POSTED, Platforms::AT_RISK, Platforms::SOLD_HERE], 'IN');
    }
    else {
      $query->condition('status', Platforms::SOLD_HERE);
    }
    $candidates = array_filter($storage->loadMultiple($query->execute()), fn (PlatformListing $l) => $l->getItem() !== NULL);
    if ($ref !== NULL) {
      foreach ($candidates as $listing) {
        if (ListingRef::find($platform, (string) $listing->get('url')->value) === $ref) {
          return $listing;
        }
      }
    }
    if ($title === NULL) {
      return NULL;
    }
    $scores = [];
    foreach ($candidates as $id => $listing) {
      $scores[$id] = self::score($title, $listing->getItem()->label());
    }
    arsort($scores);
    $best = array_key_first($scores);
    if ($best === NULL || $scores[$best] < self::MIN_SCORE) {
      return NULL;
    }
    // A tie (or near tie) is not a match.
    $second = array_values($scores)[1] ?? 0.0;
    return $scores[$best] - $second >= 0.15 ? $candidates[$best] : NULL;
  }

  /**
   * Share of the email title's words found in the item's title (0 to 1).
   *
   * The email's title may be cut short, so only its words are counted.
   */
  public static function score(string $email_title, string $item_title): float {
    $email = self::words($email_title);
    if (!$email) {
      return 0.0;
    }
    $item = self::words($item_title);
    return count(array_intersect($email, $item)) / count($email);
  }

  /**
   * Distinct meaningful words, lower case.
   *
   * @return string[]
   *   Words.
   */
  public static function words(string $title): array {
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 1 && !in_array($w, self::STOP, TRUE))));
  }

}
