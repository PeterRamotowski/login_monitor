<?php

namespace Drupal\Tests\login_monitor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\login_monitor\LoginEventType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests the Login Monitor logs saving.
 */
#[Group('login_monitor')]
#[IgnoreDeprecations]
class LoginLogSavingLogsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['login_monitor', 'user', 'system', 'options'];

  /**
   * The login log service.
   *
   * @var \Drupal\login_monitor\Service\LoginLogService
   */
  protected $loginLogService;

  /**
   * The login monitor service.
   *
   * @var \Drupal\login_monitor\Service\LoginMonitorService
   */
  protected $loginMonitorService;

  /**
   * The login stats service.
   *
   * @var \Drupal\login_monitor\Service\LoginStatsService
   */
  protected $loginStatsService;

  /**
   * The login event data service.
   *
   * @var \Drupal\login_monitor\Service\LoginEventData
   */
  protected $loginEventData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('login_log');
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['login_monitor']);

    // Create the sessions table manually for testing.
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

    $this->loginLogService = $this->container->get('login_monitor.login_log_service');
    $this->loginMonitorService = $this->container->get('login_monitor.login_monitor_service');
    $this->loginStatsService = $this->container->get('login_monitor.stats_service');
    $this->loginEventData = $this->container->get('login_monitor.login_event_data');
  }

  /**
   * Test event type constants are defined correctly.
   */
  public function testEventTypeConstants(): void {
    $this->assertEquals('success_login', LoginEventType::SuccessLogin->value);
    $this->assertEquals('success_login_onetime', LoginEventType::SuccessLoginOnetime->value);
    $this->assertEquals('failed_login_invalid_user', LoginEventType::FailedLoginInvalidUser->value);
    $this->assertEquals('failed_login_valid_user', LoginEventType::FailedLoginValidUser->value);
    $this->assertEquals('failed_login_blocked_user', LoginEventType::FailedLoginBlockedUser->value);
    $this->assertEquals('logout', LoginEventType::Logout->value);
  }

  /**
   * Test successful login logging.
   */
  public function testSuccessfulLoginLogging(): void {
    $user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $user->save();

    // Log a successful login.
    $this->loginEventData->setUser($user);
    $this->loginLogService->saveLog(LoginEventType::SuccessLogin, $this->loginEventData);

    // Verify the log entry.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties(['user_id' => $user->id()]);

    $this->assertCount(1, $logs);
    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::SuccessLogin->value, $log->getEventType());
    $this->assertEquals($user->id(), $log->get('user_id')->target_id);
  }

  /**
   * Test successful one-time login logging.
   */
  public function testSuccessfulOneTimeLoginLogging(): void {
    $user = User::create([
      'name' => 'testuseronetime',
      'mail' => 'testonetime@example.com',
      'status' => 1,
    ]);
    $user->save();

    // Log a successful one-time login.
    $this->loginEventData->setUser($user);
    $this->loginLogService->saveLog(LoginEventType::SuccessLoginOnetime, $this->loginEventData);

    // Verify the log entry.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties(['event_type' => LoginEventType::SuccessLoginOnetime->value]);

    $this->assertCount(1, $logs);
    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::SuccessLoginOnetime->value, $log->getEventType());
    $this->assertEquals($user->id(), $log->get('user_id')->target_id);

    // Verify the event type label is correct.
    $this->assertEquals('Successful One-time Login', LoginEventType::SuccessLoginOnetime->getLabel()->render());
  }

  /**
   * Test failed login logging for invalid user.
   */
  public function testFailedLoginInvalidUser(): void {
    // Log a failed login for non-existent user.
    $this->loginMonitorService->processFailure('nonexistentuser');

    // Verify the log entry.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties(['event_type' => LoginEventType::FailedLoginInvalidUser->value]);

    $this->assertCount(1, $logs);
    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::FailedLoginInvalidUser->value, $log->getEventType());
    $this->assertNull($log->get('user_id')->target_id);
  }

  /**
   * Test failed login logging for valid user.
   */
  public function testFailedLoginValidUser(): void {
    $user = User::create([
      'name' => 'testuser2',
      'mail' => 'test2@example.com',
      'status' => 1,
    ]);
    $user->save();

    // Log a failed login for existing user.
    $this->loginMonitorService->processFailure('testuser2');

    // Verify the log entry.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties(['event_type' => LoginEventType::FailedLoginValidUser->value]);

    $this->assertCount(1, $logs);
    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::FailedLoginValidUser->value, $log->getEventType());
    $this->assertEquals($user->id(), $log->get('user_id')->target_id);
  }

  /**
   * Tests blocked user login attempt logging.
   */
  public function testBlockedUserLoginAttempt(): void {
    $user = User::create([
      'name' => 'blocked_user',
      'mail' => 'blocked@example.com',
      'status' => 0,
    ]);
    $user->save();

    $this->assertTrue($user->isBlocked());

    $this->loginMonitorService->processFailure('blocked_user');

    // Check that the log entry was created.
    $query = $this->container->get('entity_type.manager')
      ->getStorage('login_log')
      ->getQuery()
      ->condition('user_id', $user->id())
      ->condition('event_type', LoginEventType::FailedLoginBlockedUser->value)
      ->accessCheck(FALSE);

    $results = $query->execute();
    $this->assertCount(1, $results);
  }

  /**
   * Test logout logging.
   */
  public function testLogoutLogging(): void {
    $user = User::create([
      'name' => 'testuser3',
      'mail' => 'test3@example.com',
      'status' => 1,
    ]);
    $user->save();

    // Log a logout.
    $this->loginEventData->setUser($user);
    $this->loginLogService->saveLog(LoginEventType::Logout, $this->loginEventData);

    // Verify the log entry.
    $storage = $this->container->get('entity_type.manager')->getStorage('login_log');
    $logs = $storage->loadByProperties(['event_type' => LoginEventType::Logout->value]);

    $this->assertCount(1, $logs);
    /** @var \Drupal\login_monitor\Entity\LoginLogInterface $log */
    $log = reset($logs);
    $this->assertEquals(LoginEventType::Logout->value, $log->getEventType());
    $this->assertEquals($user->id(), $log->get('user_id')->target_id);
  }

  /**
   * Test event statistics functionality.
   */
  public function testEventStatistics(): void {
    // Create test users.
    $user1 = User::create(['name' => 'user1', 'mail' => 'user1@example.com', 'status' => 1]);
    $user1->save();

    $user2 = User::create(['name' => 'user2', 'mail' => 'user2@example.com', 'status' => 1]);
    $user2->save();

    // Log various events.
    $this->loginEventData->setUser($user1);
    $this->loginLogService->saveLog(LoginEventType::SuccessLogin, $this->loginEventData);
    $this->loginEventData->setUser($user2);
    $this->loginLogService->saveLog(LoginEventType::SuccessLogin, $this->loginEventData);
    $this->loginEventData->setUser($user1);
    $this->loginLogService->saveLog(LoginEventType::SuccessLoginOnetime, $this->loginEventData);
    $this->loginEventData->setUser($user1);
    $this->loginLogService->saveLog(LoginEventType::Logout, $this->loginEventData);
    $this->loginMonitorService->processFailure('invaliduser');
    $this->loginMonitorService->processFailure('user2');

    $stats = $this->loginStatsService->getEventStatistics();

    // Verify counts.
    $this->assertEquals(2, $stats[LoginEventType::SuccessLogin->value]);
    $this->assertEquals(1, $stats[LoginEventType::SuccessLoginOnetime->value]);
    $this->assertEquals(1, $stats[LoginEventType::Logout->value]);
    $this->assertEquals(1, $stats[LoginEventType::FailedLoginInvalidUser->value]);
    $this->assertEquals(1, $stats[LoginEventType::FailedLoginValidUser->value]);
  }

}
