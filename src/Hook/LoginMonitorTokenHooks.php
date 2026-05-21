<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\Service\LoginEventDataInterface;

/**
 * Provides token hooks for Login Monitor.
 */
final class LoginMonitorTokenHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $type = [
      'name' => $this->t('Login Monitor'),
      'description' => $this->t('Tokens for login monitoring events.'),
    ];

    $tokens['event_type'] = [
      'name' => $this->t('Event Type'),
      'description' => $this->t('The type of login event (e.g., successful login, failed login).'),
    ];

    $tokens['event_type_label'] = [
      'name' => $this->t('Event Type Label'),
      'description' => $this->t('The human-readable label for the login event type.'),
    ];

    $tokens['username'] = [
      'name' => $this->t('Username'),
      'description' => $this->t('The username of the user involved in the login event.'),
    ];

    $tokens['ip_address'] = [
      'name' => $this->t('IP Address'),
      'description' => $this->t('The IP address from which the login attempt was made.'),
    ];

    $tokens['user_agent'] = [
      'name' => $this->t('User Agent'),
      'description' => $this->t('The user agent string of the browser used for the login attempt.'),
    ];

    return [
      'types' => ['login_monitor' => $type],
      'tokens' => ['login_monitor' => $tokens],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleableMetadata): array {
    $replacements = [];

    if ($type == 'login_monitor' && !empty($data['login_monitor'])) {
      $eventType = $data['login_monitor']['event_type'] ?? NULL;
      $loginEventData = $data['login_monitor']['login_event_data'] ?? NULL;

      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'event_type':
            if ($eventType instanceof LoginEventType) {
              $bubbleableMetadata->setCacheMaxAge(0);
              $replacements[$original] = $eventType->value;
            }
            break;

          case 'event_type_label':
            if ($eventType instanceof LoginEventType) {
              $bubbleableMetadata->setCacheMaxAge(0);
              $replacements[$original] = $eventType->getLabel();
            }
            break;

          case 'username':
            if ($loginEventData instanceof LoginEventDataInterface) {
              $bubbleableMetadata->setCacheMaxAge(0);
              $replacements[$original] = (string) $loginEventData->getUsername();
            }
            break;

          case 'ip_address':
            if ($loginEventData instanceof LoginEventDataInterface) {
              $bubbleableMetadata->setCacheMaxAge(0);
              $replacements[$original] = $loginEventData->getIpAddress();
            }
            break;

          case 'user_agent':
            if ($loginEventData instanceof LoginEventDataInterface) {
              $bubbleableMetadata->setCacheMaxAge(0);
              $replacements[$original] = (string) $loginEventData->getUserAgent();
            }
            break;
        }
      }
    }

    return $replacements;
  }

}
