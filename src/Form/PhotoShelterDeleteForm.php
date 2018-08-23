<?php

namespace Drupal\photoshelter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class PhotoShelterDeleteForm.
 *
 * @package Drupal\photoshelter\Form
 */
class PhotoShelterDeleteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photoshelter_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => t('Delete all sync data'),
    ];

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Delete media entities.
    $query = \Drupal::entityQuery('media');
    $query->condition('bundle', 'ps_image');
    $mids = $query->execute();
    if (!empty($mids)) {
      $medias = \Drupal::entityTypeManager()->getStorage('media')->loadMultiple($mids);
      \Drupal::entityTypeManager()->getStorage('media')->delete($medias);
    }

    // Delete media entities.
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', '%' . db_like('ps_') . '%', 'LIKE');
    $tids = $query->execute();
    if (!empty($tids)) {
      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);
      \Drupal::entityTypeManager()->getStorage('taxonomy_term')->delete($terms);
    }

    $config = \Drupal::service('config.factory')->getEditable('photoshelter.settings');
    $config->set('last_sync', 'Never');
    $config->save();

  }

}
