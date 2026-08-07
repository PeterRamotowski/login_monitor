<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\login_monitor\LoginEventType;

/**
 * Routes login notifications to either immediate or queued delivery.
 *
 * Extracted from LoginMonitorService so that delivery routing has a single
 * responsibility and can be unit-tested independently of orchestration logic.
 */
final class LoginNotificationDispatcher {

  /**
   * Constructs a LoginNotificationDispatcher.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\login_monitor\Service\LoginNotificationService $notificationService
   *   The notification service for immediate email delivery.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory for delayed delivery.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoginNotificationService $notificationService,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Dispatches a notification for the given event using the configured mode.
   *
   * When the delivery mode is 'delayed' the event is pushed onto the
   * login_monitor_notifications queue for processing by the cron queue worker.
   * In all other cases the notification is sent immediately.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The login event type.
   * @param \Drupal\login_monitor\Service\LoginEventDataInterface $loginEventData
   *   The login event data or a pre-captured payload snapshot.
   */
  public function dispatch(LoginEventType $eventType, LoginEventDataInterface $loginEventData): void {
    $deliveryMode = $this->configFactory
      ->get('login_monitor.settings')
      ->get('notification_delivery') ?? 'immediate';

    if ($deliveryMode === 'delayed') {
      $this->enqueue($eventType, $loginEventData);
      return;
    }

    $this->notificationService->sendEmailNotification($eventType, $loginEventData);
  }

  /**
   * Pushes a notification snapshot onto the cron queue for delayed delivery.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The login event type.
   * @param \Drupal\login_monitor\Service\LoginEventDataInterface $loginEventData
   *   The live login event data to snapshot.
   */
  private function enqueue(LoginEventType $eventType, LoginEventDataInterface $loginEventData): void {
    $payload = $loginEventData instanceof LoginEventData
      ? LoginNotificationPayload::fromLoginEventData($loginEventData)
      : $loginEventData;

    $this->queueFactory->get('login_monitor_notifications')->createItem([
      'event_type' => $eventType,
      'payload' => $payload,
    ]);
  }

}
