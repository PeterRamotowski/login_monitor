<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Resolves a typed username to a user entity and failure event type.
 *
 * Extracted from LoginMonitorService to give user-lookup a single
 * responsibility and make it independently unit-testable with a mock
 * EntityTypeManagerInterface.
 */
final class FailedLoginUserResolver {

  /**
   * Constructs a FailedLoginUserResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves the event type and optionally hydrates login event data.
   *
   * Loads the user matching the typed username (if any), populates the
   * provided LoginEventData context, and returns the LoginEventType that
   * best describes the failure.
   *
   * @param string $typedUsername
   *   The username as typed by the visitor (already normalized).
   * @param \Drupal\login_monitor\Service\LoginEventData $loginEventData
   *   The mutable event-data context to populate when a user is found.
   *
   * @return \Drupal\login_monitor\LoginEventType
   *   The event type that best describes this failure.
   */
  public function resolve(string $typedUsername, LoginEventData $loginEventData): LoginEventType {
    $storage = $this->entityTypeManager->getStorage('user');
    $users = $storage->loadByProperties(['name' => $typedUsername]);

    if (empty($users)) {
      return LoginEventType::FailedLoginInvalidUser;
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = reset($users);
    $loginEventData->setUser($user);

    return $user->isBlocked()
      ? LoginEventType::FailedLoginBlockedUser
      : LoginEventType::FailedLoginValidUser;
  }

}
