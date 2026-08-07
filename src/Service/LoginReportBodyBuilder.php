<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\login_monitor\LoginEventType;

/**
 * Builds the plain-text body and subject for login statistics email reports.
 *
 * Contains no database access or mail dispatch; is independently testable
 * with only a mock DateFormatterInterface.
 */
final class LoginReportBodyBuilder {

  use StringTranslationTrait;

  /**
   * Constructs a LoginReportBodyBuilder.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds an array of body lines for the given frequency and statistics.
   *
   * @param string $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   * @param array $stats
   *   Statistics array as returned by LoginStatsService::getStatsForPeriod().
   * @param int $periodStart
   *   Report period start timestamp.
   * @param int $periodEnd
   *   Report period end timestamp.
   * @param string $siteName
   *   The site name shown in the report heading.
   *
   * @return array
   *   Lines of plain-text email body suitable for use as $params['body'].
   */
  public function buildBody(
    string $frequency,
    array $stats,
    int $periodStart,
    int $periodEnd,
    string $siteName,
  ): array {
    $body = [];
    $body[] = $this->t('Login statistics report for @site_name', [
      '@site_name' => $siteName,
    ]);
    $body[] = $this->t('Period: @period', ['@period' => $this->formatPeriod($frequency, $periodStart, $periodEnd)]);
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
      $body[] = '';
    }

    if (!empty($stats['event_statistics'])) {
      $body[] = $this->t('Event Statistics:');
      foreach ($stats['event_statistics'] as $eventType => $count) {
        if ($count > 0) {
          $body[] = $this->t('- @label: @count', [
            '@label' => LoginEventType::from($eventType)->getLabel(),
            '@count' => $count,
          ]);
        }
      }
      $body[] = '';
    }

    $body[] = $this->t('This is an automated report from the Login Monitor module.');
    return $body;
  }

  /**
   * Formats the report period as a human-readable string.
   *
   * @param string $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   * @param int $periodStart
   *   Period start timestamp.
   * @param int $periodEnd
   *   Period end timestamp.
   *
   * @return string
   *   Formatted period label (e.g. "January 2026" or "January 1, 2026").
   */
  public function formatPeriod(string $frequency, int $periodStart, int $periodEnd): string {
    return match ($frequency) {
      'daily' => $this->dateFormatter->format($periodStart, 'custom', 'F j, Y'),
      'monthly' => $this->dateFormatter->format($periodStart, 'custom', 'F Y'),
      default => (string) $this->t('@start to @end', [
        '@start' => $this->dateFormatter->format($periodStart, 'custom', 'F j, Y'),
        '@end' => $this->dateFormatter->format($periodEnd, 'custom', 'F j, Y'),
      ]),
    };
  }

}
