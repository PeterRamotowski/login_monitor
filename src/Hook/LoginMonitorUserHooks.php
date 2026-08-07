<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\Service\LoginLogService;
use Drupal\login_monitor\Service\LoginMonitorService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides user lifecycle hooks for Login Monitor.
 */
final class LoginMonitorUserHooks {

  /**
   * Constructs the login monitor user hook service.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: 'login_monitor.login_monitor_service')]
    private readonly LoginMonitorService $loginMonitorService,
    #[Autowire(service: 'logger.channel.login_monitor')]
    private readonly LoggerChannelInterface $logger,
    #[Autowire(service: 'login_monitor.login_log_service')]
    private readonly LoginLogService $loginLogService,
  ) {}

  /**
   * Implements hook_user_login().
   */
  #[Hook('user_login')]
  public function userLogin(UserInterface $user): void {
    $eventType = $this->isOneTimeLogin()
      ? LoginEventType::SuccessLoginOnetime
      : LoginEventType::SuccessLogin;

    $this->loginMonitorService->processSuccess($user, $eventType);
  }

  /**
   * Implements hook_user_logout().
   */
  #[Hook('user_logout')]
  public function userLogout(AccountInterface $user): void {
    $this->loginMonitorService->processSuccess($user, LoginEventType::Logout);
  }

  /**
   * Implements hook_user_delete().
   *
   * Removes all login logs for the deleted user.
   */
  #[Hook('user_delete')]
  public function userDelete(UserInterface $account): void {
    $deletedCount = $this->loginLogService->deleteByUserId((int) $account->id());

    if ($deletedCount > 0) {
      $this->logger->info(
        'Deleted @count login log entries for user @user (@uid).',
        [
          '@count' => $deletedCount,
          '@user' => $account->getAccountName(),
          '@uid' => $account->id(),
        ],
      );
    }
  }

  /**
   * Determines if the current login is a one-time login.
   */
  private function isOneTimeLogin(): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    $routeName = $this->routeMatch->getRouteName();
    if ($routeName === 'user.reset.login') {
      return TRUE;
    }

    $uri = $request->getRequestUri();
    if (preg_match('/\/user\/reset\/\d+\/\d+\/[a-zA-Z0-9_-]+/', $uri)) {
      return TRUE;
    }

    if ($request->query->has('pass-reset-token') ||
        $request->query->has('hash') ||
        ($request->query->has('uid') && $request->query->has('timestamp'))) {
      return TRUE;
    }

    return FALSE;
  }

}
