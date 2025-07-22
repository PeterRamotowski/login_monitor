<?php

namespace Drupal\Tests\login_monitor\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\login_monitor\LoginEventType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests the login monitor functionality using real browser interactions.
 */
#[Group('login_monitor')]
#[IgnoreDeprecations]
class LoginMonitorLoginTest extends BrowserTestBase {

  use AssertMailTrait {
    getMails as drupalGetMails;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['login_monitor', 'user', 'system', 'options'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A regular user for testing.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $testUser;

  /**
   * A user with permission to view login logs.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create the sessions table for testing since the login monitor uses it.
    $this->container->get('database')->schema()->createTable('sessions', [
      'fields' => [
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'sid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE],
        'hostname' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'timestamp' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'session' => ['type' => 'blob', 'not null' => FALSE, 'size' => 'big'],
      ],
      'primary key' => ['sid'],
      'indexes' => [
        'timestamp' => ['timestamp'],
        'uid' => ['uid'],
      ],
    ]);

    // Create a test user.
    $this->testUser = $this->drupalCreateUser([]);

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'view login log entities',
      'administer login monitor settings',
      'access administration pages',
    ]);

    // Enable login logging.
    $this->config('login_monitor.settings')
      ->set('enable_login_logging', TRUE)
      ->save();
  }

  /**
   * Helper method to clear all existing login logs.
   */
  protected function clearLoginLogs(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $existing_logs = $storage->loadMultiple();
    if (!empty($existing_logs)) {
      $storage->delete($existing_logs);
    }
  }

  /**
   * Test successful login creates a login log entry via form submission.
   */
  public function testSuccessfulLoginLogging(): void {
    $this->clearLoginLogs();

    // Create a user with a specific password for form testing.
    $test_password = 'test_password_123';
    $form_test_user = $this->drupalCreateUser([], 'formtestuser');
    $form_test_user->setPassword($test_password);
    $form_test_user->save();

    // Navigate to login form.
    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('pass');

    // Submit login form with correct credentials.
    $this->submitForm([
      'name' => $form_test_user->getAccountName(),
      'pass' => $test_password,
    ], 'Log in');

    // Verify successful login by checking URL redirection.
    $this->assertSession()->statusCodeEquals(200);
    $current_url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/user/', $current_url, 'Should be redirected to user page after login.');

    // Check that a login log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'uid' => $form_test_user->id(),
      'event_type' => LoginEventType::SuccessLogin->value,
    ]);

    $this->assertCount(1, $logs, 'Successful login should be logged.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::SuccessLogin->value, $log->getEventType());
    $this->assertEquals($form_test_user->id(), $log->get('uid')->target_id);
  }

  /**
   * Test failed login with invalid username creates appropriate log entry.
   */
  public function testFailedLoginInvalidUserLogging(): void {
    $this->clearLoginLogs();

    // Navigate to login form.
    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('pass');

    // Submit login form with invalid username.
    $this->submitForm([
      'name' => 'nonexistentuser',
      'pass' => 'anypassword',
    ], 'Log in');

    // Verify login failed - should still be on the login page.
    $this->assertSession()->statusCodeEquals(200);
    // Still on login form.
    $this->assertSession()->fieldExists('name');

    // Check that a failed login log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'event_type' => LoginEventType::FailedLoginInvalidUser->value,
    ]);

    $this->assertCount(1, $logs, 'Failed login with invalid user should be logged.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::FailedLoginInvalidUser->value, $log->getEventType());
  }

  /**
   * Test failed login with valid username but wrong password.
   */
  public function testFailedLoginValidUserLogging(): void {
    $this->clearLoginLogs();

    // Create a user with a known password.
    $test_password = 'correct_password_123';
    $form_test_user = $this->drupalCreateUser([], 'validuser');
    $form_test_user->setPassword($test_password);
    $form_test_user->save();

    // Navigate to login form.
    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('pass');

    // Submit login form with valid username but wrong password.
    $this->submitForm([
      'name' => $form_test_user->getAccountName(),
      'pass' => 'wrong_password',
    ], 'Log in');

    // Verify login failed - should still be on the login page.
    $this->assertSession()->statusCodeEquals(200);
    // Still on login form.
    $this->assertSession()->fieldExists('name');

    // Check that a failed login log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'event_type' => LoginEventType::FailedLoginValidUser->value,
    ]);

    $this->assertCount(1, $logs, 'Failed login with valid user should be logged.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::FailedLoginValidUser->value, $log->getEventType());
  }

  /**
   * Test logout creates a logout log entry.
   */
  public function testLogoutLogging(): void {
    $this->clearLoginLogs();

    // Create a user with a known password and login via form.
    $test_password = 'test_password_123';
    $form_test_user = $this->drupalCreateUser([], 'logoutuser');
    $form_test_user->setPassword($test_password);
    $form_test_user->save();

    // Login via form first.
    $this->drupalGet('/user/login');
    $this->submitForm([
      'name' => $form_test_user->getAccountName(),
      'pass' => $test_password,
    ], 'Log in');

    // Verify we're logged in.
    $current_url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/user/', $current_url);

    // Clear login logs to focus on logout.
    $this->clearLoginLogs();

    // Use drupalLogout() which properly triggers the logout process.
    $this->drupalLogout();

    // Check that a logout log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'uid' => $form_test_user->id(),
      'event_type' => LoginEventType::Logout->value,
    ]);

    $this->assertCount(1, $logs, 'Logout should be logged.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::Logout->value, $log->getEventType());
    $this->assertEquals($form_test_user->id(), $log->get('uid')->target_id);
  }

  /**
   * Test one-time login functionality via browser password reset workflow.
   */
  public function testOneTimeLoginLogging(): void {
    $this->clearLoginLogs();

    // Create a user with known credentials for testing.
    $test_password = 'test_password_123';
    $onetime_user = $this->drupalCreateUser([], 'onetimeuser');
    $onetime_user->setPassword($test_password);
    $onetime_user->save();

    // Set the last login time that is used to generate the one-time link so
    // that it is definitely over a second ago (required for password reset).
    $onetime_user->setLastLoginTime(\Drupal::time()->getRequestTime() - mt_rand(10, 100));
    $onetime_user->save();

    // Request a password reset to get a one-time login link.
    $this->drupalGet('user/password');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');

    // Submit password reset form.
    $this->submitForm([
      'name' => $onetime_user->getAccountName(),
    ], 'Submit');

    // Verify password reset request was successful.
    $this->assertSession()->pageTextContains('If ' . $onetime_user->getAccountName() . ' is a valid account, an email will be sent with instructions');

    // Extract the one-time login URL from the email.
    $reset_url = $this->getResetUrl();
    $this->assertNotEmpty($reset_url, 'Password reset URL should be generated.');

    // Use the one-time login URL for automatic login.
    $this->drupalGet($reset_url . '/login');

    // Verify successful one-time login by checking URL redirection.
    $this->assertSession()->statusCodeEquals(200);
    $current_url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/user/', $current_url, 'Should be redirected to user page after one-time login.');

    // Check that a one-time login log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'uid' => $onetime_user->id(),
      'event_type' => LoginEventType::SuccessLoginOnetime->value,
    ]);

    $this->assertCount(1, $logs, 'One-time login should be logged with correct event type.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::SuccessLoginOnetime->value, $log->getEventType());
    $this->assertEquals($onetime_user->id(), $log->get('uid')->target_id);
  }

  /**
   * Helper function to extract password reset URL from email.
   *
   * @return string
   *   The password reset URL from the most recent email.
   */
  protected function getResetUrl(): string {
    // Assume the most recent email.
    $_emails = $this->drupalGetMails();
    $email = end($_emails);
    $urls = [];
    if (preg_match('#.+user/reset/.+#', $email['body'], $urls)) {
      return $urls[0];
    }
    return '';
  }

  /**
   * Test login log displays show different event types via browser.
   */
  public function testLoginLogDisplay(): void {
    $this->clearLoginLogs();

    // Create log entries directly to ensure they exist for display testing.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');

    // Create a regular login log.
    $regularLog = $storage->create([
      'uid' => $this->testUser->id(),
      'event_type' => LoginEventType::SuccessLogin->value,
      'ip_address' => '127.0.0.1',
      'user_agent' => 'Test Browser',
      'created' => \Drupal::time()->getRequestTime(),
      'typed_username' => $this->testUser->getDisplayName(),
    ]);
    $regularLog->save();

    // Create a logout log.
    $logoutLog = $storage->create([
      'uid' => $this->testUser->id(),
      'event_type' => LoginEventType::Logout->value,
      'ip_address' => '127.0.0.1',
      'user_agent' => 'Test Browser',
      'created' => \Drupal::time()->getRequestTime(),
      'typed_username' => $this->testUser->getDisplayName(),
    ]);
    $logoutLog->save();

    // Create a failed login log.
    $failedLog = $storage->create([
    // Failed logins might not have a uid.
      'uid' => 0,
      'event_type' => LoginEventType::FailedLoginInvalidUser->value,
      'ip_address' => '127.0.0.1',
      'user_agent' => 'Test Browser',
      'username' => 'nonexistentuser',
      'created' => \Drupal::time()->getRequestTime(),
    ]);
    $failedLog->save();

    // Login as admin to view the logs.
    $this->drupalLogin($this->adminUser);

    // Visit the login log page.
    $this->drupalGet('/admin/reports/logins');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the page contains login log information.
    // The exact text may vary, so we check for general indicators.
    $this->assertSession()->pageTextContains($this->testUser->getDisplayName());
    $this->assertSession()->pageTextContains('127.0.0.1');

    // Verify we have log entries displayed.
    $page_content = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('login', strtolower($page_content), 'Page should contain login-related content.');
  }

  /**
   * Test that blocked user login attempts are logged correctly.
   */
  public function testBlockedUserLoginLogging(): void {
    $this->clearLoginLogs();

    // Create a user with a known password.
    $test_password = 'test_password_123';
    $blocked_user = $this->drupalCreateUser([], 'blockeduser');
    $blocked_user->setPassword($test_password);
    // Block the user.
    $blocked_user->block();
    $blocked_user->save();

    // Navigate to login form.
    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('pass');

    // Submit login form with blocked user credentials.
    $this->submitForm([
      'name' => $blocked_user->getAccountName(),
      'pass' => $test_password,
    ], 'Log in');

    // Verify login failed - should still be on the login page.
    $this->assertSession()->statusCodeEquals(200);
    // Still on login form.
    $this->assertSession()->fieldExists('name');

    // Check that a blocked user login log entry was created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties([
      'event_type' => LoginEventType::FailedLoginBlockedUser->value,
    ]);

    $this->assertCount(1, $logs, 'Failed login with blocked user should be logged.');

    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::FailedLoginBlockedUser->value, $log->getEventType());
  }

  /**
   * Test multiple login attempts create multiple log entries.
   */
  public function testMultipleLoginAttempts(): void {
    $this->clearLoginLogs();

    // Create a user with a known password.
    $test_password = 'test_password_123';
    $multi_user = $this->drupalCreateUser([], 'multiuser');
    $multi_user->setPassword($test_password);
    $multi_user->save();

    // Perform multiple login/logout cycles via browser forms.
    for ($i = 0; $i < 2; $i++) {
      // Login via form.
      $this->drupalGet('/user/login');
      $this->assertSession()->fieldExists('name');
      $this->assertSession()->fieldExists('pass');

      $this->submitForm([
        'name' => $multi_user->getAccountName(),
        'pass' => $test_password,
      ], 'Log in');

      // Verify successful login.
      $current_url = $this->getSession()->getCurrentUrl();
      $this->assertStringContainsString('/user/', $current_url);

      // Logout using drupalLogout() method.
      $this->drupalLogout();
    }

    // Check that multiple login log entries were created.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $loginLogs = $storage->loadByProperties([
      'uid' => $multi_user->id(),
      'event_type' => LoginEventType::SuccessLogin->value,
    ]);

    $logoutLogs = $storage->loadByProperties([
      'uid' => $multi_user->id(),
      'event_type' => LoginEventType::Logout->value,
    ]);

    $this->assertCount(2, $loginLogs, 'Should have 2 successful login log entries.');
    $this->assertCount(2, $logoutLogs, 'Should have 2 logout log entries.');
  }

  /**
   * Test event type labels.
   */
  public function testEventTypeLabels(): void {
    // Test that all event types have proper labels.
    $this->assertEquals('Successful Login', LoginEventType::SuccessLogin->getLabel()->render());
    $this->assertEquals('Successful One-time Login', LoginEventType::SuccessLoginOnetime->getLabel()->render());
    $this->assertEquals('Failed Login - Invalid User', LoginEventType::FailedLoginInvalidUser->getLabel()->render());
    $this->assertEquals('Failed Login - Valid User', LoginEventType::FailedLoginValidUser->getLabel()->render());
    $this->assertEquals('Failed Login - Blocked User', LoginEventType::FailedLoginBlockedUser->getLabel()->render());
    $this->assertEquals('Logout', LoginEventType::Logout->getLabel()->render());
  }

}
