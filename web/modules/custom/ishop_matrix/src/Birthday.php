<?php

declare(strict_types=1);

namespace Drupal\ishop_matrix;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ishop_office\OfficeStructure;

/**
 * Birthday mode for Cody's birthday (TEMPORARY: see TEMPORARY.md).
 *
 * On all day on the birthday (Pacific) when the mode is "auto", for everyone
 * when it is "on", never when "off". Webmaster and site-foreman can also
 * preview it for themselves only, so Cody doesn't see it before the day.
 * Settings live in State (not config), so switching never touches git.
 */
final class Birthday {

  /**
   * State key holding date, mode and the previewers' user IDs.
   */
  public const STATE = 'ishop_matrix.birthday';

  /**
   * Cache tag on everything that changes with birthday mode.
   */
  public const CACHE_TAG = 'ishop_birthday';

  /**
   * Accounts that may change the settings and preview: the crew.
   *
   * By account, not permission: Cody's account is an administrator too.
   */
  public const MANAGERS = OfficeStructure::CREW;

  public const MODES = [
    'auto' => 'Automatic: on all day on the birthday (Pacific time)',
    'on' => 'On now, for everyone',
    'off' => 'Off, even on the birthday',
  ];

  /**
   * Where the song lives (private files, not git; delete after the day).
   */
  public const SONG = 'private://birthday/cody-birthday.mp3';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The settings: date ("MM-DD" or ""), mode, preview (user IDs).
   */
  public function settings(): array {
    return ($this->state->get(self::STATE) ?? []) + ['date' => '', 'mode' => 'auto', 'preview' => [], 'launched' => []];
  }

  /**
   * Saves the settings and refreshes every page that shows the mode.
   */
  public function save(array $settings): void {
    $settings['preview'] = array_values(array_intersect(array_map('intval', $settings['preview'] ?? []), self::MANAGERS));
    $this->state->set(self::STATE, $settings);
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Whether the account may change the settings and preview.
   */
  public static function isManager(AccountInterface $account): bool {
    return in_array((int) $account->id(), self::MANAGERS, TRUE);
  }

  /**
   * Whether today (Pacific) is the birthday.
   */
  public function isTheDay(): bool {
    $date = $this->settings()['date'];
    return $date !== '' && $date === $this->today()->format('m-d');
  }

  /**
   * Whether birthday mode is on for this account right now.
   */
  public function isActive(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    $settings = $this->settings();
    if (in_array((int) $account->id(), $settings['preview'], TRUE) && self::isManager($account)) {
      return TRUE;
    }
    return match ($settings['mode']) {
      'on' => TRUE,
      'off' => FALSE,
      default => $this->isTheDay(),
    };
  }

  /**
   * Whether this is the account's first page in birthday mode today.
   *
   * The first time Cody (or a previewer) opens the site on the day, the party
   * launches by itself (Liam, 2026-10-15). Calling this records the launch,
   * so it happens once per account per day, on whichever device is first.
   */
  public function claimLaunch(AccountInterface $account): bool {
    if (!$this->isActive($account)) {
      return FALSE;
    }
    $settings = $this->settings();
    $uid = (int) $account->id();
    $today = $this->today()->format('Y-m-d');
    if (($settings['launched'][$uid] ?? NULL) === $today) {
      return FALSE;
    }
    $settings['launched'][$uid] = $today;
    $this->state->set(self::STATE, $settings);
    return TRUE;
  }

  /**
   * Lets an account see the first-open launch again (for previewing).
   */
  public function resetLaunch(AccountInterface $account): void {
    $settings = $this->settings();
    unset($settings['launched'][(int) $account->id()]);
    $this->state->set(self::STATE, $settings);
  }

  /**
   * Cache metadata for anything that depends on the mode.
   *
   * Varies by user (previews) and expires at the next Pacific midnight.
   */
  public function cacheability(): array {
    $midnight = $this->today()->modify('tomorrow')->getTimestamp();
    return [
      'contexts' => ['user'],
      'tags' => [self::CACHE_TAG],
      'max-age' => max($midnight - $this->time->getCurrentTime(), 60),
    ];
  }

  /**
   * Now, in the site's (Pacific) time zone.
   */
  private function today(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new \DateTimeZone('America/Los_Angeles'));
  }

}
