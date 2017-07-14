<?php

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
  private $flistCollections;
  private $api_key;
  private $options;
  // private $memoryUsage;
  private $uid;

  /**
   * PhotoShelterConfigForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Database\Connection $connection
   */
  public function __construct(ConfigFactoryInterface $config_factory,
    Connection $connection) {
    parent::__construct($config_factory);
    $this->options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_COOKIEFILE     => $this->cookie,
      CURLOPT_COOKIEJAR      => $this->cookie,
    ];
    $config = $this->config('photoshelter.settings');
    $this->connection = $connection;
    $this->cookie = dirname(__FILE__) . '/cookie.txt';
    $this->api_key = urlencode($config->get('api_key'));
    $this->uid = $this->currentUser()->id();
    $this->flistCollections = $this->getFlistCollections();
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
      '#title'         => $this->t('The email associated with your PhotoShelter account.'),
      '#default_value' => $config->get('email'),
    ];
    $form['password']  = [
      '#type'          => 'password',
      '#title'         => $this->t('Your PhotoShelter account password.'),
      '#default_value' => $config->get('password'),
    ];
    $form['api_key']   = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Your PhotoShelter API key'),
      '#default_value' => $config->get('api_key'),
    ];
    $form['sync_new']  = [
      '#type'   => 'submit',
      '#value'  => t('Sync New Additions'),
    ];
    $form['sync_full'] = [
      '#type'   => 'submit',
      '#value'  => 'Sync All Data',
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
        $this->authenticate($form, $form_state);
        break;
      case 'Sync All Data':
        $this->authenticate($form, $form_state);
        $this->sync_full_submit($form, $form_state);
        break;
      case 'Sync New Additions':
        $this->authenticate($form, $form_state);
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
  private function sync_full_submit(array &$form,
    FormStateInterface $form_state) {

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
  private function sync_new_submit(array &$form,
    FormStateInterface $form_state, bool $update = FALSE) {
    $time = $this->config('photoshelter.settings')->get('last_sync');

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
  private function authenticate(array &$form,
    FormStateInterface &$form_state) {
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
    $ch      = curl_init($fullUrl);
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
        $form_state->setError($form, 'Invalid login credentials or API key.');
      }
    }
  }

  /**
   * @param bool $update
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \DateTime $time
   */
  private function getData(array &$form, FormStateInterface &$form_state,
    DateTime $time, bool $update = FALSE) {
    $this->authenticate($form, $form_state);
    $this->getCollections($time, $update);
    $this->getGalleries($time, $update);
  }

  /**
   * @param bool $update
   * @param \DateTime $time
   */
  private function getCollections(DateTime &$time, bool $update = FALSE) {
    // Get collection and gallery data
    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection?fields=collection_id,name,description,f_list,modified_at&api_key=$this->api_key&extend={%22KeyImage%22:%20{%22fields%22:%20%22image_id,gallery_id%22,%22params%22:%20{}},%22Visibility%22:%20{%22fields%22:%20%22mode%22,%22params%22:%20{}},%22Children%22:%20{%22Gallery%22:%20{%22fields%22:%20%22gallery_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:%20{}},%22Collection%22:%20{%22fields%22:%20%22collection_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:%20{}}}}");
    curl_setopt_array($curl, $this->options);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response = json_decode($response, TRUE);
    $collections = $response['data']['Collection'];
    unset($response);

    // Cycle through all collections
    foreach ($collections as $collection) {
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
  private function getOneCollection(array &$collection, DateTime &$time,
    bool $update = FALSE, string $parentId = NULL) {

    // Check if it is meant to be public and set permissions
    $cas_required = TRUE;
    $cPermission = $collection['Permission']['mode'];
    $collectionId = $collection['collection_id'];
    $collectionName = $collection['name'];
    $collectionFlist = $collection['f_list'];
    $cModified = $collection['modified_at'];
    $cDescription = $collection['description'];
    $cKeyImage = $collection['KeyImage']['image_id'];
    $cChildren = $collection['Children'];
    unset($collection);

    //$this->memoryUsage[] = memory_get_peak_usage(true);
    //var_dump($this->memoryUsage);
    if ($cPermission === NULL) {
      $fCollections = $this->flistCollections;
      foreach ($fCollections as $fcollection) {
        //$this->memoryUsage[] = memory_get_peak_usage(true);
        if ($fcollection['collection_id'] === $collectionId) {
          if ($fcollection['Permission']['mode'] === 'private') {
            return;
          }
        }
        unset($fcollection);
        // unset($fCollections);
      }
    }
    else if ($cPermission === 'private') {
      return;
    }
    else {
      $cas_required = $this->getPermission($cPermission, $collectionFlist);
    }
    unset($this->flistCollections);
    //$this->memoryUsage[] = memory_get_peak_usage(true);
    //var_dump($this->memoryUsage);

    // Check if modified time is after time
    $collectionTime = DateTime::createFromFormat(
      'YY"-"MM"-"DD" "HH":"II":"SS" "tz', $cModified,
      new DateTimeZone('GMT'));
    if ($update) {
      if ($collectionTime < $time) {
        return;
      }
    }

    //$this->checkForDuplicates('collection', $collectionId);

    // Create node from $collection and $keyImageId
    $node = Node::create([
      'nid'                 => NULL,
      'langcode'            => 'en',
      'uid'                 => $this->uid,
      'type'                => 'ps_collection',
      'title'               => $collectionName,
      'status'              => 1,
      'promote'             => 0,
      'comment'             => 0,
      'created'             => \Drupal::time()->getRequestTime(),
      'field_cas_required'  => $cas_required,
      'field_collection_id' => $collectionId,
      'field_description'   => $cDescription,
      'field_key_image_id'  => $cKeyImage,
      'field_name'          => $collectionName,
      'field_parent_id'     => $parentId
    ]);
    try {
      $node->save();
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }
    unset($node);

    // Create nodes for children
    if (!isset($parentId) && isset($cChildren)) {
      foreach ($cChildren as $child) {
        switch(key($cChildren)) {
          case 'Gallery':
            foreach ($child as $gallery) {
              $this->getGallery($gallery, $time, $update, $collectionId);
              unset($gallery);
            }
            break;
          case 'Collection':
            foreach ($child as $childCollection) {
              $this->getOneCollection($childCollection, $time, $update,
                $collectionId);
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

  private function getGalleries(DateTime &$time, bool $update = FALSE) {
    // Get list of galleries
    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/gallery?fields=gallery_id,name,description,f_list,modified_at&extend={%22Parents%22:{%22fields%22:%22collection_id%22,%22params%22:{}}}&api_key=$this->api_key");
    curl_setopt_array($curl, $this->options);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response = json_decode($response, TRUE);
    $galleries = $response['data']['Gallery'];
    unset($response);

    $galleryParent = $galleries['Parents']['collection_id'];

    foreach ($galleries as $gallery) {
      if (isset($galleryParent)) {
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
  private function getGallery(array &$gallery, DateTime &$time,
    bool $update = FALSE, string &$parentId = '') {
    $galleryPermission = $gallery['Visibility']['mode'];
    $galleryFlist = $gallery['f_list'];
    $galleryId = $gallery['gallery_id'];
    $galleryModified = $gallery['modified_at'];
    $galleryName = $gallery['name'];
    $galleryDescription = $gallery['description'];
    $galleryImage = $gallery['KeyImage']['image_id'];
    unset($gallery);

    // Check if it should be private
    if ($galleryPermission === 'private') {
      return;
    }
    $cas_required = $this->getPermission($galleryPermission, $galleryFlist);

    // Check if modified time is after time
    $galleryTime = DateTime::createFromFormat(
      'YY"-"MM"-"DD" "HH":"II":"SS" "tz', $galleryModified,
      new DateTimeZone('GMT'));
    if ($update) {
      if ($galleryTime < $time) {
        return;
      }
    }

    //$this->checkForDuplicates('gallery', $galleryId);

    // Create node
    $node = Node::create([
      'nid'                       => NULL,
      'langcode'                  => 'en',
      'uid'                       => $this->uid,
      'type'                      => 'ps_gallery',
      'title'                     => $galleryName,
      'status'                    => 1,
      'promote'                   => 0,
      'comment'                   => 0,
      'created'                   => \Drupal::time()->getRequestTime(),
      'field_cas_required'        => $cas_required,
      'field_gallery_id'          => $galleryId,
      'field_gallery_description' => $galleryDescription,
      'field_key_image_id'        => $galleryImage,
      'field_gallery_name'        => $galleryName,
      'field_parent_id'           => $parentId,
    ]);
    try {
      $node->save();
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }
    unset($node);

    $this->getPhotos($galleryId, $cas_required, $time, $update);
  }

  /**
   * @param string $parentId
   * @param bool $parentCas
   * @param bool $update
   * @param \DateTime $time
   */
  private function getPhotos(string &$parentId, bool $parentCas,
    DateTime &$time, bool $update = FALSE) {
    // Get list of images in gallery
    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/gallery/G0000XVCxHx2xvII/images?fields=image_id,f_visible&api_key=$this->api_key&extend={%22Image%22:{%22fields%22:%22image_id,file_name,updated_at%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link,auth_link%22,%22params%22:{}}}");
    curl_setopt_array($curl, $this->options);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response = json_decode($response, TRUE);
    $images   = $response['data']['GalleryImage'];
    unset($response);

    // Cycle through all images
    foreach ($images as $image) {
      // Skip if image isn't public
      if ($image['f_visible'] === 'f') {
        unset($image);
        continue;
      }

      $imageUpdate = $image['updated_at'];
      $imageId = $image['image_id'];
      $imageName = $image['Image']['file_name'];
      $imageAuthLink = $image['ImageLink']['auth_link'];
      $imageLink = $image['ImageLink']['link'];
      unset($image);

      // Check if modified time is after time
      $imageTime = DateTime::createFromFormat(
        'YY"-"MM"-"DD" "HH":"II":"SS" "tz', $imageUpdate,
        new DateTimeZone('GMT'));
      if ($update) {
        if ($imageTime < $time) {
          continue;
        }
      }

      //$this->checkForDuplicates('image', $imageId);

      // Create node from $image and $keyImageId
      $node = Node::create([
        'nid'                => NULL,
        'langcode'           => 'en',
        'uid'                => $this->uid,
        'type'               => 'ps_photo',
        'title'              => $imageName,
        'status'             => 1,
        'promote'            => 0,
        'comment'            => 0,
        'created'            => \Drupal::time()->getRequestTime(),
        'field_cas_required' => $parentCas,
        'field_image_id'     => $imageId,
        'field_file_name'    => $imageName,
        'field_parent_id'    => $parentId,
        'field_auth_link'    => $imageAuthLink,
        'field_link'         => $imageLink,
      ]);
      try {
        $node->save();
      } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
      }
      unset($node);
    }
  }

  /**
   * @return array
   */
  private function getFlistCollections() {
    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection?api_key=$this->api_key&fields=collection_id,f_list&extend={%22Permission%22:{%22fields%22:%22mode%22,%22params%22:{}}}");
    curl_setopt_array($curl, $this->options);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response = json_decode($response, TRUE);
    $collections = $response['data']['Collection'];
    unset($response);
    $flistCollections = array();
    foreach ($collections as $collection) {
      if ($collection['f_list'] === 'f') {
        $flistCollections[]['f_list'] = $collection['f_list'];
      }
      unset($collection);
    }
    return $flistCollections;
  }

  /**
   * @param string $permission
   * @param string $f_list
   *
   * @return bool
   */
  private function getPermission(string &$permission, string &$f_list) {
    if ($f_list === 'f') {
      return TRUE;
    }
    switch ($permission) {
      case 'private':
        return TRUE;
        break;
      case 'everyone':
        return FALSE;
        break;
      case 'permission':
        return TRUE;
        break;
      case 'public':
        return FALSE;
        break;
    }
    return TRUE;
  }

  /**
   * @param string $media
   * @param string $id
   */
  /*private function checkForDuplicates(string $media, string &$id) {
    // Check for duplicate nodes
    try {
      $results = $this->connection
        ->select('node__field_' . $media . '_id', 'f')
        ->fields('f', ['field_' . $media . '_id_value'])
        ->condition('f.field_' . $media . '_id_value', $id, '=');
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }

    // If a match is found, delete the old node
    foreach ($results as $row) {
      $entity_id = $row['entity_id'];
      $node      = Node::load($entity_id);
      try {
        $node->delete();
      } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
      }
      unset($row);
    }
  }*/

  /**
   * @param bool $isFullSync
   * @param \Drupal\Core\Config\Config $config
   */
  private function updateConfigPostSync(Config &$config,
    bool $isFullSync = FALSE) {
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
}