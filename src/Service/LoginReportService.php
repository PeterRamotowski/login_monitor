<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for generating and sending login statistics email reports.
 */
final class LoginReportService {

  use StringTranslationTrait;

  /**
   * The report scheduler lock name.
   */
  private const REPORT_LOCK_NAME = 'login_monitor_report';

  /**
   * The report scheduler lock timeout.
   */
  private const REPORT_LOCK_TIMEOUT = 60.0;

  /**
   * Constructs a login report service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerChannelInterface $logger,
    private readonly LoginStatsService $loginStatsService,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?DateFormatterInterface $dateFormatter = NULL,
  ) {}

  /**
   * Check if any reports need to be sent and send them.
   */
  public function processScheduledReports(): void {
    $settings = $this->getSettings();
    if ($settings->get('enable_email_reports') !== TRUE) {
      return;
    }

    if ($this->lock) {
      if (!$this->lock->acquire(self::REPORT_LOCK_NAME, self::REPORT_LOCK_TIMEOUT)) {
        return;
      }

      try {
        $this->sendDueScheduledReport();
      }
      finally {
        $this->lock->release(self::REPORT_LOCK_NAME);
      }
      return;
    }

    $this->sendDueScheduledReport();
  }

  /**
   * Process daily report.
   *
   * @param int $currentTime
   *   Current timestamp.
   */
  private function processDailyReport(int $currentTime): void {
    $lastReport = $this->getSettings()->get('last_daily_report') ?: 0;
    $currentDay = $this->getDateTime($currentTime)->setTime(0, 0);
    $currentDayStart = $currentDay->getTimestamp();
    $yesterdayStart = $currentDay->modify('-1 day')->getTimestamp();
    $yesterdayEnd = $currentDayStart - 1;

    // Send report if we haven't sent one today and it's past midnight.
    if ($lastReport < $currentDayStart) {
      $stats = $this->loginStatsService->getDailyStats($yesterdayStart);
      $this->sendReport('daily', $stats, $yesterdayStart, $yesterdayEnd);

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
    $lastReport = $this->getSettings()->get('last_weekly_report') ?: 0;
    $currentDate = $this->getDateTime($currentTime);
    $currentWeekStart = $currentDate->setTime(0, 0);

    // Send report only on Monday, once for the previous full week.
    if ((int) $currentDate->format('N') === 1 && $lastReport < $currentWeekStart->getTimestamp()) {
      $weekStart = $currentWeekStart->modify('-7 days')->getTimestamp();
      $weekEnd = $currentWeekStart->getTimestamp() - 1;
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
    $lastReport = $this->getSettings()->get('last_monthly_report') ?: 0;
    $currentDate = $this->getDateTime($currentTime);
    $currentMonthStart = $currentDate
      ->modify('first day of this month')
      ->setTime(0, 0);

    // Send report only on the first day, once for the previous full month.
    if ((int) $currentDate->format('j') === 1 && $lastReport < $currentMonthStart->getTimestamp()) {
      $monthStart = $currentMonthStart->modify('first day of previous month')->getTimestamp();
      $monthEnd = $currentMonthStart->getTimestamp() - 1;
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
    $settings = $this->getSettings();
    $siteSettings = $this->getSiteSettings();
    $recipient = $settings->get('report_recipient') ?: $siteSettings->get('mail');

    if (!$recipient) {
      $this->logger->error('No recipient email configured for login reports.');
      return;
    }

    $subject = $this->t('@site_name - @frequency login report for @period', [
      '@site_name' => $siteSettings->get('name'),
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
      $this->logger->info('Login report (@frequency) sent successfully to @recipient', [
        '@frequency' => $frequency,
        '@recipient' => $recipient,
      ]);
    }
    else {
      $this->logger->error('Failed to send login report (@frequency) to @recipient', [
        '@frequency' => $frequency,
        '@recipient' => $recipient,
      ]);
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

    $body[] = $this->t('Login statistics report for @site_name', ['@site_name' => $this->getSiteSettings()->get('name')]);
    $body[] = $this->t('Period: @period', ['@period' => $periodText]);
    $body[] = $this->t('Summary:');
    $body[] = $this->t('- Total successful logins: @total', ['@total' => $stats['total_logins']]);
    $body[] = $this->t('- Unique users with successful logins: @unique', ['@unique' => $stats['unique_users']]);
    $body[] = '';

    if (!empty($stats['top_users'])) {
      $body[] = $this->t('Top users by login count:');
      foreach ($stats['top_users'] as $index => $user) {
        $body[] = $this->t('@num. @name (UID: @uid) - @count logins', [
          '@num' => $index + 1,
          '@name' => $user['name'],
          '@uid' => $user['uid'],
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
        return $this->formatTimestamp($periodStart, 'F j, Y');

      case 'weekly':
        return (string) $this->t('@start to @end', [
          '@start' => $this->formatTimestamp($periodStart, 'F j, Y'),
          '@end' => $this->formatTimestamp($periodEnd, 'F j, Y'),
        ]);

      case 'monthly':
        return $this->formatTimestamp($periodStart, 'F Y');

      default:
        return (string) $this->t('@start to @end', [
          '@start' => $this->formatTimestamp($periodStart, 'F j, Y'),
          '@end' => $this->formatTimestamp($periodEnd, 'F j, Y'),
        ]);
    }
  }

  /**
   * Sends the scheduled report for the configured frequency when due.
   */
  private function sendDueScheduledReport(): void {
    $frequency = $this->getSettings()->get('report_frequency');
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
   * Formats a timestamp with Drupal formatter when available.
   */
  private function formatTimestamp(int $timestamp, string $format): string {
    if ($this->dateFormatter) {
      return $this->dateFormatter->format($timestamp, 'custom', $format);
    }

    return $this->getDateTime($timestamp)->format($format);
  }

  /**
   * Gets a fresh Login Monitor settings snapshot.
   */
  private function getSettings(): ImmutableConfig {
    return $this->configFactory->get('login_monitor.settings');
  }

  /**
   * Gets a fresh site settings snapshot.
   */
  private function getSiteSettings(): ImmutableConfig {
    return $this->configFactory->get('system.site');
  }

  /**
   * Gets a date object for the site default timezone.
   */
  private function getDateTime(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
  }

}
