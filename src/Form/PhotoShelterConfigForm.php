<?php

/**
 * Copyright 2018 Inovae Sarl
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @file
 * Contains Drupal\photoshelter\Form\PhotoShelterConfigForm
 */

namespace Drupal\photoshelter\Form;

use DateTime;
use DateTimeZone;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class PhotoShelterConfigForm
 *
 * @package Drupal\photoshelter\Form
 */
class PhotoShelterConfigForm extends ConfigFormBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  protected $user;

  protected $PS_service;

  /**
   * PhotoShelterConfigForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Database\Connection $connection
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection) {
    parent::__construct($config_factory);
    $this->connection = $connection;
    $this->PS_service = \Drupal::service('photoshelter.photoshelter_service');
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    /** @noinspection PhpParamsInspection */
    return new static(
      $container->get('config.factory'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photoshelter_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form              = parent::buildForm($form, $form_state);
    $config            = $this->config('photoshelter.settings');
    $form['email']     = [
      '#type'          => 'email',
      '#title'         =>
        $this->t('The email associated with your PhotoShelter account.'),
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
    $form['sync_new'] = [
      '#type'  => 'submit',
      '#value' => t('Sync New Additions'),
    ];
    $form['sync_full'] = [
      '#type'  => 'submit',
      '#value' => 'Sync All Data',
    ];
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

    $config = $this->config('photoshelter.settings');
    $config->set('email', $form_state->getValue('email'));
    if(!empty($form_state->getValue('password'))) {
      $config->set('password', $form_state->getValue('password'));
    }
    $config->set('api_key', $form_state->getValue('api_key'));
    $config->set('allow_private', $form_state->getValue('allow_private'));
    $config->set('cron_sync', $form_state->getValue('cron_sync'));
    $config->set('max_width', $form_state->getValue('max_width'));
    $config->set('max_height', $form_state->getValue('max_height'));
    $config->save();

    $op = $form_state->getValue('op');
    $this->token = $this->PS_service->authenticate();

    switch ($op) {
      case 'Save configuration':
        break;
      case 'Sync All Data':
        $this->sync_full_submit();
        break;
      case 'Sync New Additions':
        $this->sync_new_submit(TRUE);
        break;
    }
  }

  /**
   * {@inheritdoc}.
   */
  protected function getEditableConfigNames() {
    return ['photoshelter.settings'];
  }

  /**
   *
   */
  private function sync_full_submit() {
    $config = $this->config('photoshelter.settings');

    $time = new DateTime(19700101);

    //Get the data
    $this->PS_service->getData($time);

    // Update time saved in config
    $this->PS_service->updateConfigPostSync($config, TRUE);
  }

  /**
   * @param bool $update
   */
  private function sync_new_submit($update = FALSE) {
    $config = $this->config('photoshelter.settings');
    $time   = $config->get('last_sync');

    // Get the date
    if ($time === 'Never') {
      try {
        $time = new DateTime(NULL, new DateTimeZone('GMT'));
      } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
      }
    }
    else {
      $time = DateTime::createFromFormat(DateTime::RFC850, $time,
        new DateTimeZone('GMT'));
    }

    //Get the data
    $this->PS_service->getData($time, $update);

    // Update time saved in config
    $this->PS_service->updateConfigPostSync($config);
  }

}
