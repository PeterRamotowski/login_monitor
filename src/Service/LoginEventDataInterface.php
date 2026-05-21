<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

/**
 * Provides read access to login event context data.
 *
 * Implemented by both the live LoginEventData service (which reads from the
 * current HTTP request) and the immutable LoginNotificationPayload snapshot
 * (used for delayed, queue-based delivery).
 */
interface LoginEventDataInterface {

  /**
   * Gets the IP address associated with the login event.
   */
  public function getIpAddress(): string;

  /**
   * Gets the user agent string associated with the login event.
   */
  public function getUserAgent(): ?string;

  /**
   * Gets the display name of the user involved in the login event.
   */
  public function getUsername(): ?string;

  /**
   * Gets the user ID, if available.
   */
  public function getUserId(): ?int;

}
