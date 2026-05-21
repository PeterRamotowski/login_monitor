<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Provides an interface for defining Login Log entities.
 *
 * @ingroup login_monitor
 */
interface LoginLogInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Gets the user entity.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity or null if not set.
   */
  public function getUser(): ?UserInterface;

  /**
   * Sets the user entity.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return $this
   */
  public function setUser(UserInterface $user);

  /**
   * Gets the created timestamp.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the created timestamp.
   *
   * @param int $timestamp
   *   The created timestamp.
   *
   * @return $this
   */
  public function setCreatedTime(int $timestamp);

  /**
   * Gets the number of concurrent sessions.
   *
   * @return int
   *   The number of concurrent sessions.
   */
  public function getConcurrentSessions(): int;

  /**
   * Sets the number of concurrent sessions.
   *
   * @param int $sessions
   *   The number of concurrent sessions.
   *
   * @return $this
   */
  public function setConcurrentSessions(int $sessions);

  /**
   * Gets the IP address.
   *
   * @return string|null
   *   The IP address.
   */
  public function getIpAddress(): ?string;

  /**
   * Sets the IP address.
   *
   * @param string $ip_address
   *   The IP address.
   *
   * @return $this
   */
  public function setIpAddress(?string $ip_address);

  /**
   * Gets the user agent.
   *
   * @return string|null
   *   The user agent string.
   */
  public function getUserAgent(): ?string;

  /**
   * Sets the user agent.
   *
   * @param string $user_agent
   *   The user agent string.
   *
   * @return $this
   */
  public function setUserAgent(?string $user_agent);

  /**
   * Gets the event type.
   *
   * @return string|null
   *   The event type.
   */
  public function getEventType(): ?string;

  /**
   * Sets the event type.
   *
   * @param string $event_type
   *   The event type.
   *
   * @return $this
   */
  public function setEventType(?string $event_type);

  /**
   * Gets the typed username.
   *
   * @return string|null
   *   The typed username.
   */
  public function getTypedUsername(): ?string;

  /**
   * Sets the typed username.
   *
   * @param string $typed_username
   *   The typed username.
   *
   * @return $this
   */
  public function setTypedUsername(?string $typed_username);

}
