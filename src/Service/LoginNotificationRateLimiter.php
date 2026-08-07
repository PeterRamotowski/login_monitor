<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\login_monitor\LoginEventType;

/**
 * Enforces per-IP-per-event rate limiting for login notification emails.
 *
 * Encapsulates flood control so that LoginNotificationService can be
 * unit-tested for email-building behaviour without simulating the flood
 * backend.
 */
final class LoginNotificationRateLimiter {

  /**
   * The flood event name used for notification rate limiting.
   */
  private const FLOOD_EVENT = 'login_monitor.notification';

  /**
   * The flood window in seconds.
   */
  private const FLOOD_WINDOW = 3600;

  /**
   * Constructs a LoginNotificationRateLimiter.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood control backend.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The login monitor logger channel.
   */
  public function __construct(
    private readonly FloodInterface $flood,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns TRUE when the notification may proceed; FALSE when suppressed.
   *
   * A return value of FALSE means the caller should skip sending the
   * notification. When TRUE is returned the flood entry is registered
   * so that subsequent calls within the window are counted.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The event type being notified.
   * @param string $ipAddress
   *   The originating IP address.
   * @param int $limit
   *   Maximum allowed notifications per window. Zero or negative disables
   *   rate limiting entirely.
   *
   * @return bool
   *   TRUE when the notification is allowed, FALSE when suppressed.
   */
  public function isAllowed(LoginEventType $eventType, string $ipAddress, int $limit): bool {
    if ($limit <= 0) {
      return TRUE;
    }

    $identifier = hash('sha256', $ipAddress . ':' . $eventType->value);

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, self::FLOOD_WINDOW, $identifier)) {
      $this->logger->warning(
        'Suppressed login monitor notification flood for @event from @ip.',
        ['@event' => $eventType->value, '@ip' => $ipAddress],
      );
      return FALSE;
    }

    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);
    return TRUE;
  }

}
