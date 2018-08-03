<?php

namespace Drupal\photoshelter\Form;

use DateTime;
use DateTimeZone;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class PhotoShelterConfigForm.
 *
 * @package Drupal\photoshelter\Form
 */
class PhotoShelterConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photoshelter_config_form';
  }

  /**
   * {@inheritdoc}.
   */
  protected function getEditableConfigNames() {
    return ['photoshelter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form              = parent::buildForm($form, $form_state);
    $config            = $this->config('photoshelter.settings');
    $form['email']     = [
      '#type'          => 'email',
      '#title'         => $this->t('The email associated with your PhotoShelter account.'),
      '#default_value' => $config->get('email'),
    ];
    $form['password']  = [
      '#type'          => 'password',
      '#title'         => $this->t('Your PhotoShelter account password.'),
      '#description'   => $this->t('You can leave this field empty if it has been set before'),
      '#default_value' => $config->get('password'),
    ];
    $form['api_key']   = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Your PhotoShelter API key'),
      '#default_value' => $config->get('api_key'),
    ];
    $form['allow_private'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow synchronization of private files'),
      '#default_value' => $config->get('allow_private'),
    ];
    $form['cron_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set a daily automatic synchronization'),
      '#default_value' => $config->get('cron_sync'),
    ];
    $form['max_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum width'),
      '#description' => $this->t('Choose the maximum width for the photos in pixels, (ie: 700)'),
      '#required' => TRUE,
      '#min' => 100,
      '#size' => 4,
      '#default_value' => $config->get('max_width'),
    ];
    $form['max_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum height'),
      '#description' => $this->t('Choose the maximum height for the photos in pixels, (ie: 700)'),
      '#required' => TRUE,
      '#min' => 100,
      '#size' => 4,
      '#default_value' => $config->get('max_height'),
    ];
    $form['get_collection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Get collections names'),
      '#submit' => ['::getCollectionsNames'],
    ];
    $collection_names = $config->get('collections_names');
    if (isset($collection_names) && !empty($collection_names)) {
      $form['collections'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Collections'),
        '#options' => $collection_names,
        '#description' => $this->t('Choose collections to synchronize'),
        '#required' => TRUE,
        '#default_value' => $config->get('collections'),
      ];
      $form['sync_new'] = [
        '#type'  => 'submit',
        '#value' => t('Sync New Additions'),
        '#submit' => ['::syncNewSubmit'],
      ];
      $form['sync_full'] = [
        '#type'  => 'submit',
        '#value' => 'Sync All Data',
        '#submit' => ['::syncFullSubmit'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->saveConfig($form_state);
    $ps_service = \Drupal::service('photoshelter.photoshelter_service');
    $ps_service->authenticate();
  }

  /**
   * Synchronize all the selected collections.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state object.
   */
  public function syncFullSubmit(array &$form, FormStateInterface $form_state) {
    $config = $this->saveConfig($form_state);

    $time = new DateTime(19700101);

    $ps_service = \Drupal::service('photoshelter.photoshelter_service');

    // Get the data.
    $ps_service->getData($time);

    // Update time saved in config.
    $ps_service->updateConfigPostSync($config, TRUE);
  }

  /**
   * Synchronize newly added galleries and images in the selected collections.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state object.
   */
  public function syncNewSubmit(array &$form, FormStateInterface $form_state) {
    $config = $this->saveConfig($form_state);
    $time   = $config->get('last_sync');

    // Get the date.
    if ($time === 'Never') {
      $time = new DateTime(NULL, new DateTimeZone('GMT'));
    }
    else {
      $time = DateTime::createFromFormat(DateTime::RFC850, $time,
        new DateTimeZone('GMT'));
    }

    $ps_service = \Drupal::service('photoshelter.photoshelter_service');

    // Get the data.
    $ps_service->getData($time, TRUE);

    // Update time saved in config.
    $ps_service->updateConfigPostSync($config);
  }

  /**
   * Retrieve the Photoshelter collections and save them to the configuration.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state object.
   */
  public function getCollectionsNames(array &$form, FormStateInterface $form_state) {
    $config = $this->saveConfig($form_state);
    $ps_service = \Drupal::service('photoshelter.photoshelter_service');
    $collections_names = $ps_service->getCollectionsNames();
    $config->set('collections_names', $collections_names);
    $config->save();
  }

  /**
   * Save the configuration.
   *
   * @param FormStateInterface $form_state
   *   The form state object.
   *
   * @return \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  private function saveConfig(FormStateInterface $form_state) {
    $config = $this->config('photoshelter.settings');
    $config->set('email', $form_state->getValue('email'));
    if (!empty($form_state->getValue('password'))) {
      $config->set('password', $form_state->getValue('password'));
    }
    $config->set('api_key', $form_state->getValue('api_key'));
    $config->set('allow_private', $form_state->getValue('allow_private'));
    $config->set('cron_sync', $form_state->getValue('cron_sync'));
    $config->set('max_width', $form_state->getValue('max_width'));
    $config->set('max_height', $form_state->getValue('max_height'));
    $config->set('collections', $form_state->getValue('collections'));
    $config->save();

    return $config;
  }

}
