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

  /**
   * PhotoShelterConfigForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Database\Connection $connection
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection) {
    parent::__construct($config_factory);
    $this->connection = $connection;
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
      '#default_value' => $config->get('email')
    ];
    $form['password']  = [
      '#type'          => 'password',
      '#title'         => $this->t('Your PhotoShelter account password.'),
      '#default_value' => $config->get('password')
    ];
    $form['api_key']   = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Your PhotoShelter API key'),
      '#default_value' => $config->get('api_key')
    ];
    $form['sync_new']  = [
      '#type'   => 'submit',
      '#value'  => t('Sync New Additions'),
      '#submit' => array('syncNewSubmit')
    ];
    $form['sync_full'] = [
      '#type'   => 'submit',
      '#value'  => 'Sync All Data',
      '#submit' => array('syncAllSubmit')
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->authenticate($form_state);
  }

  /**
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  private function authenticate(FormStateInterface &$form_state) {
    $email    = $form_state->getValue('email');
    $password = $form_state->getValue('password');
    $api_key  = $form_state->getValue('api_key');
    $endpoint = '/psapi/v3/mem/authenticate';
    $base_url = 'https://www.photoshelter.com';
    $fullUrl  = $base_url . $endpoint .
                "?api_key=" . $api_key .
                "&email=" . $email .
                "&password=" . $password;

    // cURL to /psapi/v3/mem/authenticate to see if credentials are valid.
    $ch      = curl_init($fullUrl);
    $options = [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER         => FALSE,
      CURLOPT_CONNECTTIMEOUT => 60,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_COOKIEJAR      => realpath('../../files/cookie.txt'),
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);

    if ($response === FALSE) {
      curl_close($ch);
      $form_state->setError($form,
        'There was an error processing your login. Please try again.
        cURL Error: ' . curl_error($ch));
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
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function syncNewSubmit(array &$form, FormStateInterface $form_state) {
    $config  = $this->config('photoshelter.settings');
    $api_key = $config->get('api_key');
    $time    = $config->get('last_sync');

    // Get the date
    if ($time == 'Never') {
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
    if(!$this->getData($form_state, $time, $api_key)) {
      $form_state->setError($form,
        'There was a problem fetching your PhotoShelter data.');
    }

    // Update time saved in config
    $this->updateConfigPostSync($config);
    parent::submitForm($form, $form_state);
  }

  /**
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \DateTime $time
   * @param string $api_key
   *
   * @return bool
   */
  private function getData(FormStateInterface &$form_state, DateTime $time, string $api_key) {
    $this->authenticate($form_state);
    if (!$this->getCollections($api_key, $time)) {
      return FALSE;
    }
    if (!$this->getGalleries($api_key, $time)) {
      return FALSE;
    }
    if (!$this->getPhotos($api_key, $time)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param string $api_key
   * @param \DateTime $time
   *
   * @return bool
   */
  private function getCollections(string $api_key, DateTime $time) {
    $url     = 'https://www.photoshelter.com/psapi/v3/mem/collection?api_key=' . $api_key;
    $user    = $this->currentUser();
    $options = array(
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER         => FALSE,
      CURLOPT_CONNECTTIMEOUT => 60,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_COOKIEFILE     => realpath('../../files/cookie.txt'),
    );

    // Get list of collections
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if ($response === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);

    // Cycle through all collections
    $response    = json_decode($response, TRUE);
    $collections = $response['data']['Collection'];
    foreach ($collections as $collection) {
      $collectionId = $collection['collection_id'];
      $url          = 'https://www.photoshelter.com/psapi/v3/mem/collection/'
                      . $collectionId . '?api_key=' . $api_key;

      // Get information for each collection
      $ch = curl_init($url);
      curl_setopt_array($ch, $options);
      $collection = curl_exec($ch);
      if ($collection === FALSE) {
        curl_close($ch);
        return FALSE;
      }
      curl_close($ch);
      $collection = json_decode($collection, TRUE);
      $collection = $collection['data']['Collection'];

      // Check if it is meant to be public
      $cas_required = $this->getPermission('gallery', $collection['gallery_id'], $api_key, $options);
      if ($collection['f_list'] == 'f' || $cas_required == 'skip') {
        continue;
      }

      // Check if modified time is after time
      $collectionTime = DateTime::createFromFormat('YY"-"MM"-"DD" "HH":"II":"SS" "tz',
        $collection['modified_at'], new DateTimeZone('GMT'));
      if ($collectionTime < $time) {
        continue;
      }

      $this->checkForDuplicates('collection', $collection['collection_id']);
      $keyImageId = $this->getKeyImageId('collection', $collection['collection_id'], $api_key, $options);
      $link       = $this->getMediaLink('collection', $collection['collection_id'], $api_key, $options);

      // Create node from $collection and $keyImageId
      $node = Node::create([
        'nid'                 => NULL,
        'langcode'            => 'en',
        'uid'                 => $user->id(),
        'type'                => 'ps_collection',
        'title'               => $collection['name'],
        'status'              => 1,
        'promote'             => 0,
        'comment'             => 0,
        'created'             => \Drupal::time()->getRequestTime(),
        'field_cas_required'  => $cas_required,
        'field_collection_id' => $collection['collection_id'],
        'field_description'   => $collection['description'],
        'field_key_image_id'  => $keyImageId,
        'field_name'          => $collection['name'],
        'field_link'          => $link,
      ]);
      try {
        $node->save();
      } catch (Exception $e) {
        $e->getMessage();
        exit(1);
      }
    }

    return TRUE;
  }

  /**
   * @param string $media
   * @param string $id
   * @param string $api_key
   * @param array $options
   *
   * @return bool
   */
  private function getPermission(string $media, string $id, string $api_key, array &$options) {
    $url = 'https://www.photoshelter.com/psapi/v3/mem/' . $media . '/'
           . $id . '/permission?api_key=' . $api_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, $options);
    $permission = curl_exec($ch);
    if ($permission === FALSE) {
      curl_close($ch);
      echo "Error getting $media permission: " . curl_error($ch);
      exit(1);
    }
    curl_close($ch);
    $permission       = json_decode($permission, TRUE);
    $permissionStatus = $permission['data']['Permission']['mode'];
    switch ($permissionStatus) {
      case 'private':
        return 'skip';
      case 'permission':
        return TRUE;
      case 'public':
        return FALSE;
      default:
        return TRUE;
    }
  }

  /**
   * @param string $media
   * @param string $id
   */
  private function checkForDuplicates(string $media, string $id) {
    // Check for duplicate nodes
    $query = $this->connection->prepareQuery(
      'SELECT n.field_' . $media . '_id_value FROM {node__field_image_id} n WHERE n.field_image_id_value = ' . $id
    );
    try {
      $result = $this->connection->query($query);
    } catch (Exception $e) {
      $e->getMessage();
      exit(1);
    }
    // If a match is found, delete the old node
    if ($result->rowCount() > 0) {
      $row       = $result->fetchAssoc();
      $entity_id = $row['entity_id'];
      $node      = Node::load($entity_id);
      try {
        $node->delete();
      } catch (Exception $e) {
        $e->getMessage();
        exit(1);
      }
    }
  }

  /**
   * @param string $media
   * @param string $id
   * @param string $api_key
   * @param array $options
   *
   * @return bool
   */
  private function getKeyImageId(string $media, string $id, string $api_key, array &$options) {
    // Get the image key image
    $url = 'https://www.photoshelter.com/psapi/v3/mem/' . $media . '/'
           . $id . '/key_image?api_key=' . $api_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, $options);
    $keyImage = curl_exec($ch);
    if ($keyImage === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);
    $keyImage   = json_decode($keyImage, TRUE);
    $keyImageId = $keyImage['data']['KeyImage']['image_id'];
    return $keyImageId;
  }

  /**
   * @param string $media
   * @param string $id
   * @param string $api_key
   * @param array $options
   *
   * @return bool
   */
  private function getMediaLink(string $media, string $id, string $api_key, array &$options) {
    // Get the link
    $url = 'https://www.photoshelter.com/psapi/v3/mem/' . $media . '/'
           . $id . '/link?api_key=' . $api_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, $options);
    $link = curl_exec($ch);
    if ($link === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);
    $link    = json_decode($link, TRUE);
    $linkUrl = $link['data']['CollectionLink']['url'];
    return $linkUrl;
  }

  /**
   * @param string $api_key
   * @param \DateTime $time
   *
   * @return bool
   */
  private function getGalleries(string $api_key, DateTime $time) {
    $url     = 'https://www.photoshelter.com/psapi/v3/mem/gallery?api_key=' . $api_key;
    $user    = $this->currentUser();
    $options = array(
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER         => FALSE,
      CURLOPT_CONNECTTIMEOUT => 60,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_COOKIEFILE     => realpath('../../files/cookie.txt'),
    );

    // Get list of galleries
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if ($response === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);

    // Cycle through all galleries
    $response  = json_decode($response, TRUE);
    $galleries = $response['data']['Gallery'];
    foreach ($galleries as $gallery) {
      // Check if it is meant to be public
      $cas_required = $this->getPermission('gallery', $gallery['gallery_id'], $api_key, $options);
      if ($gallery['f_list'] == 'f' || $cas_required == 'skip') {
        continue;
      }

      // Check if modified time is after time
      $galleryTime = DateTime::createFromFormat('YY"-"MM"-"DD" "HH":"II":"SS" "tz',
        $gallery['modified_at'], new DateTimeZone('GMT'));
      if ($galleryTime < $time) {
        continue;
      }

      $this->checkForDuplicates('gallery', $gallery['gallery_id']);

      // Get the gallery key image and parent id
      $keyImageId = $this->getKeyImageId('gallery', $gallery['gallery_id'], $api_key, $options);
      $parentId   = $this->getParentId('gallery', $gallery['gallery_id'], $api_key, $options);
      $link       = $this->getMediaLink('gallery', $gallery['gallery_id'], $api_key, $options);

      // Create node from $gallery and $keyImageId
      $node = Node::create([
        'nid'                       => NULL,
        'langcode'                  => 'en',
        'uid'                       => $user->id(),
        'type'                      => 'ps_gallery',
        'title'                     => $gallery['name'],
        'status'                    => 1,
        'promote'                   => 0,
        'comment'                   => 0,
        'created'                   => \Drupal::time()->getRequestTime(),
        'field_cas_required'        => $cas_required,
        'field_gallery_id'          => $gallery['gallery_id'],
        'field_gallery_description' => $gallery['description'],
        'field_key_image_id'        => $keyImageId,
        'field_gallery_name'        => $gallery['name'],
        'field_parent_id'           => $parentId,
        'field_link'                => $link,
      ]);
      try {
        $node->save();
      } catch (Exception $e) {
        $e->getMessage();
        exit(1);
      }
    }

    return TRUE;
  }

  /**
   * @param string $media
   * @param string $id
   * @param string $api_key
   * @param array $options
   *
   * @return bool
   */
  private function getParentId(string $media, string $id, string $api_key, array &$options) {
    // Get the parent
    $url = 'https://www.photoshelter.com/psapi/v3/mem/' . $media . '/'
           . $id . '/parents?api_key=' . $api_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, $options);
    $keyImage = curl_exec($ch);
    if ($keyImage === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);
    $parent   = json_decode($keyImage, TRUE);
    $parentId = $parent['data']['Parents']['collection_id'];
    return $parentId;
  }

  /**
   * @param string $api_key
   * @param \DateTime $time
   *
   * @return bool
   */
  private function getPhotos(string $api_key, DateTime $time) {
    $url     = 'https://www.photoshelter.com/psapi/v3/mem/collection?api_key=' . $api_key;
    $user    = $this->currentUser();
    $options = array(
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER         => FALSE,
      CURLOPT_CONNECTTIMEOUT => 60,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_COOKIEFILE     => realpath('../../files/cookie.txt'),
    );

    // Get list of images
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if ($response === FALSE) {
      curl_close($ch);
      return FALSE;
    }
    curl_close($ch);

    // Cycle through all images
    $response = json_decode($response, TRUE);
    $images   = $response['data']['Collection'];
    foreach ($images as $image) {
      $imageId = $image['image_id'];
      $url     = 'https://www.photoshelter.com/psapi/v3/mem/image/'
                 . $imageId . '?api_key=' . $api_key;

      // Get information for each image
      $ch = curl_init($url);
      curl_setopt_array($ch, $options);
      $image = curl_exec($ch);
      if ($image === FALSE) {
        curl_close($ch);
        return FALSE;
      }
      curl_close($ch);
      $image = json_decode($image, TRUE);
      $image = $image['data']['Image'];

      // Check if modified time is after time
      $imageTime = DateTime::createFromFormat('YY"-"MM"-"DD" "HH":"II":"SS" "tz',
        $image['modified_at'], new DateTimeZone('GMT'));
      if ($imageTime < $time) {
        continue;
      }

      $this->checkForDuplicates('image', $image['image_id']);

      // Get image parent id
      $parentId = $this->getParentId('image', $image['image_id'], $api_key, $options);

      // Get image permission
      $cas_required = $this->getImagePermission($image['image_id'], $parentId, $api_key, $options);

      // Get auth_link
      $url = 'https://www.photoshelter.com/psapi/v3/mem/$mage/'
             . $image['image_id'] . '/link?api_key=' . $api_key;
      $ch  = curl_init($url);
      curl_setopt_array($ch, $options);
      $link_response = curl_exec($ch);
      if ($link_response === FALSE) {
        curl_close($ch);
        return FALSE;
      }
      curl_close($ch);
      $link_response = json_decode($link_response, TRUE);
      $auth_link     = $link_response['data']['ImageLink']['auth_link'];
      $link          = $link_response['data']['ImageLink']['link'];

      // Create node from $image and $keyImageId
      $node = Node::create([
        'nid'                => NULL,
        'langcode'           => 'en',
        'uid'                => $user->id(),
        'type'               => 'ps_photo',
        'title'              => $image['file_name'],
        'status'             => 1,
        'promote'            => 0,
        'comment'            => 0,
        'created'            => \Drupal::time()->getRequestTime(),
        'field_cas_required' => $cas_required,
        'field_image_id'     => $image['image_id'],
        'field_file_name'    => $image['file_name'],
        'field_parent_id'    => $parentId,
        'field_auth_link'    => $auth_link,
        'field_link'         => $link,
      ]);
      try {
        $node->save();
      } catch (Exception $e) {
        $e->getMessage();
        exit(1);
      }
    }

    return TRUE;
  }

  /**
   * @param string $imageId
   * @param string $galleryId
   * @param string $api_key
   * @param array $options
   *
   * @return bool
   */
  private function getImagePermission(string $imageId, string $galleryId, string $api_key, array &$options) {
    $galleryPermission = $this->getPermission('gallery', $galleryId, $api_key, $options);

    $url = 'https://www.photoshelter.com/psapi/v3/mem/image/'
           . $imageId . '/public?api_key=' . $api_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, $options);
    $permission = curl_exec($ch);
    if ($permission === FALSE) {
      curl_close($ch);
      echo "Error getting image permission: " . curl_error($ch);
      exit(1);
    }
    curl_close($ch);
    $permission       = json_decode($permission, TRUE);
    $permissionStatus = $permission['data']['Image']['is_public'];
    if ($galleryPermission && $permissionStatus == 't') {
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * @param bool $isFullSync
   * @param \Drupal\Core\Config\Config $config
   */
  private function updateConfigPostSync(bool $isFullSync = FALSE, Config &$config) {
    try {
      $currentTime = new DateTime(NULL, new DateTimeZone('GMT'));
    } catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }
    if ($isFullSync) {
      $config->set('last_full_sync', $currentTime->format(DateTime::RFC850));
    }
    $config->set('last_sync', $currentTime->format(DateTime::RFC850));
    $config->save();
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function syncAllSubmit(array &$form, FormStateInterface $form_state) {
    $time    = new DateTime('19700101');
    $config  = $this->config('photoshelter.settings');
    $api_key = $config->get('api_key');

    //Get the data
    if (!$this->getData($form_state, $time, $api_key)) {
      $form_state->setError($form,
        'There was a problem fetching your PhotoShelter data.');
    }

    // Update time saved in config
    $this->updateConfigPostSync(TRUE, $config);
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}.
   */
  protected function getEditableConfigNames() {
    return ['photoshelter.settings'];
  }
}