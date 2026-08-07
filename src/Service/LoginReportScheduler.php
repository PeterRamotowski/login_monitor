<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Determines which login report periods are currently due for sending.
 *
 * Stores last-sent timestamps in the State API (not configuration) so that
 * runtime operational data is never exported to code via drush config:export.
 */
final class LoginReportScheduler {

  /**
   * State key prefix for report timestamps.
   */
  private const STATE_PREFIX = 'login_monitor.last_report.';

  /**
   * Constructs a LoginReportScheduler.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state API service.
   */
  public function __construct(
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns a time-period array when a daily report is due, or NULL otherwise.
   *
   * @return array{start: int, end: int}|null
   *   Period boundaries, or NULL when no report is due.
   */
  public function getDailyPeriodIfDue(): ?array {
    $currentTime = $this->time->getRequestTime();
    $todayStart = $this->startOfDay($currentTime);

    if ($this->getLastSent('daily') >= $todayStart) {
      return NULL;
    }

    $yesterdayStart = $todayStart - 86400;
    return ['start' => $yesterdayStart, 'end' => $todayStart - 1];
  }

  /**
   * Returns a time-period array when a weekly report is due, or NULL otherwise.
   *
   * Reports are only due on Mondays (ISO weekday 1).
   *
   * @return array{start: int, end: int}|null
   *   Period boundaries, or NULL when no report is due.
   */
  public function getWeeklyPeriodIfDue(): ?array {
    $currentTime = $this->time->getRequestTime();
    $dt = $this->getDateTime($currentTime);

    if ((int) $dt->format('N') !== 1) {
      return NULL;
    }

    $weekStart = $this->startOfDay($currentTime);
    if ($this->getLastSent('weekly') >= $weekStart) {
      return NULL;
    }

    $prevWeekStart = $weekStart - 604800;
    return ['start' => $prevWeekStart, 'end' => $weekStart - 1];
  }

  /**
   * Returns a monthly period array when a report is due, or NULL otherwise.
   *
   * Reports are only due on the first day of the month.
   *
   * @return array{start: int, end: int}|null
   *   Period boundaries, or NULL when no report is due.
   */
  public function getMonthlyPeriodIfDue(): ?array {
    $currentTime = $this->time->getRequestTime();
    $dt = $this->getDateTime($currentTime);

    if ((int) $dt->format('j') !== 1) {
      return NULL;
    }

    $currentMonth = $dt
      ->modify('first day of this month')
      ->setTime(0, 0);
    $currentMonthStart = $currentMonth->getTimestamp();

    if ($this->getLastSent('monthly') >= $currentMonthStart) {
      return NULL;
    }

    $prevMonthStart = $currentMonth
      ->modify('first day of previous month')
      ->getTimestamp();

    return ['start' => $prevMonthStart, 'end' => $currentMonthStart - 1];
  }

  /**
   * Records the last-sent timestamp for the given report frequency.
   *
   * @param string $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   */
  public function markSent(string $frequency): void {
    $this->state->set(self::STATE_PREFIX . $frequency, $this->time->getRequestTime());
  }

  /**
   * Returns the last-sent timestamp for the given frequency.
   *
   * @param string $frequency
   *   One of 'daily', 'weekly', or 'monthly'.
   *
   * @return int
   *   Unix timestamp of the last sent report, or 0 if never sent.
   */
  private function getLastSent(string $frequency): int {
    return (int) $this->state->get(self::STATE_PREFIX . $frequency, 0);
  }

  /**
   * Returns the Unix timestamp for midnight of the given timestamp's day.
   *
   * @param int $timestamp
   *   Any Unix timestamp within the target day.
   *
   * @return int
   *   Unix timestamp at 00:00:00 of the same day in the site timezone.
   */
  private function startOfDay(int $timestamp): int {
    return $this->getDateTime($timestamp)->setTime(0, 0)->getTimestamp();
  }

  /**
   * Returns a DateTimeImmutable set to the site default timezone.
   *
   * @param int $timestamp
   *   The Unix timestamp to convert.
   *
   * @return \DateTimeImmutable
   *   DateTime in the site timezone.
   */
  private function getDateTime(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
  }

}
