<?php

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for querying login statistics from the database.
 */
class LoginStatsService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private TimeInterface $time,
    private Connection $database,
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
      'total_logins' => $this->getTotalLogins($startTime, $endTime),
      'unique_users' => $this->getUniqueUsers($startTime, $endTime),
      'top_users' => $this->getTopUsers($startTime, $endTime),
      'event_statistics' => $this->getEventStatistics($startTime, $endTime),
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
    $storage = $this->entityTypeManager->getStorage('login_log');

    $query = $storage->getQuery()
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->accessCheck(FALSE);

    return (int) $query->count()->execute();
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
    $query = $this->database->select('login_log', 'll')
      ->fields('ll', ['uid'])
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->distinct();

    return (int) $query->countQuery()->execute()->fetchField();
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
    // Get user IDs with their login counts.
    $query = $this->database->select('login_log', 'll')
      ->fields('ll', ['uid'])
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->groupBy('uid')
      ->orderBy('login_count', 'DESC')
      ->range(0, $limit);
    $query->addExpression('COUNT(*)', 'login_count');
    $topUsers = $query->execute()->fetchAllAssoc('uid');

    if (empty($topUsers)) {
      return [];
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $users = $userStorage->loadMultiple(array_keys($topUsers));

    $topUsersFormatted = [];
    foreach ($topUsers as $uid => $record) {
      if (isset($users[$uid])) {
        /** @var \Drupal\user\UserInterface $user */
        $user = $users[$uid];
        $topUsersFormatted[] = [
          'uid' => $uid,
          'name' => $user->getDisplayName(),
          'email' => $user->getEmail(),
          'count' => (int) $record->login_count,
        ];
      }
    }

    return $topUsersFormatted;
  }

  /**
   * Get login event statistics.
   *
   * @param int $startTime
   *   Start timestamp for the query range.
   * @param int $endTime
   *   End timestamp for the query range.
   *
   * @return array
   *   Array with statistics for each event type.
   */
  public function getEventStatistics(?int $startTime = NULL, ?int $endTime = NULL): array {
    $query = $this->database->select('login_log', 'll')
      ->fields('ll', ['event_type'])
      ->groupBy('event_type');

    $query->addExpression('COUNT(*)', 'count');

    if ($startTime) {
      $query->condition('created', $startTime, '>=');
    }

    if ($endTime) {
      $query->condition('created', $endTime, '<=');
    }

    $results = $query->execute()->fetchAllKeyed();

    // Ensure all event types are represented.
    $eventTypes = [
      LoginEventType::SuccessLogin->value => 0,
      LoginEventType::FailedLoginInvalidUser->value => 0,
      LoginEventType::FailedLoginValidUser->value => 0,
      LoginEventType::FailedLoginBlockedUser->value => 0,
      LoginEventType::Logout->value => 0,
    ];

    return array_merge($eventTypes, $results);
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
    $cutoffTime = $this->time->getRequestTime() - ($hours * 3600);

    $query = $this->database->select('login_log', 'll')
      ->fields('ll', ['ip_address', 'created', 'event_type'])
      ->condition('event_type', [
        LoginEventType::FailedLoginInvalidUser->value,
        LoginEventType::FailedLoginValidUser->value,
        LoginEventType::FailedLoginBlockedUser->value,
      ], 'IN')
      ->condition('created', $cutoffTime, '>=')
      ->orderBy('created', 'DESC')
      ->range(0, $limit);

    return $query->execute()->fetchAll();
  }

  /**
   * Get daily login statistics.
   *
   * @param int $dayStart
   *   Start timestamp for the day.
   *
   * @return array
   *   Array with daily statistics
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
   *   Array with weekly statistics
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
   *   Array with monthly statistics
   */
  public function getMonthlyStats(int $monthStart, int $monthEnd): array {
    return $this->getStatsForPeriod($monthStart, $monthEnd);
  }

}
