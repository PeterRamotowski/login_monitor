<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\login_monitor\Form\LoginLogDeleteForm;
use Drupal\login_monitor\LoginEventType;
use Drupal\login_monitor\LoginLogListBuilder;
use Drupal\login_monitor\LoginMonitorLimits;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;
use Drupal\views\EntityViewsData;

/**
 * Defines the Login Log entity.
 */
#[ContentEntityType(
  id: "login_log",
  label: new TranslatableMarkup("Login Log"),
  label_count: [
    'singular' => '@count login log',
    'plural' => '@count login logs',
  ],
  handlers: [
    "view_builder" => EntityViewBuilder::class,
    "list_builder" => LoginLogListBuilder::class,
    "views_data" => EntityViewsData::class,
    "form" => [
      "delete" => LoginLogDeleteForm::class,
    ],
    "route_provider" => [
      "html" => AdminHtmlRouteProvider::class,
    ],
    "access" => EntityAccessControlHandler::class,
  ],
  base_table: "login_log",
  admin_permission: "administer login log entities",
  collection_permission: "view login log entities",
  entity_keys: [
    "id" => "id",
    "label" => "id",
    "uuid" => "uuid",
    "owner" => "uid",
  ],
  links: [
    "collection" => "/admin/reports/logins",
    "delete-form" => "/admin/reports/logins/{login_log}/delete",
  ],
  field_ui_base_route: "entity.login_log.collection"
)]
class LoginLog extends ContentEntityBase implements LoginLogInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['event_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Event Type'))
      ->setDescription(t('The type of login event.'))
      ->setSettings([
        'allowed_values' => LoginEventType::getOptions(),
      ])
      ->setRequired(TRUE)
      ->setDefaultValue(LoginEventType::SuccessLogin->value)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the login was happened.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['concurrent_sessions'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Concurrent Sessions'))
      ->setDescription(t('The number of active sessions at the time of login.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 20,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['ip_address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IP Address'))
      ->setDescription(t('The IP address from which the user logged in.'))
      ->setSettings([
        // IPv6 max length.
        'max_length' => 39,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 30,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user_agent'] = BaseFieldDefinition::create('string')
      ->setLabel(t('User Agent'))
      ->setDescription(t('The user agent string from the browser that was used to log in.'))
      ->setSettings([
        'max_length' => LoginMonitorLimits::USER_AGENT_MAX_LENGTH,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 40,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['typed_username'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Typed Username'))
      ->setDescription(t('The username that was typed in the login form (for failed login attempts).'))
      ->setSettings([
        'max_length' => LoginMonitorLimits::USERNAME_MAX_LENGTH,
        'text_processing' => 0,
      ])
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 50,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 50,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the user entity.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity or null if not set.
   */
  public function getUser(): ?UserInterface {
    $user = $this->get('uid')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Sets the user entity.
   *
   * @param \Drupal\user\UserInterface|null $user
   *   The user entity.
   *
   * @return $this
   */
  public function setUser(?UserInterface $user = NULL) {
    $this->set('uid', $user ? $user->id() : NULL);
    return $this;
  }

  /**
   * Gets the created timestamp.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Sets the created timestamp.
   *
   * @param int $timestamp
   *   The created timestamp.
   *
   * @return $this
   */
  public function setCreatedTime(int $timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * Gets the number of concurrent sessions.
   *
   * @return int
   *   The number of concurrent sessions.
   */
  public function getConcurrentSessions(): int {
    return (int) $this->get('concurrent_sessions')->value;
  }

  /**
   * Sets the number of concurrent sessions.
   *
   * @param int $sessions
   *   The number of concurrent sessions.
   *
   * @return $this
   */
  public function setConcurrentSessions(int $sessions) {
    $this->set('concurrent_sessions', $sessions);
    return $this;
  }

  /**
   * Gets the IP address.
   *
   * @return string|null
   *   The IP address.
   */
  public function getIpAddress(): ?string {
    return $this->get('ip_address')->value;
  }

  /**
   * Sets the IP address.
   *
   * @param string|null $ip_address
   *   The IP address.
   *
   * @return $this
   */
  public function setIpAddress(?string $ip_address = NULL) {
    $this->set('ip_address', $ip_address);
    return $this;
  }

  /**
   * Gets the user agent.
   *
   * @return string|null
   *   The user agent string.
   */
  public function getUserAgent(): ?string {
    return $this->get('user_agent')->value;
  }

  /**
   * Sets the user agent.
   *
   * @param string|null $user_agent
   *   The user agent string.
   *
   * @return $this
   */
  public function setUserAgent(?string $user_agent = NULL) {
    $this->set('user_agent', $user_agent);
    return $this;
  }

  /**
   * Gets the event type.
   *
   * @return string|null
   *   The event type.
   */
  public function getEventType(): ?string {
    return $this->get('event_type')->value;
  }

  /**
   * Sets the event type.
   *
   * @param string|null $event_type
   *   The event type.
   *
   * @return $this
   */
  public function setEventType(?string $event_type = NULL) {
    $this->set('event_type', $event_type);
    return $this;
  }

  /**
   * Gets the typed username.
   *
   * @return string|null
   *   The typed username.
   */
  public function getTypedUsername(): ?string {
    return $this->get('typed_username')->value;
  }

  /**
   * Sets the typed username.
   *
   * @param string|null $typed_username
   *   The typed username.
   *
   * @return $this
   */
  public function setTypedUsername(?string $typed_username = NULL) {
    $this->set('typed_username', $typed_username);
    return $this;
  }

  /**
   * Gets the human-readable label for the event type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The human-readable label for the event type.
   */
  public function getEventTypeLabel() {
    $eventType = $this->getEventType();
    if ($eventType) {
      try {
        $eventTypeEnum = LoginEventType::from($eventType);
        return $eventTypeEnum->getLabel();
      }
      catch (\ValueError $e) {
        return $eventType;
      }
    }
    return '';
  }

  /**
   * Get available event types.
   *
   * @return array
   *   Array of event types with their labels.
   */
  public static function getEventTypes(): array {
    return LoginEventType::getOptions();
  }

}
