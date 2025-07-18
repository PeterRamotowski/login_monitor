<?php

namespace Drupal\login_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
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
class LoginNotificationService {

  use StringTranslationTrait;

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private MailManagerInterface $mailManager,
    private LoggerChannelInterface $logger,
    private Token $token,
    private DateFormatterInterface $dateFormatter,
    private TimeInterface $time,
    private AccountProxyInterface $currentUser,
  ) {}

  /**
   * Send email notification to admin about user login.
   *
   * @param \Drupal\login_monitor\LoginEventType $eventType
   *   The type of event to log.
   * @param LoginEventData $loginEventData
   *   The login event data.
   */
  public function sendEmailNotification(LoginEventType $eventType, LoginEventData $loginEventData): void {
    $settings = $this->configFactory->get('login_monitor.settings');

    if ($settings->get('send_email_notifications') !== TRUE) {
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

    $message = $this->mailManager->mail('login_monitor', 'login_notify', $recipientEmail, $langcode, $emailParams, NULL, TRUE);

    if ($message['result'] === TRUE) {
      $this->logger->notice(
        $this->t('An email notification of user login has been sent to @email.', [
          '@email' => $recipientEmail,
        ])
      );
    }
    else {
      $this->logger->error(
        $this->t('There was a problem sending email notification to @email.', [
          '@email' => $recipientEmail,
        ])
      );
    }
  }

}
