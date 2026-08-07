<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\LoginMonitorLimits;

/**
 * Orchestrates login event recording and notification dispatching.
 */
final class LoginMonitorService {

  /**
   * Constructs a LoginMonitorService.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoginEventData $loginEventData,
    private readonly LoginLogService $loginLogService,
    private readonly LoginNotificationDispatcher $dispatcher,
    private readonly FailedLoginUserResolver $userResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Processes a successful login or logout event.
   */
  public function processSuccess(AccountInterface $user, LoginEventType $eventType): void {
    if (!$this->shouldMonitorUser($user)) {
      return;
    }

    $this->loginEventData->reset();
    $this->loginEventData->setUser($user);
    $this->handleEvent($eventType);
  }

  /**
   * Processes a failed login attempt.
   */
  public function processFailure(string $typedUsername): void {
    $typedUsername = LoginMonitorLimits::normalizeUsername($typedUsername);
    if ($typedUsername === '') {
      return;
    }

    $this->loginEventData->reset();
    $this->loginEventData->setTypedUsername($typedUsername);

    $eventType = $this->userResolver->resolve($typedUsername, $this->loginEventData);

    if (!$this->shouldMonitorUser($this->loginEventData->getUser())) {
      return;
    }

    $this->handleEvent($eventType);
  }

  /**
   * Returns TRUE when the user belongs to a monitored role.
   *
   * When no roles are configured, all users are monitored. A NULL user is
   * treated as unresolved (no matching account) and is always monitored.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $user
   *   The user to check, or NULL for an unresolved username.
   *
   * @return bool
   *   TRUE when the user should be monitored.
   */
  public function shouldMonitorUser(?AccountInterface $user): bool {
    $trackedRoles = $this->configFactory
      ->get('login_monitor.settings')
      ->get('tracked_roles') ?? [];

    if (empty($trackedRoles) || $user === NULL) {
      return TRUE;
    }

    return !empty(array_intersect($user->getRoles(), $trackedRoles));
  }

  /**
   * Persists the event and dispatches the notification.
   */
  private function handleEvent(LoginEventType $eventType): void {
    try {
      $this->loginLogService->saveLog($eventType, $this->loginEventData);
    }
    catch (\Throwable $e) {
      $this->logger->error('Login monitor failed to save @event: @message', [
        '@event' => $eventType->value,
        '@message' => $e->getMessage(),
      ]);
    }

    try {
      $this->dispatcher->dispatch($eventType, $this->loginEventData);
    }
    catch (\Throwable $e) {
      $this->logger->error('Login monitor failed to notify for @event: @message', [
        '@event' => $eventType->value,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
