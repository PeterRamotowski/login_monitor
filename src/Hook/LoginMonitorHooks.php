<?php

namespace Drupal\login_monitor\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\Service\LoginEventData;
use Drupal\login_monitor\Service\LoginLogService;
use Drupal\login_monitor\Service\LoginMonitorService;
use Drupal\login_monitor\Service\LoginNotificationService;
use Drupal\login_monitor\Service\LoginReportService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides hooks for the Login Monitor module.
 */
class LoginMonitorHooks {

  use StringTranslationTrait;

  public function __construct(
    private EventDispatcherInterface $eventDispatcher,
    private ConfigFactoryInterface $configFactory,
    private EntityTypeManagerInterface $entityTypeManager,
    private RequestStack $requestStack,
    #[Autowire(service: 'current_route_match')]
    private RouteMatchInterface $routeMatch,
    #[Autowire(service: 'login_monitor.login_monitor_service')]
    private LoginMonitorService $loginMonitorService,
    #[Autowire(service: 'login_monitor.login_log_service')]
    private LoginLogService $loginLogService,
    #[Autowire(service: 'login_monitor.notification_service')]
    private LoginNotificationService $notificationService,
    #[Autowire(service: 'login_monitor.report_service')]
    private LoginReportService $reportService,
    #[Autowire(service: 'logger.channel.login_monitor')]
    private LoggerChannelInterface $logger,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $routeName, RouteMatchInterface $routeMatch): string {
    $output = '';
    switch ($routeName) {
      case 'help.page.login_monitor':
        $output .= '<h2>' . $this->t('About') . '</h2>';
        $output .= '<p>' . $this->t('The Login Monitor module provides comprehensive tracking and monitoring of user login activities on your Drupal site. It logs successful logins, failed login attempts, and logout events, while offering configurable email notifications and periodic statistical reports to help administrators monitor site security and user activity patterns.') . '</p>';

        $output .= '<h3>' . $this->t('Features') . '</h3>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('<strong>Login Activity Logging:</strong> Tracks all login events including successful logins, failed attempts, one-time login links, and logout events.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Email Notifications:</strong> Send real-time email notifications for login events with customizable templates and token support.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Statistical Reports:</strong> Generate and send periodic email reports (daily, weekly, monthly) with login activity summaries.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Role-based Filtering:</strong> Configure which user roles should be monitored for login activities.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Data Management:</strong> Automatic cleanup of old log entries with configurable retention periods.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Security Monitoring:</strong> Track failed login attempts to identify potential security threats.') . '</li>';
        $output .= '</ul>';

        $output .= '<h3>' . $this->t('Configuration') . '</h3>';
        $output .= '<p>' . $this->t('To configure the Login Monitor module:') . '</p>';
        $output .= '<ol>';
        $output .= '<li>' . $this->t('Go to <a href=":config_url">Administration » Configuration » People » Login monitor settings</a> to configure the module settings.', [':config_url' => '/admin/config/people/login-monitor']) . '</li>';
        $output .= '<li>' . $this->t('Set up permissions at <a href=":permissions_url">Administration » People » Permissions</a> to control who can view and manage login logs.', [':permissions_url' => '/admin/people/permissions']) . '</li>';
        $output .= '<li>' . $this->t('View login logs at <a href=":logs_url">Administration » Reports » Login Log</a>.', [':logs_url' => '/admin/reports/logins']) . '</li>';
        $output .= '</ol>';

        $output .= '<h3>' . $this->t('Login Event Types') . '</h3>';
        $output .= '<p>' . $this->t('The module tracks the following types of login events:') . '</p>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('<strong>Successful Login:</strong> Regular user login through the login form.') . '</li>';
        $output .= '<li>' . $this->t('<strong>One-time Login:</strong> Login using password reset or one-time login links.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Failed Login (Invalid User):</strong> Login attempt with non-existent username.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Failed Login (Valid User):</strong> Login attempt with correct username but wrong password.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Failed Login (Blocked User):</strong> Login attempt by a blocked user account.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Logout:</strong> User logout events.') . '</li>';
        $output .= '</ul>';

        $output .= '<h3>' . $this->t('Token Support') . '</h3>';
        $output .= '<p>' . $this->t('The module provides the following tokens for use in email templates:') . '</p>';
        $output .= '<ul>';
        $output .= '<li><code>[login_monitor:event_type]</code> - ' . $this->t('The type of login event') . '</li>';
        $output .= '<li><code>[login_monitor:event_type_label]</code> - ' . $this->t('Human-readable label for the event type') . '</li>';
        $output .= '<li><code>[login_monitor:username]</code> - ' . $this->t('Username involved in the event') . '</li>';
        $output .= '<li><code>[login_monitor:ip_address]</code> - ' . $this->t('IP address of the login attempt') . '</li>';
        $output .= '<li><code>[login_monitor:user_agent]</code> - ' . $this->t('Browser user agent string') . '</li>';
        $output .= '</ul>';

        $output .= '<h3>' . $this->t('Drush Commands') . '</h3>';
        $output .= '<p>' . $this->t('The module provides the following Drush command:') . '</p>';
        $output .= '<ul>';
        $output .= '<li><code>drush login-monitor:send-reports</code> - ' . $this->t('Manually trigger sending of statistical reports') . '</li>';
        $output .= '</ul>';

        $output .= '<h3>' . $this->t('Permissions') . '</h3>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('<strong>Administer Login Monitor settings:</strong> Allows users to configure module settings.') . '</li>';
        $output .= '<li>' . $this->t('<strong>View login log entities:</strong> Allows users to view login logs in the administrative interface.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Administer login log entities:</strong> Allows full access to login log entities including deletion.') . '</li>';
        $output .= '</ul>';
        break;

      case 'login_monitor.settings':
        $output .= '<p>' . $this->t('Configure Login Monitor settings to control how login events are tracked and reported.') . '</p>';
        $output .= '<p>' . $this->t('Use the "Tracked user roles" setting to monitor specific user roles only, or leave empty to monitor all users. Email notifications can be customized using tokens to include relevant event information.') . '</p>';
        break;

      case 'entity.login_log.collection':
        $output .= '<p>' . $this->t('This page displays all recorded login events including successful logins, failed attempts, and logout events.') . '</p>';
        $output .= '<p>' . $this->t('Use the filters to search for specific events, users, or time periods. The data can help identify security issues such as repeated failed login attempts or unusual login patterns.') . '</p>';
        break;
    }
    return $output;
  }

  /**
   * Implements hook_user_login().
   */
  #[Hook('user_login')]
  public function userLogin(UserInterface $user): void {
    // Determine if this is a one-time login.
    $eventType = $this->isOneTimeLogin() ? LoginEventType::SuccessLoginOnetime : LoginEventType::SuccessLogin;

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
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $formState, string $form_id): void {
    if (in_array($form_id, ['user_login_form', 'user_login_block'])) {
      array_unshift($form['#validate'], [$this, 'validateLoginAttempt']);
      $form['#validate'][] = [$this, 'postValidateLoginAttempt'];
    }
  }

  /**
   * Pre-validation handler for login forms.
   *
   * Stores the attempted username for potential failed login logging.
   */
  public function validateLoginAttempt(array &$form, FormStateInterface $formState): void {
    $username = $formState->getValue('name');
    if (!empty($username)) {
      $formState->set('login_monitor_username', $username);
    }
  }

  /**
   * Post-validation handler for login forms.
   *
   * Logs failed login attempts if validation failed.
   */
  public function postValidateLoginAttempt(array &$form, FormStateInterface $formState): void {
    $username = $formState->get('login_monitor_username');

    if ($formState->hasAnyErrors() && !empty($username)) {
      $this->loginMonitorService->processFailure($username);
    }
  }

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    switch ($key) {
      case 'login_notify':
      case 'login_report':
        $siteConfig = $this->configFactory->get('system.site');
        $siteName = $siteConfig->get('name');
        $siteMail = $siteConfig->get('mail');
        $message['headers']['From'] = $siteName . '<' . $siteMail . '>';
        $message['headers']['Return-Path'] = $siteMail;
        $message['headers']['Sender'] = $siteMail;
        $message['headers']['Reply-to'] = $siteMail;
        $message['subject'] = $params['subject'];
        $message['body'] = $params['body'];
        break;
    }
  }

  /**
   * Implements hook_user_delete().
   *
   * Removes all login logs for the deleted user.
   */
  #[Hook('user_delete')]
  public function userDelete(UserInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('login_log');
    $query = $storage->getQuery()
      ->condition('uid', $account->id())
      ->accessCheck(FALSE);
    $loginLogIds = $query->execute();

    if (!empty($loginLogIds)) {
      $loginLogs = $storage->loadMultiple($loginLogIds);
      $storage->delete($loginLogs);

      $this->logger->info(
        $this->t('Deleted @count login log entries for user @user (@uid).', [
          '@count' => count($loginLogIds),
          '@user' => $account->getAccountName(),
          '@uid' => $account->id(),
        ])
      );
    }
  }

  /**
   * Implements hook_cron().
   *
   * Automatically deletes old login logs and processes email reports.
   */
  #[Hook('cron')]
  public function cron(): void {
    $settings = $this->configFactory->get('login_monitor.settings');

    // Process log cleanup.
    $enableCleanup = $settings->get('enable_log_cleanup');
    if ($enableCleanup) {
      $retentionDays = (int) $settings->get('log_retention_days');
      if ($retentionDays > 0) {
        $deletedCount = $this->loginLogService->deleteOldLogs($retentionDays);

        if ($deletedCount > 0) {
          $this->logger->info(
            $this->t('Deleted @count old login log entries (older than @days days).', [
              '@count' => $deletedCount,
              '@days' => $retentionDays,
            ])
          );
        }
      }
    }

    // Process email reports.
    $this->reportService->processScheduledReports();
  }

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $type = [
      'name' => $this->t('Login Monitor'),
      'description' => $this->t('Tokens for login monitoring events.'),
    ];

    $tokens['event_type'] = [
      'name' => $this->t('Event Type'),
      'description' => $this->t('The type of login event (e.g., successful login, failed login).'),
    ];

    $tokens['event_type_label'] = [
      'name' => $this->t('Event Type Label'),
      'description' => $this->t('The human-readable label for the login event type.'),
    ];

    $tokens['username'] = [
      'name' => $this->t('Username'),
      'description' => $this->t('The username of the user involved in the login event.'),
    ];

    $tokens['ip_address'] = [
      'name' => $this->t('IP Address'),
      'description' => $this->t('The IP address from which the login attempt was made.'),
    ];

    $tokens['user_agent'] = [
      'name' => $this->t('User Agent'),
      'description' => $this->t('The user agent string of the browser used for the login attempt.'),
    ];

    return [
      'types' => ['login_monitor' => $type],
      'tokens' => ['login_monitor' => $tokens],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleableMetadata): array {
    $replacements = [];

    if ($type == 'login_monitor' && !empty($data['login_monitor'])) {
      $eventType = $data['login_monitor']['event_type'] ?? NULL;
      $loginEventData = $data['login_monitor']['login_event_data'] ?? NULL;

      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'event_type':
            if ($eventType instanceof LoginEventType) {
              $replacements[$original] = $eventType->value;
            }
            break;

          case 'event_type_label':
            if ($eventType instanceof LoginEventType) {
              $replacements[$original] = $eventType->getLabel();
            }
            break;

          case 'username':
            if ($loginEventData instanceof LoginEventData) {
              $replacements[$original] = $loginEventData->getUsername();
            }
            break;

          case 'ip_address':
            if ($loginEventData instanceof LoginEventData) {
              $replacements[$original] = $loginEventData->getIpAddress();
            }
            break;

          case 'user_agent':
            if ($loginEventData instanceof LoginEventData) {
              $replacements[$original] = $loginEventData->getUserAgent();
            }
            break;
        }
      }
    }

    return $replacements;
  }

  /**
   * Determines if the current login is a one-time login.
   *
   * @return bool
   *   TRUE if this is a one-time login, FALSE otherwise.
   */
  private function isOneTimeLogin(): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    // Check if the current route is a user reset route.
    $routeName = $this->routeMatch->getRouteName();
    if ($routeName === 'user.reset.login') {
      return TRUE;
    }

    // Check for one-time login URL patterns.
    $uri = $request->getRequestUri();

    // Pattern: /user/reset/{uid}/{timestamp}/{hash}.
    if (preg_match('/\/user\/reset\/\d+\/\d+\/[a-zA-Z0-9_-]+/', $uri)) {
      return TRUE;
    }

    // Additional check for query parameters that might indicate
    // a one-time login.
    if ($request->query->has('pass-reset-token') ||
        $request->query->has('hash') ||
        ($request->query->has('uid') && $request->query->has('timestamp'))) {
      return TRUE;
    }

    return FALSE;
  }

}
