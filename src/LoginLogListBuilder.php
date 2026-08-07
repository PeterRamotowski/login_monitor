<?php

declare(strict_types=1);

namespace Drupal\login_monitor;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\login_monitor\Entity\LoginLogInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of Login Log entities.
 */
final class LoginLogListBuilder extends EntityListBuilder {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new LoginLogListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct(
      $entity_type,
      $entity_type_manager->getStorage($entity_type->id()),
    );
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new self(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entityQuery = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->pager(10)
      ->sort('created', 'DESC');

    return $this->storage->loadMultiple($entityQuery->execute());
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['created'] = $this->t('Event date');
    $header['event_type'] = $this->t('Event type');
    $header['typed_username'] = $this->t('Username');
    $header['concurrent_sessions'] = $this->t('Concurrent Sessions');
    $header['user_roles'] = $this->t('User roles');
    $header['ip_address'] = $this->t('IP Address');
    $header['user_agent'] = $this->t('User Agent');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\login_monitor\Entity\LoginLog $entity */
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime());
    $row['event_type'] = $entity->getEventTypeLabel();
    $row['typed_username'] = $entity->getTypedUsername() ?: '';
    $row['concurrent_sessions'] = $entity->getConcurrentSessions() ?? 1;
    $row['user_roles'] = $this->getUserRoles($entity);
    $row['ip_address'] = $entity->getIpAddress() ?: '';
    $user_agent = $entity->getUserAgent();
    $row['user_agent'] = $user_agent ? Unicode::truncate($user_agent, 50, TRUE, TRUE) : '';
    return $row + parent::buildRow($entity);
  }

  /**
   * Gets the user roles label for a login log entity.
   *
   * @param \Drupal\login_monitor\Entity\LoginLogInterface $entity
   *   The login log entity.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The user roles label or empty string.
   */
  protected function getUserRoles(LoginLogInterface $entity): string|TranslatableMarkup {
    $user = $entity->getUser();
    if (!$user || $user->isAnonymous()) {
      return '';
    }

    $roles = $user->getRoles(TRUE);
    if (empty($roles)) {
      return '';
    }

    $roleStorage = $this->entityTypeManager->getStorage('user_role');
    $roleEntities = $roleStorage->loadMultiple($roles);
    $roleLabels = array_map(
      static fn($role): string => (string) $role->label(),
      $roleEntities,
    );

    return implode(', ', $roleLabels);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(
    EntityInterface $entity,
    ?CacheableMetadata $cacheability = NULL,
  ) {
    $operations = parent::getDefaultOperations(...func_get_args());
    $viewAccess = $entity->access('view', NULL, TRUE);
    $cacheability?->addCacheableDependency($viewAccess);

    if ($viewAccess->isAllowed() && $entity->hasLinkTemplate('canonical')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 10,
        'url' => $entity->toUrl('canonical'),
      ];
    }

    return $operations;
  }

}
