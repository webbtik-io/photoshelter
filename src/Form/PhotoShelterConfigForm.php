<?php

/**
 * Copyright 2017 Brigham Young University
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
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
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

  private $cookie;

  private $api_key;

  private $options;

  private $uid;

  /**
   * PhotoShelterConfigForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Database\Connection $connection
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $connection
  ) {
    parent::__construct($config_factory);
    $this->cookie     = dirname(__FILE__) . '/cookie.txt';
    $this->options    = [
      CURLOPT_RETURNTRANSFER   => TRUE,
      CURLOPT_ENCODING         => "",
      CURLOPT_MAXREDIRS        => 10,
      CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST    => "GET",
      CURLOPT_COOKIEFILE       => $this->cookie,
      CURLOPT_COOKIEJAR        => $this->cookie,
      CURLOPT_SSL_VERIFYPEER   => FALSE,
      CURLOPT_FOLLOWLOCATION
    ];
    if (defined('CURLOPT_SSL_VERIFYSTATUS')) {
      $this->options[CURLOPT_SSL_VERIFYSTATUS] = FALSE;
    }
    $config           = $this->config('photoshelter.settings');
    $this->connection = $connection;
    $this->api_key    = urlencode($config->get('api_key'));
    $this->uid        = $this->currentUser()->id();
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
      '#type'          => 'textfield',
      '#title'         =>
        $this->t('Your PhotoShelter account password.'),
      '#default_value' => $config->get('password'),
    ];
    $form['api_key']   = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Your PhotoShelter API key'),
      '#default_value' => $config->get('api_key'),
    ];
    $form['sync_new']  = [
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
    $op = $form_state->getValue('op');
    switch ($op) {
      case 'Save configuration':
        $this->submitForm($form, $form_state);
        $this->authenticate($form, $form_state);
        break;
      case 'Sync All Data':
        $this->authenticate($form, $form_state);
        // $this->flistCollections = $this->getFlistCollections();
        $this->sync_full_submit($form, $form_state);
        break;
      case 'Sync New Additions':
        $this->authenticate($form, $form_state);
        // $this->flistCollections = $this->getFlistCollections();
        $this->sync_new_submit($form, $form_state, TRUE);
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('photoshelter.settings');
    $config->set('email', $form_state->getValue('email'));
    $config->set('password', $form_state->getValue('password'));
    $config->set('api_key', $form_state->getValue('api_key'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}.
   */
  protected function getEditableConfigNames() {
    return ['photoshelter.settings'];
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  private function sync_full_submit(
    array &$form,
    FormStateInterface $form_state
  ) {
    $config = $this->config('photoshelter.settings');

    $time = new DateTime(19700101);

    //Get the data
    $this->getData($form, $form_state, $time);

    // Update time saved in config
    $this->updateConfigPostSync($config, TRUE);
    parent::submitForm($form, $form_state);
  }

  /**
   * @param bool $update
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  private function sync_new_submit(
    array &$form,
    FormStateInterface $form_state, bool $update = FALSE
  ) {
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
    $this->getData($form, $form_state, $time, $update);

    // Update time saved in config
    $this->updateConfigPostSync($config);
    parent::submitForm($form, $form_state);
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  private function authenticate(
    array &$form,
    FormStateInterface &$form_state
  ) {
    $config   = $this->config('photoshelter.settings');
    $email    = $config->get('email');
    $password = $config->get('password');
    $api_key  = $config->get('api_key');
    $endpoint = '/psapi/v3/mem/authenticate';
    $base_url = 'https://www.photoshelter.com';
    $fullUrl  = $base_url . $endpoint .
                '?api_key=' . $api_key .
                '&email=' . $email .
                '&password=' . $password;

    // cURL to /psapi/v3/mem/authenticate to see if credentials are valid.
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, $this->options);
    $response = curl_exec($ch);

    if ($response === FALSE) {
      $form_state->setError($form,
        'There was an error processing your login. Please try again.
        cURL Error: ' . curl_error($ch));
      curl_close($ch);
    }
    else {
      curl_close($ch);
      $jsonResponse = json_decode($response, TRUE);
      if ($jsonResponse['status'] != 'ok') {
        $form_state->setError($form,
          'Invalid login credentials or API key.');
      }
      if (isset($jsonResponse['data']['org'][0]['id']) && !empty($jsonResponse['data']['org'][0]['id'])) {
        $org_id = $jsonResponse['data']['org'][0]['id'];
        $endpoint = '/psapi/v3/mem/organization/' . $org_id . '/authenticate';
        $fullUrl  = $base_url . $endpoint .
          '?api_key=' . $api_key;
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, $this->options);
        $response = curl_exec($ch);
        $jsonResponse = json_decode($response, TRUE);
        if ($jsonResponse['status'] != 'ok') {
          $form_state->setError($form,
            'Invalid organization id.');
        }
        curl_close($ch);
        drupal_set_message(t('The authentication as an organization is successfull'));
      }
      else {
        drupal_set_message(t('The authentication is successfull'));
      }
    }
  }

  /**
   * @param bool $update
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \DateTime $time
   */
  private function getData(
    array &$form, FormStateInterface &$form_state,
    DateTime &$time, bool $update = FALSE
  ) {
    set_time_limit(0);
    $this->authenticate($form, $form_state);
    $this->getCollections($time, $update);
    $this->getGalleries($time, $update);
  }

  /**
   * @param bool $update
   * @param \DateTime $time
   */
  private function getCollections(DateTime &$time, bool $update) {
    // Get collection and gallery data
    $api_key = $this->config('photoshelter.settings')->get('api_key');
    $curl    = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection?fields=collection_id,name,description,f_list,modified_at&api_key=$api_key&extend={%22Permission%22:{%22fields%22:%22mode%22,%22params%22:{}},%22KeyImage%22:%20{%22fields%22:%22image_id,gallery_id%22,%22params%22:{}},%22Visibility%22:%20{%22fields%22:%22mode%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Children%22:{%22Gallery%22:{%22fields%22:%22gallery_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}},%22Collection%22:{%22fields%22:%22collection_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}}}}");
    curl_setopt_array($curl, $this->options);

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response    = json_decode($response, TRUE);
    $collections = $response['data']['Collection'];
    unset($response);

    // Cycle through all collections
    foreach ($collections as $collection) {
      if ($collection['f_list'] === 'f') {
        unset($collection);
        continue;
      }
      $this->getOneCollection($collection, $time, $update);
      unset($collection);
    }
    unset($collections);
  }

  /**
   * @param array $collection
   * @param \DateTime $time
   * @param bool $update
   * @param string $parentId
   */
  private function getOneCollection(
    array &$collection, DateTime &$time,
    bool $update, string $parentId = NULL
  ) {
    $this->saveOneCollection($collection, $time, $update,
      $collection['Permission']['mode'], $parentId);
  }

  /**
   * @param string $collectionId
   * @param \DateTime $time
   * @param bool $update
   * @param string|NULL $parentId
   */

  private function curlOneCollection(
    string $collectionId, DateTime &$time,
    bool $update, string $parentId = NULL
  ) {

    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection/$collectionId?api_key=6CmmdvcipQw&fields=collection_id,name,description,f_list,mode,modified_at&extend={%22Permission%22:{%22fields%22:%22mode%22,%22params%22:{}},%22KeyImage%22:%20{%22fields%22:%22image_id,gallery_id%22,%22params%22:{}},%22Visibility%22:%20{%22fields%22:%22mode%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Children%22:{%22Gallery%22:{%22fields%22:%22gallery_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}},%22Collection%22:{%22fields%22:%22collection_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}}}}");
    curl_setopt_array($curl, $this->options);

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
    }

    $jsonResponse = json_decode($response, TRUE);
    $collection   = $jsonResponse['data']['Collection'];
    $this->saveOneCollection($collection, $time, $update,
      $collection['Visibility']['mode'], $parentId);
    unset($collection);
  }


  /**
   * @param array $collection
   * @param \DateTime $time
   * @param bool $update
   * @param string $cPermission
   * @param string|NULL $parentId
   */

  private function saveOneCollection(
    array &$collection, DateTime &$time,
    bool $update, string $cPermission, string $parentId = NULL
  ) {
    // Check if it is meant to be public and set permissions
    $collectionId   = $collection['collection_id'];
    $collectionName = $collection['name'];
    $cModified      = $collection['modified_at'];
    $cDescription   = $collection['description'];
    $cKeyImage      = $collection['KeyImage']['image_id'];
    $cChildren      = $collection['Children'];
    $cKeyImageFile  = $collection['KeyImage']['ImageLink']['link'];
    unset($collection);

    $cas_required = $this->getPermission($cPermission);

    // Check if modified time is after time
    $collectionTime = DateTime::createFromFormat('Y-m-d H:i:s e',
      $cModified, new DateTimeZone('GMT'));
    if ($update) {
      if ($collectionTime < $time) {
        unset($collectionTime);
        return;
      }
    }
    unset($collectionTime);

    if ($cKeyImageFile !== NULL) {
      $file = File::create(['uri' => $cKeyImageFile]);
      $file->save();
    }

    // If already exist, update instead of create.
    $collection_id = $this->collectionExists($collectionId);
    if (!empty($collection_id)) {
      $term = Term::load($collection_id);
      $term->set('name', $collectionName);
      $term->set('description', $cDescription);
      $term->set('field_ps_permission', $cas_required);
      $term->set('field_ps_parent_id', $parentId);
      $term->set('field_ps_parent_collection', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
      $term->set('field_ps_modified_at', $cModified);
      $term->set('field_ps_key_image_id', $cKeyImage);
      $term->set('field_ps_key_image', isset($file) ? ['target_id' => $file->id()] : NULL);
    }
    else {
      // Create term from $collection and $keyImageId
      $term = Term::create([
        'langcode'             => 'en',
        'vid'                 => 'ps_collection',
        'name'           => $collectionName,
        'description'    => $cDescription,
        'field_ps_permission'   => $cas_required,
        'field_ps_id'             => $collectionId,
        'field_ps_parent_id'      => $parentId,
        'field_ps_parent_collection' => isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL,
        'field_ps_modified_at' => $cModified,
        'field_ps_key_image_id'   => $cKeyImage,
        'field_ps_key_image' => isset($file) ?
          ['target_id' => $file->id()] : NULL,
      ]);
    }

    try {
      $term->save();
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }

    if (isset($file)) {
      unset($file);
    }
    unset($term);

    // Create nodes for children
    if (isset($cChildren)) {
      foreach ($cChildren as $child) {
        switch (key($cChildren)) {
          case 'Gallery':
            foreach ($child as $gallery) {
              $this->getGallery($gallery, $time, $update, $collectionId);
              unset($gallery);
            }
            break;
          case 'Collection':
            foreach ($child as $childCollection) {
              $this->curlOneCollection($childCollection['collection_id'], $time,
                $update, $collectionId);
              unset($childCollection);
            }
            unset($collection);
            break;
        }
        unset($child);
        next($cChildren);
      }
    }
  }

  private function getGalleries(DateTime &$time, bool $update) {
    // Get list of galleries
    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/gallery?fields=gallery_id,name,description,f_list,modified_at&extend={%22Parents%22:{%22fields%22:%22collection_id%22,%22params%22:{}},%22KeyImage%22:{%22fields%22:%22image_id%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link,auth_link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Visibility%22:{%22fields%22:%22mode%22,%22params%22:{}}}&api_key=$this->api_key");
    curl_setopt_array($curl, $this->options);
    $response = curl_exec($curl);
    $err      = curl_error($curl);
    curl_close($curl);
    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response  = json_decode($response, TRUE);
    $galleries = $response['data']['Gallery'];
    unset($response);

    foreach ($galleries as $gallery) {
      if ($gallery['f_list'] === 'f') {
        unset($gallery);
        continue;
      }
      else if (!isset($galleryParent)) {
        $this->getGallery($gallery, $time, $update);
      }
      unset($gallery);
    }
    unset($galleryParent);
    unset($galleries);
  }

  /**
   * @param array $gallery
   * @param bool $update
   * @param \DateTime $time
   * @param string $parentId
   */
  private function getGallery(
    array &$gallery, DateTime &$time,
    bool $update, string &$parentId = NULL
  ) {
    $galleryPermission  = $gallery['Visibility']['mode'];
    $galleryId          = $gallery['gallery_id'];
    $galleryModified    = $gallery['modified_at'];
    $galleryName        = $gallery['name'];
    $galleryDescription = $gallery['description'];
    $galleryImage       = $gallery['KeyImage']['image_id'];
    $galleryImageFile   = $gallery['KeyImage']['ImageLink']['link'];
    unset($gallery);

    $cas_required = $this->getPermission($galleryPermission);

    // Check if modified time is after time
    $galleryTime = DateTime::createFromFormat(
      'Y-m-d H:i:s e', $galleryModified,
      new DateTimeZone('GMT'));
    if ($update) {
      if ($galleryTime < $time) {
        return;
      }
    }

    if (isset($galleryImageFile)) {
      $file = File::create(['uri' => $galleryImageFile]);
      $file->save();
    }

    // If already exists, update instead of create
    $gallery_id = $this->galleryExists($galleryId);
    if (!empty($gallery_id)) {
      $term = Term::load($gallery_id);
      $term->set('name', $galleryName);
      $term->set('description', $galleryDescription);
      $term->set('field_ps_permission', $cas_required);
      $term->set('field_ps_parent_id', $parentId);
      $term->set('field_ps_parent_collection', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
      $term->set('field_ps_modified_at', $galleryModified);
      $term->set('field_ps_key_image_id', $galleryImage);
      $term->set('field_ps_key_image', isset($file) ? ['target_id' => $file->id()] : NULL);
    }
    else {
      // Create node
      $term = Term::create([
        'langcode'             => 'en',
        'vid'                 => 'ps_gallery',
        'name'           => $galleryName,
        'description'    => $galleryDescription,
        'field_ps_permission'   => $cas_required,
        'field_ps_id'             => $galleryId,
        'field_ps_parent_id'      => $parentId,
        'field_ps_parent_collection' => isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL,
        'field_ps_modified_at' => $galleryModified,
        'field_ps_key_image_id'   => $galleryImage,
        'field_ps_key_image' => isset($file) ?
          ['target_id' => $file->id()] : NULL,
      ]);
    }

    try {
      $term->save();
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }

    if (isset($file)) {
      unset($file);
    }
    unset($term);

    $this->getPhotos($galleryId, $cas_required, $time, $update);
  }

  /**
   * @param string $parentId
   * @param bool $parentCas
   * @param bool $update
   * @param \DateTime $time
   */
  private function getPhotos(
    string &$parentId, bool $parentCas,
    DateTime &$time, bool $update
  ) {
    $page = 1;
    do {
      // Get list of images in gallery
      $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/gallery/$parentId/images?fields=image_id,f_visible&api_key=$this->api_key&per_page=750&page=$page&extend={%22Image%22:{%22fields%22:%22image_id,file_name,updated_at%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link,auth_link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Iptc%22:{%22fields%22:%22keyword,credit,caption%22,%22params%22:{}}}");
      curl_setopt_array($curl, $this->options);
      $response = curl_exec($curl);
      $err      = curl_error($curl);
      curl_close($curl);
      if ($err) {
        echo "cURL Error #:" . $err;
        exit(1);
      }
      $response = json_decode($response, TRUE);
      $images   = $response['data']['GalleryImage'];
      $paging   = $response['data']['Paging'];
      if (!array_key_exists('next', $paging)) {
        $page = 0;
      }
      unset($paging);
      unset($response);

      // Cycle through all images
      foreach ($images as $image) {
        // Skip if image isn't public
        if ($image['f_visible'] === 'f') {
          unset($image);
          continue;
        }

        $imageUpdate   = $image['Image']['updated_at'];
        $imageId       = $image['image_id'];
        $imageName     = $image['Image']['file_name'];
        $imageKeywords = $image['Image']['Iptc']['keyword'];
        $imageLink     = $image['ImageLink']['link'];
        $imageCaption  = nl2br($image['Image']['Iptc']['caption']);
        $imageCredit   = $image['Image']['Iptc']['credit'];
        unset($image);

        // Check if modified time is after time
        $imageTime = DateTime::createFromFormat('Y-m-d H:i:s e',
          $imageUpdate, new DateTimeZone('GMT'));
        if ($update) {
          if ($imageTime < $time) {
            continue;
          }
        }

        if (isset($imageLink)) {
          $file = File::create([
            'uri' => $imageLink,
            'alt' => $imageName,
          ]);
          $file->save();
        }

        // If already exists, update instead of create
        $media_id = $this->imageExists($imageId);
        if (!empty($media_id)) {
          $media = Media::load($media_id);
          $media->set('name', $imageName);
          $media->set('field_ps_permission', $parentCas);
          $media->set('field_ps_parent_id', $parentId);
          $media->set('field_ps_parent_gallery', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
          $media->set('field_ps_modified_at', $imageUpdate);
          $media->set('field_ps_caption', $imageCaption);
          $media->set('field_ps_credit', $imageCredit);
          $media->set('field_media_image', isset($file) ? ['target_id' => $file->id(), 'alt' => $imageName,] : NULL);

        }
        else {
          // Create node from $image and $keyImageId
          $media = Media::create([
            'langcode'             => 'en',
            'uid'                  => $this->uid,
            'bundle'                 => 'ps_image',
            'name'                => $imageName,
            'status'               => 1,
            'created'              => \Drupal::time()->getRequestTime(),
            'field_ps_permission'   => $parentCas,
            'field_ps_id'             => $imageId,
            'field_ps_parent_id'      => $parentId,
            'field_ps_parent_gallery' => isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL,
            'field_ps_modified_at' => $imageUpdate,
            'field_ps_caption'        => $imageCaption,
            'field_ps_credit'         => $imageCredit,
            'field_media_image' => isset($file) ?
              ['target_id' => $file->id(), 'alt' => $imageName,] : NULL,
          ]);
        }

        if (isset($imageKeywords) && !empty($imageKeywords)) {
          $taxonomy = explode(',', $imageKeywords);
          foreach ($taxonomy as $term) {
            $term = trim($term);
            $termExists = $this->termExists($term, 'ps_tags');
            if($termExists === 0) {
              $keyword = Term::create([
                'name' => $term,
                'vid'  => 'ps_tags',
              ]);
              $keyword->save();
              $media->field_ps_tags->appendItem(['target_id' => $keyword->id()]);
            }
            else {
              $media->field_ps_tags->appendItem(['target_id' => $termExists]);
            }
          }
        }

        try {
          $media->save();
        } catch (Exception $e) {
          echo $e->getMessage();
          exit(1);
        }
        if (isset($file)) {
          unset($file);
        }
        unset($media);
      }

      if ($page !== 0) {
        $page++;
      }
    } while ($page !== 0);
  }

  /**
   * @param string $permission
   *
   * @return bool
   */
  private function getPermission(string &$permission) {
    switch ($permission) {
      case 'private':
      case 'permission':
        return TRUE;
        break;
      case 'everyone':
      case 'public':
        return FALSE;
        break;
    }
    return TRUE;
  }

  /**
   * @param bool $isFullSync
   * @param \Drupal\Core\Config\Config $config
   */
  private function updateConfigPostSync(
    Config &$config,
    bool $isFullSync = FALSE
  ) {
    try {
      $currentTime = new DateTime(NULL, new DateTimeZone('GMT'));
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }
    if ($isFullSync) {
      $config->set('last_full_sync', $currentTime->format(
        DateTime::RFC850));
    }
    $config->set('last_sync', $currentTime->format(
      DateTime::RFC850));
    $config->save();
  }

  /**
   * @param null $name
   * @param null $vid
   *
   * @return bool
   */
  private function termExists($name = NULL, $vid = NULL) {
    $properties = [];
    if (!empty($name)) {
      $properties['name'] = $name;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);

    return !empty($term) ? $term->id() : 0;
  }

  /**
   * Get the parent term id.
   *
   * @param string $parent_ps_id
   *   Photoshelter id of the parent collection or gallery.
   *
   * @return string
   */
  private function getParentTerm($parent_ps_id) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('field_ps_id', $parent_ps_id);
    $tids = $query->execute();
    $tid = !empty($tids) ? reset($tids) : '';
    return $tid;
  }

  /**
   * @param string $collection_ps_id
   *   Photoshelter id of the collection.
   *
   * @return string
   *   Taxonomy term id.
   */
  private function collectionExists($collection_ps_id) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', 'ps_collection');
    $query->condition('field_ps_id', $collection_ps_id);
    $tids = $query->execute();
    $tid = !empty($tids) ? reset($tids) : '';
    return $tid;
  }

  /**
   * @param string $gallery_ps_id
   *   Photoshelter id of the gallery.
   *
   * @return string
   *   Taxonomy term id.
   */
  private function galleryExists($gallery_ps_id) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', 'ps_gallery');
    $query->condition('field_ps_id', $gallery_ps_id);
    $tids = $query->execute();
    $tid = !empty($tids) ? reset($tids) : '';
    return $tid;
  }

  /**
   * @param string $image_ps_id
   *   Photoshelter id of the image.
   *
   * @return string
   *   Media id.
   */
  private function imageExists($image_ps_id) {
    $query = \Drupal::entityQuery('media');
    $query->condition('bundle', 'ps_image');
    $query->condition('field_ps_id', $image_ps_id);
    $mids = $query->execute();
    $mid = !empty($mids) ? reset($mids) : '';
    return $mid;
  }
}
