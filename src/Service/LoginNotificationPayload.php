<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

/**
 * Immutable snapshot of login event data for queued notification delivery.
 *
 * Captures request-time context (IP address, user agent) at the moment a
 * login event occurs so it can be safely processed later by a cron queue
 * worker, independent of the originating HTTP request.
 */
final readonly class LoginNotificationPayload implements LoginEventDataInterface {

  /**
   * Constructs a login notification payload.
   *
   * @param string $ipAddress
   *   The IP address of the login attempt.
   * @param string|null $userAgent
   *   The user agent string of the browser used.
   * @param string|null $username
   *   The display name of the user involved in the login event.
   * @param int|null $userId
   *   The user ID, if available.
   */
  public function __construct(
    private string $ipAddress,
    private ?string $userAgent,
    private ?string $username,
    private ?int $userId,
  ) {}

  /**
   * Creates a payload snapshot from a LoginEventData service instance.
   *
   * @param \Drupal\login_monitor\Service\LoginEventData $loginEventData
   *   The live login event data to snapshot.
   */
  public static function fromLoginEventData(LoginEventData $loginEventData): self {
    return new self(
      ipAddress: $loginEventData->getIpAddress(),
      userAgent: $loginEventData->getUserAgent(),
      username: $loginEventData->getUsername(),
      userId: $loginEventData->getUserId(),
    );
  }

  /**
   * Gets the IP address of the login attempt.
   */
  public function getIpAddress(): string {
    return $this->ipAddress;
  }

  /**
   * Gets the user agent string.
   */
  public function getUserAgent(): ?string {
    return $this->userAgent;
  }

  /**
   * Gets the display name of the user involved in the event.
   */
  public function getUsername(): ?string {
    return $this->username;
  }

  /**
   * Gets the user ID, if available.
   */
  public function getUserId(): ?int {
    return $this->userId;
  }

}
