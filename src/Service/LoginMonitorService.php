<?php

namespace Drupal\login_monitor\Service;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for validating if a user should be monitored.
 */
class LoginMonitorService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private ImmutableConfig $settings,
    private LoginEventData $loginEventData,
    private LoginLogService $loginLogService,
    private LoginNotificationService $notificationService,
  ) {}

  /**
   * Process a successful login event.
   */
  public function processSuccess(AccountInterface $user, LoginEventType $eventType): void {
    if (!$this->shouldMonitorUser($user)) {
      return;
    }

    $this->loginEventData->setUser($user);

    $this->loginLogService->saveLog($eventType, $this->loginEventData);
    $this->notificationService->sendEmailNotification($eventType, $this->loginEventData);
  }

  /**
   * Process a failed login event.
   */
  public function processFailure(string $typedUsername): void {

    $userExists = FALSE;
    $userBlocked = FALSE;

    $userStorage = $this->entityTypeManager->getStorage('user');
    $users = $userStorage->loadByProperties(['name' => $typedUsername]);
    $userExists = !empty($users);
    if ($userExists) {
      /** @var \Drupal\user\UserInterface $user */
      $user = reset($users);

      if (!$this->shouldMonitorUser($user)) {
        return;
      }

      $userBlocked = $user->isBlocked();
      $this->loginEventData->setUser($user);
    }

    $this->loginEventData->setTypedUsername($typedUsername);

    if ($userBlocked) {
      $eventType = LoginEventType::FailedLoginBlockedUser;
    }
    elseif ($userExists) {
      $eventType = LoginEventType::FailedLoginValidUser;
    }
    else {
      $eventType = LoginEventType::FailedLoginInvalidUser;
    }

    $this->loginLogService->saveLog($eventType, $this->loginEventData);
    $this->notificationService->sendEmailNotification($eventType, $this->loginEventData);
  }

  /**
   * Check if a user should be monitored based on configured roles.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to check.
   *
   * @return bool
   *   TRUE if the user should be monitored, FALSE otherwise.
   */
  public function shouldMonitorUser(AccountInterface $user): bool {
    $trackedRoles = $this->settings->get('tracked_roles') ?? [];

    // If no specific roles are configured, monitor all users.
    if (empty($trackedRoles)) {
      return TRUE;
    }

    // Check if the user has any of the roles enabled for monitoring.
    $userRoles = $user->getRoles();
    return !empty(array_intersect($userRoles, $trackedRoles));
  }

}
