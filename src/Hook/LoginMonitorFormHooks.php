<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Hook;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\login_monitor\LoginMonitorLimits;
use Drupal\login_monitor\Service\LoginMonitorService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides form hooks for Login Monitor.
 */
final class LoginMonitorFormHooks {

  /**
   * Constructs the login monitor form hook service.
   */
  public function __construct(
    #[Autowire(service: 'login_monitor.login_monitor_service')]
    private readonly LoginMonitorService $loginMonitorService,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $formState, string $form_id): void {
    if (in_array($form_id, ['user_login_form', 'user_login_block'], TRUE)) {
      array_unshift($form['#validate'], [$this, 'validateLoginAttempt']);
      $form['#validate'][] = [$this, 'postValidateLoginAttempt'];
    }
  }

  /**
   * Pre-validation handler for login forms.
   *
   * Stores the attempted username for potential failed login logging.
   */
  public function validateLoginAttempt(array &$form, FormStateInterface $formState): void {
    $username = $this->normalizeUsername((string) $formState->getValue('name'));
    if ($username !== '') {
      $formState->set('login_monitor_username', $username);
    }
  }

  /**
   * Post-validation handler for login forms.
   *
   * Logs failed login attempts if validation failed.
   */
  public function postValidateLoginAttempt(array &$form, FormStateInterface $formState): void {
    $username = $formState->get('login_monitor_username');

    if ($formState->hasAnyErrors() && !empty($username)) {
      $this->loginMonitorService->processFailure($username);
    }
  }

  /**
   * Normalizes a typed username from the login form.
   */
  private function normalizeUsername(string $username): string {
    return Unicode::truncate(trim($username), LoginMonitorLimits::USERNAME_MAX_LENGTH, TRUE, FALSE);
  }

}
