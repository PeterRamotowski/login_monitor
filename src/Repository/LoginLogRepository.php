<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Provides read-access to login log data for reporting and statistics.
 *
 * All raw SQL queries in this repository derive the base table name from
 * entity type metadata so that database prefixes and future table renames
 * are handled automatically.
 */
final class LoginLogRepository {

  /**
   * The physical table name resolved from entity type metadata.
   */
  private string $table;

  /**
   * Constructs a LoginLogRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
    $definition = $entityTypeManager->getDefinition('login_log');
    $this->table = $definition->getBaseTable();
  }

  /**
   * Counts total successful logins in the given period.
   *
   * @param int $startTime
   *   Period start timestamp (inclusive).
   * @param int $endTime
   *   Period end timestamp (inclusive).
   *
   * @return int
   *   Total successful login count.
   */
  public function countSuccessfulLogins(int $startTime, int $endTime): int {
    return (int) $this->database->select($this->table, 'll')
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->condition('event_type', $this->successEventValues(), 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts distinct users with successful logins in the given period.
   *
   * @param int $startTime
   *   Period start timestamp (inclusive).
   * @param int $endTime
   *   Period end timestamp (inclusive).
   *
   * @return int
   *   Count of unique users.
   */
  public function countUniqueUsers(int $startTime, int $endTime): int {
    $query = $this->database->select($this->table, 'll')
      ->fields('ll', ['uid'])
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->condition('event_type', $this->successEventValues(), 'IN')
      ->condition('uid', 0, '>')
      ->distinct();

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the top users by successful login count for the given period.
   *
   * @param int $startTime
   *   Period start timestamp (inclusive).
   * @param int $endTime
   *   Period end timestamp (inclusive).
   * @param int $limit
   *   Maximum number of top users to return.
   *
   * @return array<array{uid: int, name: string, count: int}>
   *   Ordered list of top users with their login counts.
   */
  public function getTopUsers(int $startTime, int $endTime, int $limit = 5): array {
    $query = $this->database->select($this->table, 'll')
      ->fields('ll', ['uid'])
      ->condition('created', $startTime, '>=')
      ->condition('created', $endTime, '<=')
      ->condition('event_type', $this->successEventValues(), 'IN')
      ->condition('uid', 0, '>')
      ->groupBy('uid')
      ->orderBy('login_count', 'DESC')
      ->range(0, $limit);
    $query->addExpression('COUNT(*)', 'login_count');

    $rows = $query->execute()->fetchAllAssoc('uid');
    if (empty($rows)) {
      return [];
    }

    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($rows));
    $result = [];
    foreach ($rows as $uid => $row) {
      if (isset($users[$uid])) {
        /** @var \Drupal\user\UserInterface $user */
        $user = $users[$uid];
        $result[] = [
          'uid' => (int) $uid,
          'name' => $user->getDisplayName(),
          'count' => (int) $row->login_count,
        ];
      }
    }
    return $result;
  }

  /**
   * Returns per-event-type counts for an optional time range.
   *
   * @param int|null $startTime
   *   Optional period start timestamp (inclusive).
   * @param int|null $endTime
   *   Optional period end timestamp (inclusive).
   *
   * @return array<string, int>
   *   Map of event type value to count; all known event types are present.
   */
  public function getEventCounts(?int $startTime = NULL, ?int $endTime = NULL): array {
    $query = $this->database->select($this->table, 'll')
      ->fields('ll', ['event_type'])
      ->groupBy('event_type');
    $query->addExpression('COUNT(*)', 'count');

    if ($startTime !== NULL) {
      $query->condition('created', $startTime, '>=');
    }
    if ($endTime !== NULL) {
      $query->condition('created', $endTime, '<=');
    }

    $results = $query->execute()->fetchAllKeyed();

    $defaults = array_fill_keys(
      array_column(LoginEventType::cases(), 'value'),
      0,
    );
    return array_merge($defaults, $results);
  }

  /**
   * Returns recent failed login records ordered by most recent first.
   *
   * @param int $limit
   *   Maximum number of records to return.
   * @param int $hours
   *   Number of hours to look back from the current request time.
   *
   * @return array<object>
   *   Raw database records with ip_address, created, event_type fields.
   */
  public function getRecentFailedAttempts(int $limit = 50, int $hours = 24): array {
    $cutoff = $this->time->getRequestTime() - ($hours * 3600);

    return $this->database->select($this->table, 'll')
      ->fields('ll', ['ip_address', 'created', 'event_type'])
      ->condition('event_type', $this->failedEventValues(), 'IN')
      ->condition('created', $cutoff, '>=')
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Counts active sessions for the given user ID.
   *
   * @param int $uid
   *   The user ID to check.
   * @param int $maxLifetime
   *   The session max lifetime in seconds (typically session.gc_maxlifetime).
   *
   * @return int
   *   Number of active sessions for the user.
   */
  public function countActiveSessions(int $uid, int $maxLifetime): int {
    $cutoff = $this->time->getRequestTime() - $maxLifetime;

    return (int) $this->database->select('sessions', 's')
      ->condition('s.uid', $uid)
      ->condition('s.timestamp', $cutoff, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns event values representing successful logins.
   *
   * @return string[]
   *   Event type values for successful login events.
   */
  private function successEventValues(): array {
    return [
      LoginEventType::SuccessLogin->value,
      LoginEventType::SuccessLoginOnetime->value,
    ];
  }

  /**
   * Returns event values representing failed logins.
   *
   * @return string[]
   *   Event type values for failed login events.
   */
  private function failedEventValues(): array {
    return [
      LoginEventType::FailedLoginInvalidUser->value,
      LoginEventType::FailedLoginValidUser->value,
      LoginEventType::FailedLoginBlockedUser->value,
    ];
  }

  /**
   * Returns the physical base table name for the login_log entity type.
   *
   * Exposed for callers that need it without creating a direct dependency.
   *
   * @return string
   *   The table name.
   *
   * @internal
   *   Only for use within the login_monitor module.
   */
  public function getTable(): string {
    return $this->table;
  }

  /**
   * Counts all login log records matching optional conditions.
   *
   * Used by LoginMonitorLimits::DELETE_BATCH_SIZE consumers via
   * LoginLogService.
   *
   * @param int|null $startTime
   *   Optional start timestamp filter.
   *
   * @return int
   *   Total count of matching records.
   */
  public function countAll(?int $startTime = NULL): int {
    $query = $this->database->select($this->table, 'll');
    if ($startTime !== NULL) {
      $query->condition('created', $startTime, '<');
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

}
