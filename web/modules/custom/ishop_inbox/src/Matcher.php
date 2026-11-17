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
   *   Classifier kind: sold, offer, label and message look at listings not
   *   sold yet (and a sale already marked); the rest look at sales.
   * @param string|null $ref
   *   The listing ID from the email's links.
   * @param string|null $title
   *   The title read from the email.
   */
  public function find(string $platform, string $kind, ?string $ref, ?string $title): ?PlatformListing {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('platform', $platform);
    // A label or a message can come before Cody marks the sale, so they look
    // at unsold listings too.
    if (in_array($kind, [Classifier::SOLD, Classifier::OFFER, Classifier::LABEL, Classifier::MESSAGE], TRUE)) {
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
   * Score an email title needs for a certain match (with no other item close).
   */
  public const CERTAIN_SCORE = 0.9;

  /**
   * The listing on $platform an email is certainly about, or NULL and why.
   *
   * Certain: the listing's own ID is in the email's links; or the title
   * matches exactly one item on the whole site (score >= CERTAIN_SCORE and
   * every other item below MIN_SCORE) and that item has a listing on
   * $platform. Statuses and prices are checked by the caller.
   *
   * @return array{0: ?PlatformListing, 1: string}
   *   The listing (or NULL) and a short reason.
   */
  public function certain(string $platform, ?string $ref, ?string $title): array {
    $storage = $this->entityTypeManager->getStorage('platform_listing');
    if ($ref !== NULL) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('platform', $platform)->condition('url', '%' . $ref . '%', 'LIKE')->execute();
      foreach ($storage->loadMultiple($ids) as $listing) {
        if ($listing->getItem() && ListingRef::find($platform, (string) $listing->get('url')->value) === $ref) {
          return [$listing, 'listing link'];
        }
      }
    }
    if ($title === NULL) {
      return [NULL, 'no title in the email'];
    }
    $titles = \Drupal::database()->select('node_field_data', 'n')->fields('n', ['nid', 'title'])->condition('type', 'item')->execute()->fetchAllKeyed();
    $close = [];
    foreach ($titles as $nid => $item_title) {
      $score = self::score($title, (string) $item_title);
      if ($score >= self::MIN_SCORE) {
        $close[$nid] = $score;
      }
    }
    if (count($close) !== 1) {
      return [NULL, $close ? count($close) . ' items match the title' : 'no item matches the title'];
    }
    $nid = (int) array_key_first($close);
    if ($close[$nid] < self::CERTAIN_SCORE) {
      return [NULL, 'title only partly matches'];
    }
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('item', $nid)->condition('platform', $platform)->execute();
    $listing = $ids ? $storage->load(reset($ids)) : NULL;
    return $listing instanceof PlatformListing ? [$listing, 'title'] : [NULL, 'the item has no listing on this site'];
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
