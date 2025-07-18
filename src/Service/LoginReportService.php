<?php

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for generating and sending login statistics email reports.
 */
class LoginReportService {

  use StringTranslationTrait;

  /**
   * Login Monitor settings.
   */
  private ImmutableConfig $settings;

  /**
   * Site settings.
   */
  private ImmutableConfig $siteSettings;

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private MailManagerInterface $mailManager,
    private TimeInterface $time,
    private Token $token,
    private LanguageManagerInterface $languageManager,
    private LoggerChannelInterface $logger,
    private LoginStatsService $loginStatsService,
  ) {
    $this->settings = $this->configFactory->get('login_monitor.settings');
    $this->siteSettings = $this->configFactory->get('system.site');
  }

  /**
   * Check if any reports need to be sent and send them.
   */
  public function processScheduledReports(): void {
    if ($this->settings->get('enable_email_reports') !== TRUE) {
      return;
    }

    $frequency = $this->settings->get('report_frequency');
    $currentTime = $this->time->getRequestTime();

    switch ($frequency) {
      case 'daily':
        $this->processDailyReport($currentTime);
        break;

      case 'weekly':
        $this->processWeeklyReport($currentTime);
        break;

      case 'monthly':
        $this->processMonthlyReport($currentTime);
        break;
    }
  }

  /**
   * Process daily report.
   *
   * @param int $currentTime
   *   Current timestamp.
   */
  private function processDailyReport(int $currentTime): void {
    $lastReport = $this->settings->get('last_daily_report') ?: 0;
    $yesterday = strtotime('yesterday', $currentTime);

    // Send report if we haven't sent one today and it's past midnight.
    if ($lastReport < $yesterday) {
      $stats = $this->loginStatsService->getDailyStats($yesterday);
      $this->sendReport('daily', $stats, $yesterday, $currentTime - 86400);

      $this->configFactory->getEditable('login_monitor.settings')
        ->set('last_daily_report', $currentTime)
        ->save();
    }
  }

  /**
   * Process weekly report.
   *
   * @param int $currentTime
   *   Current timestamp.
   */
  private function processWeeklyReport(int $currentTime): void {
    $lastReport = $this->settings->get('last_weekly_report') ?: 0;
    $lastMonday = strtotime('last monday', $currentTime);

    // Send report if we haven't sent one this week and it's Monday or later.
    if ($lastReport < $lastMonday && date('N', $currentTime) >= 1) {
      $weekStart = $lastMonday;
      $weekEnd = $weekStart + (7 * 24 * 60 * 60) - 1;
      $stats = $this->loginStatsService->getWeeklyStats($weekStart, $weekEnd);
      $this->sendReport('weekly', $stats, $weekStart, $weekEnd);

      $this->configFactory->getEditable('login_monitor.settings')
        ->set('last_weekly_report', $currentTime)
        ->save();
    }
  }

  /**
   * Process monthly report.
   *
   * @param int $currentTime
   *   Current timestamp.
   */
  private function processMonthlyReport(int $currentTime): void {
    $lastReport = $this->settings->get('last_monthly_report') ?: 0;
    $firstOfMonth = strtotime('first day of this month', $currentTime);

    // Send report if we haven't sent one this month
    // and it's the first day or later.
    if ($lastReport < $firstOfMonth && date('j', $currentTime) >= 1) {
      $monthStart = strtotime('first day of last month', $currentTime);
      $monthEnd = strtotime('last day of last month', $currentTime);
      $stats = $this->loginStatsService->getMonthlyStats($monthStart, $monthEnd);
      $this->sendReport('monthly', $stats, $monthStart, $monthEnd);

      $this->configFactory->getEditable('login_monitor.settings')
        ->set('last_monthly_report', $currentTime)
        ->save();
    }
  }

  /**
   * Send the email report.
   *
   * @param string $frequency
   *   The frequency of the report (daily, weekly, monthly).
   * @param array $stats
   *   The statistics data to include in the report.
   * @param int $periodStart
   *   Start timestamp for the report period.
   * @param int $periodEnd
   *   End timestamp for the report period.
   */
  private function sendReport(string $frequency, array $stats, int $periodStart, int $periodEnd): void {
    $recipient = $this->settings->get('report_recipient') ?: $this->siteSettings->get('mail');

    if (!$recipient) {
      $this->logger->error($this->t('No recipient email configured for login reports.'));
      return;
    }

    $subject = $this->t('@site_name - @frequency login report for @period', [
      '@site_name' => $this->siteSettings->get('name'),
      '@frequency' => ucfirst($frequency),
      '@period' => $this->formatPeriod($frequency, $periodStart, $periodEnd),
    ]);

    $body = $this->generateReportBody($frequency, $stats, $periodStart, $periodEnd);

    $params = [
      'subject' => $subject,
      'body' => $body,
    ];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $result = $this->mailManager->mail(
      'login_monitor',
      'login_report',
      $recipient,
      $langcode,
      $params
    );

    if ($result['result']) {
      $this->logger->info($this->t('Login report (@frequency) sent successfully to @recipient', [
        '@frequency' => $frequency,
        '@recipient' => $recipient,
      ]));
    }
    else {
      $this->logger->error($this->t('Failed to send login report (@frequency) to @recipient', [
        '@frequency' => $frequency,
        '@recipient' => $recipient,
      ]));
    }
  }

  /**
   * Generate the email body for the report.
   *
   * @param string $frequency
   *   The frequency of the report (daily, weekly, monthly).
   * @param array $stats
   *   The statistics data to include in the report.
   * @param int $periodStart
   *   Start timestamp for the report period.
   * @param int $periodEnd
   *   End timestamp for the report period.
   *
   * @return array
   *   The formatted email body as an array of strings.
   */
  private function generateReportBody(string $frequency, array $stats, int $periodStart, int $periodEnd): array {
    $periodText = $this->formatPeriod($frequency, $periodStart, $periodEnd);

    $body = [];

    $body[] = $this->t('Login statistics report for @site_name', ['@site_name' => $this->siteSettings->get('name')]);
    $body[] = $this->t('Period: @period', ['@period' => $periodText]);
    $body[] = $this->t('Summary:');
    $body[] = $this->t('- Total events: @total', ['@total' => $stats['total_logins']]);
    $body[] = $this->t('- Unique users: @unique', ['@unique' => $stats['unique_users']]);
    $body[] = '';

    if (!empty($stats['top_users'])) {
      $body[] = $this->t('Top users by login count:');
      foreach ($stats['top_users'] as $index => $user) {
        $body[] = $this->t('@num. @name (@email) - @count logins', [
          '@num' => $index + 1,
          '@name' => $user['name'],
          '@email' => $user['email'],
          '@count' => $user['count'],
        ]);
      }
    }

    $body[] = '';

    if (!empty($stats['event_statistics'])) {
      $body[] = $this->t('Event Statistics:');
      foreach ($stats['event_statistics'] as $eventType => $count) {
        if ($count > 0) {
          $eventEnum = LoginEventType::from($eventType);
          $body[] = $this->t('- @event_type: @count', [
            '@event_type' => $eventEnum->getLabel(),
            '@count' => $count,
          ]);
        }
      }
    }

    $body[] = '';
    $body[] = $this->t('This is an automated report from the Login Monitor module.');

    return $body;
  }

  /**
   * Format the period text for display.
   *
   * @param string $frequency
   *   The frequency of the report (daily, weekly, monthly).
   * @param int $periodStart
   *   Start timestamp for the report period.
   * @param int $periodEnd
   *   End timestamp for the report period.
   *
   * @return string
   *   Formatted period string.
   */
  private function formatPeriod(string $frequency, int $periodStart, int $periodEnd): string {
    switch ($frequency) {
      case 'daily':
        return date('F j, Y', $periodStart);

      case 'weekly':
        return $this->t('@start to @end', [
          '@start' => date('F j, Y', $periodStart),
          '@end' => date('F j, Y', $periodEnd),
        ]);

      case 'monthly':
        return date('F Y', $periodStart);

      default:
        return $this->t('@start to @end', [
          '@start' => date('F j, Y', $periodStart),
          '@end' => date('F j, Y', $periodEnd),
        ]);
    }
  }

}
