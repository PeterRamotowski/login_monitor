<?php

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for handling login log.
 */
class LoginLogService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private TimeInterface $time,
    private ConfigFactoryInterface $configFactory,
    private LoginEventData $loginEventData,
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
      'user_id' => $loginEventData->getUserId(),
      'event_type' => $eventType->value,
      'concurrent_sessions' => $this->loginEventData->getActiveSessions(),
      'ip_address' => $this->loginEventData->getIpAddress(),
      'user_agent' => $this->loginEventData->getUserAgent(),
      'typed_username' => $loginEventData->getTypedUsername(),
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

    $query = $storage->getQuery()
      ->condition('created', $cutoffTime, '<')
      ->accessCheck(FALSE);
    $loginLogIds = $query->execute();

    if (!empty($loginLogIds)) {
      $loginLogs = $storage->loadMultiple($loginLogIds);
      $storage->delete($loginLogs);
      return count($loginLogIds);
    }

    return 0;
  }

}
