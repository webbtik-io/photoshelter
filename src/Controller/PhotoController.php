<?php

namespace Drupal\photoshelter\Controller;

use Drupal\Core\Controller\ControllerBase;

class PhotoController extends ControllerBase {

  /**
   * Display the markup
   *
   * @return array
   */
  public function content() {
    return array(
      '#type' => 'markup',
      '#markup' => $this->t('Hello, World!'),
    );
  }

}