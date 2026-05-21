<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Hook;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\login_monitor\Service\LoginLogService;
use Drupal\login_monitor\Service\LoginReportService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides miscellaneous hooks for the Login Monitor module.
 */
final class LoginMonitorHooks {

  use StringTranslationTrait;

  /**
   * Constructs the login monitor hook service.
   */
  public function __construct(
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'login_monitor.login_log_service')]
    private readonly LoginLogService $loginLogService,
    #[Autowire(service: 'login_monitor.report_service')]
    private readonly LoginReportService $reportService,
    #[Autowire(service: 'logger.channel.login_monitor')]
    private readonly LoggerChannelInterface $logger,
    #[Autowire(service: 'email.validator')]
    private readonly ?EmailValidatorInterface $emailValidator = NULL,
    #[Autowire(service: 'renderer')]
    private readonly ?RendererInterface $renderer = NULL,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $routeName, RouteMatchInterface $routeMatch): string {
    return match ($routeName) {
      'help.page.login_monitor' => $this->renderHelpPage(),
      'login_monitor.settings' => $this->renderParagraphs([
        $this->t('Configure Login Monitor settings to control how login events are tracked and reported.'),
        $this->t('Use the "Tracked user roles" setting to monitor specific user roles only, or leave empty to monitor all users. Email notifications can be customized using tokens to include relevant event information.'),
      ]),
      'entity.login_log.collection' => $this->renderParagraphs([
        $this->t('This page displays all recorded login events including successful logins, failed attempts, and logout events.'),
        $this->t('Use the filters to search for specific events, users, or time periods. The data can help identify security issues such as repeated failed login attempts or unusual login patterns.'),
      ]),
      default => '',
    };
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
        $siteName = PlainTextOutput::renderFromHtml((string) $siteConfig->get('name'));
        $siteName = trim(preg_replace('/[\r\n\t]+/', ' ', $siteName) ?? '');
        $siteMail = str_replace(["\r", "\n"], '', (string) $siteConfig->get('mail'));

        if ($this->isValidEmail($siteMail)) {
          $displayName = '"' . addcslashes($siteName, '"\\') . '"';
          $message['headers']['From'] = $displayName . ' <' . $siteMail . '>';
          $message['headers']['Return-Path'] = $siteMail;
          $message['headers']['Sender'] = $siteMail;
          $message['headers']['Reply-to'] = $siteMail;
        }
        $message['subject'] = $params['subject'];
        $message['body'] = $params['body'];
        break;
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

    $enableCleanup = $settings->get('enable_log_cleanup');
    if ($enableCleanup) {
      $retentionDays = (int) $settings->get('log_retention_days');
      if ($retentionDays > 0) {
        $deletedCount = $this->loginLogService->deleteOldLogs($retentionDays);

        if ($deletedCount > 0) {
          $this->logger->info(
            'Deleted @count old login log entries (older than @days days).',
            [
              '@count' => $deletedCount,
              '@days' => $retentionDays,
            ],
          );
        }
      }
    }

    $this->reportService->processScheduledReports();
  }

  /**
   * Builds the main module help page.
   */
  private function renderHelpPage(): string {
    $build = [
      'about_title' => $this->buildHeading('h2', $this->t('About')),
      'about_text' => $this->buildParagraph($this->t('The Login Monitor module provides comprehensive tracking and monitoring of user login activities on your Drupal site. It logs successful logins, failed login attempts, and logout events, while offering configurable email notifications and periodic statistical reports to help administrators monitor site security and user activity patterns.')),
      'features_title' => $this->buildHeading('h3', $this->t('Features')),
      'features' => $this->buildItemList([
        $this->t('Login Activity Logging: Tracks all login events including successful logins, failed attempts, one-time login links, and logout events.'),
        $this->t('Email Notifications: Send real-time email notifications for login events with customizable templates and token support.'),
        $this->t('Statistical Reports: Generate and send periodic email reports with login activity summaries.'),
        $this->t('Role-based Filtering: Configure which user roles should be monitored for login activities.'),
        $this->t('Data Management: Automatic cleanup of old log entries with configurable retention periods.'),
        $this->t('Security Monitoring: Track failed login attempts to identify potential security threats.'),
      ]),
      'configuration_title' => $this->buildHeading('h3', $this->t('Configuration')),
      'configuration_text' => $this->buildParagraph($this->t('To configure the Login Monitor module:')),
      'configuration' => $this->buildItemList([
        $this->t('Go to Administration > Configuration > People > Login monitor settings (/admin/config/people/login-monitor) to configure the module settings.'),
        $this->t('Set up permissions at Administration > People > Permissions (/admin/people/permissions) to control who can view and manage login logs.'),
        $this->t('View login logs at Administration > Reports > Login Log (/admin/reports/logins).'),
      ], 'ol'),
      'event_types_title' => $this->buildHeading('h3', $this->t('Login Event Types')),
      'event_types_text' => $this->buildParagraph($this->t('The module tracks the following types of login events:')),
      'event_types' => $this->buildItemList([
        $this->t('Successful Login: Regular user login through the login form.'),
        $this->t('One-time Login: Login using password reset or one-time login links.'),
        $this->t('Failed Login (Invalid User): Login attempt with non-existent username.'),
        $this->t('Failed Login (Valid User): Login attempt with correct username but wrong password.'),
        $this->t('Failed Login (Blocked User): Login attempt by a blocked user account.'),
        $this->t('Logout: User logout events.'),
      ]),
      'token_support_title' => $this->buildHeading('h3', $this->t('Token Support')),
      'token_support_text' => $this->buildParagraph($this->t('The module provides the following tokens for use in email templates:')),
      'tokens' => $this->buildItemList([
        $this->t('[login_monitor:event_type] - The type of login event'),
        $this->t('[login_monitor:event_type_label] - Human-readable label for the event type'),
        $this->t('[login_monitor:username] - Username involved in the event'),
        $this->t('[login_monitor:ip_address] - IP address of the login attempt'),
        $this->t('[login_monitor:user_agent] - Browser user agent string'),
      ]),
      'drush_title' => $this->buildHeading('h3', $this->t('Drush Commands')),
      'drush_text' => $this->buildParagraph($this->t('The module provides the following Drush command:')),
      'drush' => $this->buildItemList([
        $this->t('drush login-monitor:send-reports - Manually trigger sending of statistical reports'),
      ]),
      'permissions_title' => $this->buildHeading('h3', $this->t('Permissions')),
      'permissions' => $this->buildItemList([
        $this->t('Administer Login Monitor settings: Allows users to configure module settings.'),
        $this->t('View login log entities: Allows users to view login logs in the administrative interface.'),
        $this->t('Administer login log entities: Allows full access to login log entities including deletion.'),
      ]),
    ];

    return $this->renderInIsolation($build);
  }

  /**
   * Renders a list of paragraphs.
   */
  private function renderParagraphs(array $paragraphs): string {
    $build = [];
    foreach ($paragraphs as $index => $paragraph) {
      $build['paragraph_' . $index] = $this->buildParagraph($paragraph);
    }

    return $this->renderInIsolation($build);
  }

  /**
   * Builds a heading render array.
   */
  private function buildHeading(string $tag, mixed $value): array {
    return [
      '#type' => 'html_tag',
      '#tag' => $tag,
      '#value' => $value,
    ];
  }

  /**
   * Builds a paragraph render array.
   */
  private function buildParagraph(mixed $value): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $value,
    ];
  }

  /**
   * Builds an item list render array.
   */
  private function buildItemList(array $items, string $listType = 'ul'): array {
    return [
      '#theme' => 'item_list',
      '#list_type' => $listType,
      '#items' => $items,
    ];
  }

  /**
   * Renders a build array when the renderer is available.
   */
  private function renderInIsolation(array $build): string {
    return $this->renderer
      ? (string) $this->renderer->renderInIsolation($build)
      : '';
  }

  /**
   * Validates an email address with a transitional fallback.
   */
  private function isValidEmail(string $email): bool {
    return $this->emailValidator
      ? $this->emailValidator->isValid($email)
      : filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE;
  }

}
