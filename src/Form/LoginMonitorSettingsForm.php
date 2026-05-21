<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a settings form for Login Monitor module.
 */
final class LoginMonitorSettingsForm extends ConfigFormBase {

  /**
   * Constructs a LoginMonitorSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'login_monitor_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['login_monitor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('login_monitor.settings');

    // General logging settings.
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging settings'),
      '#open' => TRUE,
    ];

    $form['general']['enable_login_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable logging'),
      '#description' => $this->t('Save login records to the database.'),
      '#default_value' => $config->get('enable_login_logging') ?? TRUE,
    ];

    // Email notification settings.
    $form['email_notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Email notifications'),
      '#description' => $this->t('Configure email notifications for login events.'),
      '#open' => TRUE,
    ];

    $form['email_notifications']['send_email_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email notifications'),
      '#description' => $this->t('Enable email notifications when users log in.'),
      '#default_value' => $config->get('send_email_notifications') ?? FALSE,
    ];

    $form['email_notifications']['notification_delivery'] = [
      '#type' => 'radios',
      '#title' => $this->t('Delivery timing'),
      '#description' => $this->t('Choose when notifications are dispatched. <em>Immediate</em> sends the email as soon as the login event occurs. <em>Delayed (via cron)</em> queues notifications and sends them during the next cron run, which reduces request latency.'),
      '#options' => [
        'immediate' => $this->t('Immediate'),
        'delayed' => $this->t('Delayed (via cron)'),
      ],
      '#default_value' => $config->get('notification_delivery') ?? 'immediate',
      '#states' => [
        'visible' => [
          ':input[name="send_email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['email_notifications']['email_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Email recipient'),
      '#description' => $this->t('Email address to receive login notifications. Leave empty to use the site email address.'),
      '#default_value' => $config->get('email_recipient') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="send_email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['email_notifications']['email_content'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email content'),
      '#description' => $this->t('The content of the notification email. You can use tokens to insert dynamic content.'),
      '#default_value' => $config->get('email_content'),
      '#rows' => 10,
      '#states' => [
        'visible' => [
          ':input[name="send_email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['email_notifications']['notification_rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Notification rate limit'),
      '#description' => $this->t('Maximum email notifications per IP address and event type per hour. Set to 0 to disable notification rate limiting.'),
      '#default_value' => $config->get('notification_rate_limit') ?? 10,
      '#min' => 0,
      '#step' => 1,
      '#states' => [
        'visible' => [
          ':input[name="send_email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['email_notifications']['token_help'] = [
      '#theme' => 'token_tree_link',
      '#theme_wrappers' => ['container'],
      '#token_types' => ['login_monitor', 'user', 'site', 'current-date'],
      '#show_restricted' => TRUE,
      '#weight' => 90,
      '#states' => [
        'visible' => [
          ':input[name="send_email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // User role monitoring.
    $form['user_monitoring'] = [
      '#type' => 'details',
      '#title' => $this->t('User roles monitoring'),
      '#description' => $this->t('Configure which users to monitor.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          [':input[name="enable_login_logging"]' => ['checked' => TRUE]],
          'or',
          [':input[name="send_email_notifications"]' => ['checked' => TRUE]],
        ],
      ],
    ];

    $form['user_monitoring']['tracked_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('User roles to monitor'),
      '#description' => $this->t('Select which user roles should be monitored for login notifications and logging. If no roles are selected, all authenticated users will be monitored.'),
      '#options' => $this->getUserRoleOptions(),
      '#default_value' => $config->get('tracked_roles') ?? [],
    ];

    // Email reporting settings.
    $form['email_reports'] = [
      '#type' => 'details',
      '#title' => $this->t('Email reports'),
      '#description' => $this->t('Configure automated email reports with login statistics.'),
      '#open' => FALSE,
      '#weight' => 90,
    ];

    $form['email_reports']['enable_email_reports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable email reports'),
      '#description' => $this->t('Send periodic email reports with login statistics.'),
      '#default_value' => $config->get('enable_email_reports') ?? FALSE,
    ];

    $form['email_reports']['report_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Report frequency'),
      '#description' => $this->t('How often should reports be sent.'),
      '#options' => [
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
      ],
      '#default_value' => $config->get('report_frequency') ?? 'weekly',
      '#states' => [
        'visible' => [
          ':input[name="enable_email_reports"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['email_reports']['report_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Report recipient'),
      '#description' => $this->t('Email address to receive login reports. Leave empty to use the site email address.'),
      '#default_value' => $config->get('report_recipient') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="enable_email_reports"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Log retention settings.
    $form['log_retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Log retention'),
      '#description' => $this->t('Configure automatic cleanup of old login logs.'),
      '#open' => FALSE,
      '#weight' => 100,
    ];

    $form['log_retention']['enable_log_cleanup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic log cleanup'),
      '#description' => $this->t('Automatically delete old login logs via cron.'),
      '#default_value' => $config->get('enable_log_cleanup') ?? TRUE,
    ];

    $retention_options = [
      '1' => $this->t('1 day'),
      '7' => $this->t('1 week'),
      '30' => $this->t('1 month'),
      '90' => $this->t('3 months'),
      '180' => $this->t('6 months'),
      '365' => $this->t('1 year'),
    ];

    $form['log_retention']['log_retention_days'] = [
      '#type' => 'select',
      '#title' => $this->t('Delete logs older than'),
      '#description' => $this->t('Login logs older than the selected period will be automatically deleted during cron runs.'),
      '#options' => $retention_options,
      '#default_value' => (string) ($config->get('log_retention_days') ?? 90),
      '#states' => [
        'visible' => [
          ':input[name="enable_log_cleanup"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Filter out unchecked roles (they come as 0 values)
    $tracked_roles = array_filter($form_state->getValue('tracked_roles'));

    $this->config('login_monitor.settings')
      ->set('enable_login_logging', (bool) $form_state->getValue('enable_login_logging'))
      ->set('send_email_notifications', (bool) $form_state->getValue('send_email_notifications'))
      ->set('notification_delivery', $form_state->getValue('notification_delivery'))
      ->set('notification_rate_limit', (int) $form_state->getValue('notification_rate_limit'))
      ->set('tracked_roles', array_values($tracked_roles))
      ->set('email_content', $form_state->getValue('email_content'))
      ->set('email_recipient', $form_state->getValue('email_recipient'))
      ->set('enable_log_cleanup', (bool) $form_state->getValue('enable_log_cleanup'))
      ->set('log_retention_days', (int) $form_state->getValue('log_retention_days'))
      ->set('enable_email_reports', (bool) $form_state->getValue('enable_email_reports'))
      ->set('report_frequency', $form_state->getValue('report_frequency'))
      ->set('report_recipient', $form_state->getValue('report_recipient'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available user roles as options for the form.
   *
   * @return array
   *   An array of user role options.
   */
  private function getUserRoleOptions(): array {
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $options = [];

    foreach ($roles as $role_id => $role) {
      // Skip anonymous role as it doesn't make sense to monitor.
      if ($role_id !== 'anonymous') {
        $options[$role_id] = $role->label();
      }
    }

    return $options;
  }

}
