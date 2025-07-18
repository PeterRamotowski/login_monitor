<?php

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for handling login event data.
 */
class LoginEventData {

  /**
   * The user for this login event.
   *
   * @var \Drupal\Core\Session\AccountInterface|null
   */
  private ?AccountInterface $user = NULL;

  /**
   * The typed username for this login event.
   *
   * This is used when the user does not exist or is blocked.
   *
   * @var string|null
   */

  private ?string $typedUsername = NULL;

  public function __construct(
    private RequestStack $requestStack,
    private TimeInterface $time,
    private Connection $database,
  ) {}

  /**
   * Set the user for this instance.
   */
  public function setUser(?AccountInterface $user = NULL): void {
    $this->user = $user;
  }

  /**
   * Set the typed username for this instance.
   */
  public function setTypedUsername(?string $typedUsername = NULL): void {
    $this->typedUsername = $typedUsername;
  }

  /**
   * Get the IP address of the user.
   */
  public function getIpAddress(): string {
    return $this->requestStack->getCurrentRequest()->getClientIp();
  }

  /**
   * Get the user agent of the user.
   */
  public function getUserAgent(): ?string {
    return $this->requestStack->getCurrentRequest()->headers->get('User-Agent');
  }

  /**
   * Get the user for this instance.
   */
  public function getUser(): ?AccountInterface {
    return $this->user;
  }

  /**
   * Get the username of the user if available.
   *
   * @return string|null
   *   The username or NULL if no user is set.
   */
  public function getUsername(): ?string {
    return $this->user ? $this->user->getDisplayName() : $this->typedUsername;
  }

  /**
   * Get the typed username.
   *
   * @return string|null
   *   The typed username or NULL if not set.
   */
  public function getTypedUsername(): ?string {
    return $this->typedUsername;
  }

  /**
   * Get the user ID if available.
   */
  public function getUserId(): ?int {
    return $this->user ? $this->user->id() : NULL;
  }

  /**
   * Get the number of active sessions for a user.
   */
  public function getActiveSessions(): int {
    if (!isset($this->user)) {
      return 0;
    }

    $current_time = $this->time->getRequestTime();
    $max_lifetime = (int) ini_get('session.gc_maxlifetime');

    $query = $this->database->select('sessions', 's')
      ->condition('s.uid', $this->user->id())
      ->condition('s.timestamp', $current_time - $max_lifetime, '>=');

    return (int) $query->countQuery()->execute()->fetchField();
  }

}
