<?php

declare(strict_types=1);

namespace Drupal\login_monitor;

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

}
