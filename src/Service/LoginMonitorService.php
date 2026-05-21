<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\LoginMonitorLimits;

/**
 * Service for validating if a user should be monitored.
 */
final class LoginMonitorService {

  /**
   * The configuration factory.
   */
  private readonly ?ConfigFactoryInterface $configFactory;

  /**
   * The fallback settings snapshot from an old compiled container.
   */
  private readonly ?ImmutableConfig $settings;

  /**
   * Constructs a login monitor service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface|ImmutableConfig $configFactoryOrSettings,
    private readonly LoginEventData $loginEventData,
    private readonly LoginLogService $loginLogService,
    private readonly LoginNotificationService $notificationService,
    private readonly ?LoggerChannelInterface $logger = NULL,
    private readonly ?QueueFactory $queueFactory = NULL,
  ) {
    $this->configFactory = $configFactoryOrSettings instanceof ConfigFactoryInterface
      ? $configFactoryOrSettings
      : NULL;
    $this->settings = $configFactoryOrSettings instanceof ImmutableConfig
      ? $configFactoryOrSettings
      : NULL;
  }

  /**
   * Process a successful login event.
   */
  public function processSuccess(AccountInterface $user, LoginEventType $eventType): void {
    if (!$this->shouldMonitorUser($user)) {
      return;
    }

    $this->loginEventData->reset();
    $this->loginEventData->setUser($user);

    $this->handleEvent($eventType, $this->loginEventData);
  }

  /**
   * Process a failed login event.
   */
  public function processFailure(string $typedUsername): void {
    $typedUsername = $this->normalizeUsername($typedUsername);
    if ($typedUsername === '') {
      return;
    }

    $userExists = FALSE;
    $userBlocked = FALSE;
    $this->loginEventData->reset();

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

    $this->handleEvent($eventType, $this->loginEventData);
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
    $trackedRoles = $this->getSettings()->get('tracked_roles') ?? [];

    // If no specific roles are configured, monitor all users.
    if (empty($trackedRoles)) {
      return TRUE;
    }

    // Check if the user has any of the roles enabled for monitoring.
    $userRoles = $user->getRoles();
    return !empty(array_intersect($userRoles, $trackedRoles));
  }

  /**
   * Gets settings from the current or transitional container shape.
   */
  private function getSettings(): ImmutableConfig {
    if ($this->configFactory) {
      return $this->configFactory->get('login_monitor.settings');
    }

    if ($this->settings) {
      return $this->settings;
    }

    throw new \LogicException('Login Monitor settings are unavailable.');
  }

  /**
   * Handles persistence and notification side effects for an event.
   */
  private function handleEvent(LoginEventType $eventType, LoginEventData $loginEventData): void {
    try {
      $this->loginLogService->saveLog($eventType, $loginEventData);
    }
    catch (\Throwable $exception) {
      $this->logger?->error('Login monitor failed to save @event: @message', [
        '@event' => $eventType->value,
        '@message' => $exception->getMessage(),
      ]);
    }

    $deliveryMode = $this->getSettings()->get('notification_delivery') ?? 'immediate';

    if ($deliveryMode === 'delayed' && $this->queueFactory !== NULL) {
      $this->enqueueNotification($eventType, $loginEventData);
      return;
    }

    try {
      $this->notificationService->sendEmailNotification($eventType, $loginEventData);
    }
    catch (\Throwable $exception) {
      $this->logger?->error('Login monitor failed to notify for @event: @message', [
        '@event' => $eventType->value,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Pushes a notification snapshot onto the cron queue for delayed delivery.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The login event type.
   * @param \Drupal\login_monitor\Service\LoginEventData $loginEventData
   *   The live login event data to snapshot.
   */
  private function enqueueNotification(LoginEventType $eventType, LoginEventData $loginEventData): void {
    $queue = $this->queueFactory->get('login_monitor_notifications');
    $queue->createItem([
      'event_type' => $eventType,
      'payload' => LoginNotificationPayload::fromLoginEventData($loginEventData),
    ]);
  }

  /**
   * Normalizes a typed username before lookup, logging, or notification.
   */
  private function normalizeUsername(string $typedUsername): string {
    return Unicode::truncate(trim($typedUsername), LoginMonitorLimits::USERNAME_MAX_LENGTH, TRUE, FALSE);
  }

}
