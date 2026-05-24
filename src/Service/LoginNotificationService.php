<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\login_monitor\LoginEventType;

/**
 * Service for handling login email notifications.
 */
final class LoginNotificationService {

  use StringTranslationTrait;

  /**
   * The notification flood control event name.
   */
  private const FLOOD_EVENT_NAME = 'login_monitor.notification';

  /**
   * The notification flood control time window.
   */
  private const FLOOD_WINDOW = 3600;

  /**
   * Constructs a login notification service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerChannelInterface $logger,
    private readonly Token $token,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?FloodInterface $flood = NULL,
  ) {}

  /**
   * Send email notification to admin about user login.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The type of event to log.
   * @param \Drupal\login_monitor\Service\LoginEventDataInterface $loginEventData
   *   The login event data or a pre-captured payload snapshot.
   */
  public function sendEmailNotification(LoginEventType $eventType, LoginEventDataInterface $loginEventData): void {
    $settings = $this->configFactory->get('login_monitor.settings');

    if ($settings->get('send_email_notifications') !== TRUE) {
      return;
    }

    $identifier = hash('sha256', $loginEventData->getIpAddress() . ':' . $eventType->value);
    $rateLimit = (int) ($settings->get('notification_rate_limit') ?? 10);
    if ($rateLimit > 0 && $this->flood && !$this->flood->isAllowed(self::FLOOD_EVENT_NAME, $rateLimit, self::FLOOD_WINDOW, $identifier)) {
      $this->logger->warning('Suppressed login monitor notification flood for @event from @ip.', [
        '@event' => $eventType->value,
        '@ip' => $loginEventData->getIpAddress(),
      ]);
      return;
    }

    $recipientEmail = $settings->get('email_recipient') ?: $this->configFactory->get('system.site')->get('mail');
    $emailContent = $settings->get('email_content');
    $emailParams['subject'] = $this->t('User monitoring notification');

    $tokenData = [
      'login_monitor' => [
        'event_type' => $eventType,
        'login_event_data' => $loginEventData,
      ],
    ];

    if (empty($emailContent)) {
      $emailParams['body'][] = MailFormatHelper::htmlToText(
        $this->t('Event type: @eventType<br>Username: @userName', [
          '@eventType' => $eventType->getLabel(),
          '@userName' => $loginEventData->getUsername(),
        ])
      );
    }
    else {
      $emailContent = $this->token->replace($emailContent, $tokenData);
      $emailParams['body'][] = MailFormatHelper::htmlToText($emailContent);
    }

    $langcode = $this->currentUser->getPreferredLangcode();
    if ($rateLimit > 0 && $this->flood) {
      $this->flood->register(self::FLOOD_EVENT_NAME, self::FLOOD_WINDOW, $identifier);
    }

    $message = $this->mailManager->mail('login_monitor', 'login_notify', $recipientEmail, $langcode, $emailParams, NULL, TRUE);

    if ($message['result'] === TRUE) {
      $this->logger->notice(
        'An email notification of user login has been sent to @email.',
        [
          '@email' => $recipientEmail,
        ],
      );
    }
    else {
      $this->logger->error(
        'There was a problem sending email notification to @email.',
        [
          '@email' => $recipientEmail,
        ],
      );
    }
  }

}
