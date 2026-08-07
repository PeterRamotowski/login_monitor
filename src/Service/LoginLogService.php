<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\LoginMonitorLimits;

/**
 * Service for handling login log.
 */
final class LoginLogService {

  /**
   * Constructs a login log service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Log user login to database.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The type of event to log.
   * @param LoginEventData $loginEventData
   *   The login event data.
   */
  public function saveLog(LoginEventType $eventType, LoginEventData $loginEventData): void {

    $settings = $this->configFactory->get('login_monitor.settings');

    if ($settings->get('enable_login_logging') !== TRUE) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('login_log');
    $entity = $storage->create([
      'uid' => $loginEventData->getUserId() ?? 0,
      'event_type' => $eventType->value,
      'concurrent_sessions' => $loginEventData->getActiveSessions(),
      'ip_address' => $loginEventData->getIpAddress(),
      'user_agent' => $loginEventData->getUserAgent(),
      'typed_username' => $loginEventData->getTypedUsername() ?? $loginEventData->getUsername(),
    ]);
    $storage->save($entity);
  }

  /**
   * Delete login logs older than the specified number of days.
   *
   * @param int $retentionDays
   *   The number of days to retain logs.
   *
   * @return int
   *   The number of deleted login logs.
   */
  public function deleteOldLogs(int $retentionDays): int {
    if ($retentionDays <= 0) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('login_log');
    $cutoffTime = $this->time->getRequestTime() - ($retentionDays * 24 * 60 * 60);

    $deletedCount = 0;

    do {
      $loginLogIds = $storage->getQuery()
        ->condition('created', $cutoffTime, '<')
        ->accessCheck(FALSE)
        ->range(0, LoginMonitorLimits::DELETE_BATCH_SIZE)
        ->execute();

      if (empty($loginLogIds)) {
        break;
      }

      $loginLogs = $storage->loadMultiple($loginLogIds);
      $storage->delete($loginLogs);
      $deletedCount += count($loginLogIds);
    } while (count($loginLogIds) === LoginMonitorLimits::DELETE_BATCH_SIZE);

    return $deletedCount;
  }

  /**
   * Deletes all login log entries belonging to a specific user.
   *
   * @param int $uid
   *   The user ID whose logs should be deleted.
   *
   * @return int
   *   The number of deleted log entries.
   */
  public function deleteByUserId(int $uid): int {
    $storage = $this->entityTypeManager->getStorage('login_log');
    $deletedCount = 0;

    do {
      $ids = $storage->getQuery()
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->range(0, LoginMonitorLimits::DELETE_BATCH_SIZE)
        ->execute();

      if (empty($ids)) {
        break;
      }

      $storage->delete($storage->loadMultiple($ids));
      $deletedCount += count($ids);
    } while (count($ids) === LoginMonitorLimits::DELETE_BATCH_SIZE);

    return $deletedCount;
  }

}
