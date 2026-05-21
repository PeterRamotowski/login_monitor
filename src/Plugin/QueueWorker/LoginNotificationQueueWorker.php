<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\Service\LoginNotificationPayload;
use Drupal\login_monitor\Service\LoginNotificationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued login notification emails during cron.
 *
 * Queue items are created when the notification delivery mode is set to
 * "delayed". Each item holds a LoginEventType and a LoginNotificationPayload
 * snapshot captured at the time of the login event.
 */
#[QueueWorker(
  id: 'login_monitor_notifications',
  title: new TranslatableMarkup('Login monitor notifications'),
  cron: ['time' => 30],
)]
final class LoginNotificationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a LoginNotificationQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\login_monitor\Service\LoginNotificationService $notificationService
   *   The login notification service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly LoginNotificationService $notificationService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('login_monitor.notification_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(mixed $data): void {
    if (!is_array($data)) {
      return;
    }

    $eventType = $data['event_type'] ?? NULL;
    $payload = $data['payload'] ?? NULL;

    if (!$eventType instanceof LoginEventType || !$payload instanceof LoginNotificationPayload) {
      return;
    }

    $this->notificationService->sendEmailNotification($eventType, $payload);
  }

}
