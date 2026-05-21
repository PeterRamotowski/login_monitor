<?php

declare(strict_types=1);

namespace Drupal\Tests\login_monitor\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests access control for login monitor admin pages.
 */
#[Group('login_monitor')]
#[IgnoreDeprecations]
class LoginMonitorAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['login_monitor', 'user', 'system', 'options'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A regular user with no special permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * A user with permission to view login logs.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $viewUser;

  /**
   * A user with permission to administer login monitor settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A user with both view and admin permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $fullAccessUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create users with different permission levels.
    $this->regularUser = $this->drupalCreateUser([
      'access content',
    ]);

    $this->viewUser = $this->drupalCreateUser([
      'access content',
      'view login log entities',
      'access administration pages',
    ]);

    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'administer login monitor settings',
      'access administration pages',
    ]);

    $this->fullAccessUser = $this->drupalCreateUser([
      'access content',
      'view login log entities',
      'administer login monitor settings',
      'access administration pages',
      'access site reports',
    ]);

    // Enable login logging.
    $this->config('login_monitor.settings')
      ->set('enable_login_logging', TRUE)
      ->save();
  }

  /**
   * Test access to the settings page for users with different permissions.
   */
  public function testSettingsPageAccess(): void {
    $settings_url = '/admin/config/people/login-monitor';

    // Test anonymous user access - should be denied.
    $this->drupalGet($settings_url);
    $this->assertSession()->statusCodeEquals(403);

    // Test regular user access - should be denied.
    $this->drupalLogin($this->regularUser);
    $this->drupalGet($settings_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Test user with only view permissions - should be denied.
    $this->drupalLogin($this->viewUser);
    $this->drupalGet($settings_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Test user with admin permissions - should be allowed.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($settings_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Login monitor settings');
    $this->drupalLogout();

    // Test user with full access - should be allowed.
    $this->drupalLogin($this->fullAccessUser);
    $this->drupalGet($settings_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Login monitor settings');
    $this->drupalLogout();
  }

  /**
   * Test access to the reports page for users with different permissions.
   */
  public function testReportsPageAccess(): void {
    $reports_url = '/admin/reports/logins';

    // Test anonymous user access - should be denied.
    $this->drupalGet($reports_url);
    $this->assertSession()->statusCodeEquals(403);

    // Test regular user access - should be denied.
    $this->drupalLogin($this->regularUser);
    $this->drupalGet($reports_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Test user with only admin permissions - should be denied.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($reports_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Test user with view permissions - should be allowed.
    $this->drupalLogin($this->viewUser);
    $this->drupalGet($reports_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();

    // Test user with full access - should be allowed.
    $this->drupalLogin($this->fullAccessUser);
    $this->drupalGet($reports_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();
  }

  /**
   * Test that permission combinations work correctly.
   */
  public function testPermissionCombinations(): void {
    // User with both permissions should access both pages.
    $this->drupalLogin($this->fullAccessUser);

    $this->drupalGet('/admin/config/people/login-monitor');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/admin/reports/logins');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();

    // User with only view permission cannot access settings.
    $this->drupalLogin($this->viewUser);

    $this->drupalGet('/admin/reports/logins');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/admin/config/people/login-monitor');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogout();

    // User with only admin permission cannot access reports.
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/people/login-monitor');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/admin/reports/logins');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogout();
  }

}
