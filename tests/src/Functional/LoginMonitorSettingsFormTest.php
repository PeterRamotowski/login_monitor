<?php

namespace Drupal\Tests\login_monitor\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests the login monitor settings form.
 */
#[Group('login_monitor')]
#[IgnoreDeprecations]
class LoginMonitorSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['login_monitor', 'user', 'system', 'options'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to administer login monitor settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer login monitor settings',
      'access administration pages',
    ]);
  }

  /**
   * Test that the settings form displays all sections.
   */
  public function testSettingsFormDisplay(): void {
    $this->drupalLogin($this->adminUser);

    // Visit the settings form.
    $this->drupalGet('/admin/config/people/login-monitor');
    $this->assertSession()->statusCodeEquals(200);

    // Check that all main sections are present.
    $this->assertSession()->pageTextContains('Logging Settings');
    $this->assertSession()->pageTextContains('User roles monitoring');
    $this->assertSession()->pageTextContains('Email notifications');
    $this->assertSession()->pageTextContains('Email reports');
    $this->assertSession()->pageTextContains('Log retention');
  }

  /**
   * Test that the form saves all configuration values correctly.
   */
  public function testSettingsFormSave(): void {
    $this->drupalLogin($this->adminUser);

    // Submit the form with specific values.
    $edit = [
      'enable_login_logging' => TRUE,
      'send_email_notifications' => FALSE,
    ];

    $this->drupalGet('/admin/config/people/login-monitor');
    $this->submitForm($edit, 'Save configuration');

    // Verify success message.
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Verify that the configuration was saved correctly.
    $main_config = $this->config('login_monitor.settings');

    $this->assertTrue($main_config->get('enable_login_logging'));
    $this->assertFalse($main_config->get('send_email_notifications'));
  }

}
