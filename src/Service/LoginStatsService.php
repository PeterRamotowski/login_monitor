<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\login_monitor\Repository\LoginLogRepository;

/**
 * Service for querying login statistics from the database.
 *
 * Acts as an aggregation facade over LoginLogRepository, composing
 * lower-level counts into structured statistics arrays for reporting.
 */
final class LoginStatsService {

  /**
   * Constructs a login stats service.
   *
   * @param \Drupal\login_monitor\Repository\LoginLogRepository $repository
   *   The login log repository.
   */
  public function __construct(
    private readonly LoginLogRepository $repository,
  ) {}

  /**
   * Get statistics for a specific time period.
   *
   * @param int $startTime
   *   Start timestamp for the query range.
   * @param int $endTime
   *   End timestamp for the query range.
   *
   * @return array
   *   Array containing statistics for the period.
   */
  public function getStatsForPeriod(int $startTime, int $endTime): array {
    return [
      'period_start' => $startTime,
      'period_end' => $endTime,
      'total_logins' => $this->repository->countSuccessfulLogins($startTime, $endTime),
      'unique_users' => $this->repository->countUniqueUsers($startTime, $endTime),
      'top_users' => $this->repository->getTopUsers($startTime, $endTime),
      'event_statistics' => $this->repository->getEventCounts($startTime, $endTime),
    ];
  }

  /**
   * Get total login count for a period.
   *
   * @param int $startTime
   *   Start timestamp for the query range.
   * @param int $endTime
   *   End timestamp for the query range.
   *
   * @return int
   *   Total number of logins in the period.
   */
  public function getTotalLogins(int $startTime, int $endTime): int {
    return $this->repository->countSuccessfulLogins($startTime, $endTime);
  }

  /**
   * Get unique user count for a period.
   *
   * @param int $startTime
   *   Start timestamp for the query range.
   * @param int $endTime
   *   End timestamp for the query range.
   *
   * @return int
   *   The number of unique users who logged in during the period.
   */
  public function getUniqueUsers(int $startTime, int $endTime): int {
    return $this->repository->countUniqueUsers($startTime, $endTime);
  }

  /**
   * Get top users by login count for a period.
   *
   * @param int $startTime
   *   Start timestamp for the query range.
   * @param int $endTime
   *   End timestamp for the query range.
   * @param int $limit
   *   Maximum number of top users to return.
   *
   * @return array
   *   Array of top users with their login counts.
   */
  public function getTopUsers(int $startTime, int $endTime, int $limit = 5): array {
    return $this->repository->getTopUsers($startTime, $endTime, $limit);
  }

  /**
   * Get login event statistics.
   *
   * @param int|null $startTime
   *   Start timestamp for the query range.
   * @param int|null $endTime
   *   End timestamp for the query range.
   *
   * @return array
   *   Array with statistics for each event type.
   */
  public function getEventStatistics(?int $startTime = NULL, ?int $endTime = NULL): array {
    return $this->repository->getEventCounts($startTime, $endTime);
  }

  /**
   * Get recent failed login attempts for security monitoring.
   *
   * @param int $limit
   *   Maximum number of attempts to return.
   * @param int $hours
   *   Number of hours to look back.
   *
   * @return array
   *   Array of recent failed login attempts.
   */
  public function getRecentFailedAttempts(int $limit = 50, int $hours = 24): array {
    return $this->repository->getRecentFailedAttempts($limit, $hours);
  }

  /**
   * Get daily login statistics.
   *
   * @param int $dayStart
   *   Start timestamp for the day.
   *
   * @return array
   *   Array with daily statistics.
   */
  public function getDailyStats(int $dayStart): array {
    $dayEnd = $dayStart + 86400 - 1;
    return $this->getStatsForPeriod($dayStart, $dayEnd);
  }

  /**
   * Get weekly login statistics.
   *
   * @param int $weekStart
   *   Start timestamp for the week.
   * @param int $weekEnd
   *   End timestamp for the week.
   *
   * @return array
   *   Array with weekly statistics.
   */
  public function getWeeklyStats(int $weekStart, int $weekEnd): array {
    return $this->getStatsForPeriod($weekStart, $weekEnd);
  }

  /**
   * Get monthly login statistics.
   *
   * @param int $monthStart
   *   Start timestamp for the month.
   * @param int $monthEnd
   *   End timestamp for the month.
   *
   * @return array
   *   Array with monthly statistics.
   */
  public function getMonthlyStats(int $monthStart, int $monthEnd): array {
    return $this->getStatsForPeriod($monthStart, $monthEnd);
  }

}
