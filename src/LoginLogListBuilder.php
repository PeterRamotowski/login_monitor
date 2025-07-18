<?php

namespace Drupal\login_monitor;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of Login Log entities.
 */
class LoginLogListBuilder extends EntityListBuilder {

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
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, DateFormatterInterface $date_formatter) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['created'] = $this->t('Created');
    $header['user'] = $this->t('User');
    $header['typed_username'] = $this->t('Typed Username');
    $header['ip_address'] = $this->t('IP Address');
    $header['user_agent'] = $this->t('User Agent');
    $header['concurrent_sessions'] = $this->t('Concurrent Sessions');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\login_monitor\Entity\LoginLog $entity */
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime());
    $row['user'] = $entity->getUser() ? $entity->getUser()->getDisplayName() : $this->t('N/A');
    $row['typed_username'] = $entity->getTypedUsername() ?: $this->t('N/A');
    $row['ip_address'] = $entity->getIpAddress() ?: $this->t('N/A');
    $user_agent = $entity->getUserAgent();
    $row['user_agent'] = $user_agent ? (strlen($user_agent) > 50 ? substr($user_agent, 0, 50) . '...' : $user_agent) : $this->t('N/A');
    $row['concurrent_sessions'] = $entity->getConcurrentSessions() ?? 1;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 10,
        'url' => $entity->toUrl('canonical'),
      ];
    }

    return $operations;
  }

}
