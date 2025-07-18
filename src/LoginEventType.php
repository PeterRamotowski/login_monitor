<?php

namespace Drupal\login_monitor;

/**
 * Enum for Login Log event types.
 */
enum LoginEventType: string {

  case SuccessLogin = 'success_login';
  case SuccessLoginOnetime = 'success_login_onetime';
  case FailedLoginInvalidUser = 'failed_login_invalid_user';
  case FailedLoginValidUser = 'failed_login_valid_user';
  case FailedLoginBlockedUser = 'failed_login_blocked_user';
  case Logout = 'logout';

  /**
   * Get the human-readable label for the event type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label for the event type.
   */
  public function getLabel() {
    return match ($this) {
      self::SuccessLogin => t('Successful Login'),
      self::SuccessLoginOnetime => t('Successful One-time Login'),
      self::FailedLoginInvalidUser => t('Failed Login - Invalid User'),
      self::FailedLoginValidUser => t('Failed Login - Valid User'),
      self::FailedLoginBlockedUser => t('Failed Login - Blocked User'),
      self::Logout => t('Logout'),
    };
  }

  /**
   * Get all event types as an associative array.
   *
   * @return array
   *   Array of event types with their labels.
   */
  public static function getOptions(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->getLabel();
    }
    return $options;
  }

}
