<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Sends scheduled login statistics email reports.
 *
 * Acts as a thin coordinator: scheduling decisions are delegated to
 * LoginReportScheduler and body generation to LoginReportBodyBuilder.
 */
final class LoginReportService {

  use StringTranslationTrait;

  /**
   * The report scheduler lock name.
   */
  private const REPORT_LOCK_NAME = 'login_monitor_report';

  /**
   * The report scheduler lock timeout in seconds.
   */
  private const REPORT_LOCK_TIMEOUT = 60.0;

  /**
   * Constructs a LoginReportService.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerChannelInterface $logger,
    private readonly LoginStatsService $loginStatsService,
    private readonly LoginReportScheduler $scheduler,
    private readonly LoginReportBodyBuilder $bodyBuilder,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Checks the schedule and sends any overdue reports.
   */
  public function processScheduledReports(): void {
    $settings = $this->getSettings();
    if ($settings->get('enable_email_reports') !== TRUE) {
      return;
    }

    if (!$this->lock->acquire(self::REPORT_LOCK_NAME, self::REPORT_LOCK_TIMEOUT)) {
      return;
    }

    try {
      $this->dispatchDueReport($settings->get('report_frequency'));
    }
    finally {
      $this->lock->release(self::REPORT_LOCK_NAME);
    }
  }

  /**
   * Dispatches the report for the given frequency if it is currently due.
   *
   * @param string|null $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   */
  private function dispatchDueReport(?string $frequency): void {
    $period = match ($frequency) {
      'daily' => $this->scheduler->getDailyPeriodIfDue(),
      'weekly' => $this->scheduler->getWeeklyPeriodIfDue(),
      'monthly' => $this->scheduler->getMonthlyPeriodIfDue(),
      default => NULL,
    };

    if ($period === NULL) {
      return;
    }

    $stats = $this->loginStatsService->getStatsForPeriod($period['start'], $period['end']);
    if ($this->sendReport((string) $frequency, $stats, $period['start'], $period['end'])) {
      $this->scheduler->markSent((string) $frequency);
    }
  }

  /**
   * Builds and delivers the report email.
   *
   * @param string $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   * @param array $stats
   *   Statistics array from LoginStatsService::getStatsForPeriod().
   * @param int $periodStart
   *   Period start timestamp.
   * @param int $periodEnd
   *   Period end timestamp.
   *
   * @return bool
   *   TRUE when the report was delivered successfully, otherwise FALSE.
   */
  private function sendReport(
    string $frequency,
    array $stats,
    int $periodStart,
    int $periodEnd,
  ): bool {
    $siteSettings = $this->getSiteSettings();
    $monitorSettings = $this->getSettings();
    $recipient = $monitorSettings->get('report_recipient') ?: $siteSettings->get('mail');

    if (!$recipient) {
      $this->logger->error('No recipient email configured for login reports.');
      return FALSE;
    }

    $period = $this->bodyBuilder->formatPeriod($frequency, $periodStart, $periodEnd);
    $params = [
      'subject' => $this->t('@site_name - @frequency login report for @period', [
        '@site_name' => $siteSettings->get('name'),
        '@frequency' => ucfirst($frequency),
        '@period' => $period,
      ]),
      'body' => $this->bodyBuilder->buildBody(
        $frequency,
        $stats,
        $periodStart,
        $periodEnd,
        (string) $siteSettings->get('name'),
      ),
    ];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('login_monitor', 'login_report', $recipient, $langcode, $params);

    if ($result['result']) {
      $this->logger->info('Login report (@frequency) sent successfully to @recipient', [
        '@frequency' => $frequency,
        '@recipient' => $recipient,
      ]);
      return TRUE;
    }

    $this->logger->error('Failed to send login report (@frequency) to @recipient', [
      '@frequency' => $frequency,
      '@recipient' => $recipient,
    ]);
    return FALSE;
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

}
