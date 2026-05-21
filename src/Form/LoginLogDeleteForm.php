<?php

declare(strict_types=1);

namespace Drupal\login_monitor\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Provides a form for deleting Login Log entities.
 */
final class LoginLogDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to delete the login log %name?', [
      '%name' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeletionMessage(): TranslatableMarkup {
    return $this->t('The login log %label has been deleted.', [
      '%label' => $this->entity->label(),
    ]);
  }

}
