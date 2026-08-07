<?php

declare(strict_types=1);

namespace Drupal\login_monitor;

use Drupal\Component\Utility\Unicode;

/**
 * Defines shared Login Monitor limits.
 */
final class LoginMonitorLimits {

  /**
   * The maximum number of login log entities deleted in one batch.
   */
  public const DELETE_BATCH_SIZE = 100;

  /**
   * The maximum stored user agent length.
   */
  public const USER_AGENT_MAX_LENGTH = 512;

  /**
   * The maximum stored username length.
   */
  public const USERNAME_MAX_LENGTH = 255;

  /**
   * Prevents constructing this utility class.
   */
  private function __construct() {}

  /**
   * Trims and truncates a raw username to the stored maximum length.
   *
   * @param string $username
   *   The raw username string.
   *
   * @return string
   *   The normalized username, or an empty string when blank after trimming.
   */
  public static function normalizeUsername(string $username): string {
    return Unicode::truncate(
      trim($username),
      self::USERNAME_MAX_LENGTH,
      TRUE,
      FALSE,
    );
  }

}
