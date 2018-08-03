<?php

namespace Drupal\photoshelter;

use DateTime;
use DateTimeZone;
use Drupal\Core\Config\Config;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
use Exception;

/**
 * Class PhotoshelterService.
 *
 * @package Drupal\photoshelter
 */
class PhotoshelterService {

  /**
   * Photoshelter API key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * Photoshelter authenticate token.
   *
   * @var string
   */
  protected $token;

  /**
   * Photoshelter base url.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * Photoshelter collections array.
   *
   * @var array
   */
  protected $collections;

  /**
   * Photoshelter credentials array.
   *
   * @var array
   */
  protected $credentials;

  /**
   * Owner id for images.
   *
   * @var int
   */
  private $uid;

  /**
   * Allow private files status.
   *
   * @var bool
   */
  private $allowPrivate;

  /**
   * Maximum dimensions for images.
   *
   * @var int
   */
  private $maxDim;

  /**
   * Options array for curl request.
   *
   * @var array
   */
  private $curlOptions;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * PhotoshelterService constructor.
   *
   * @param MessengerInterface $messenger
   *   The Messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
    $this->curlOptions    = [
      CURLOPT_RETURNTRANSFER   => TRUE,
      CURLOPT_ENCODING         => "",
      CURLOPT_MAXREDIRS        => 10,
      CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST    => "GET",
      CURLOPT_SSL_VERIFYPEER   => FALSE,
      CURLOPT_FOLLOWLOCATION
    ];
    if (defined('CURLOPT_SSL_VERIFYSTATUS')) {
      $this->curlOptions[CURLOPT_SSL_VERIFYSTATUS] = FALSE;
    }
    $this->uid = 1;
    $this->baseUrl = 'https://www.photoshelter.com/psapi/v3/mem/';
    $config = \Drupal::config('photoshelter.settings');
    $this->credentials = [
      'email' => $config->get('email'),
      'password' => $config->get('password'),
    ];
    $this->apiKey = $config->get('api_key');
    $this->allowPrivate = $config->get('allow_private');
    $this->maxDim = $config->get('max_width') . 'x' . $config->get('max_height');
    $this->collections = $config->get('collections');
    $this->authenticate();
  }

  /**
   * Send request to authenticate to the service and retrieve token.
   *
   * @return string
   *   Authentication token string.
   */
  public function authenticate() {
    $endpoint = 'authenticate';
    $fullUrl  = $this->baseUrl . $endpoint .
      '?api_key=' . $this->apiKey .
      '&email=' . $this->credentials['email'] .
      '&password=' . $this->credentials['password'] .
      '&mode=token';

    // cURL to /psapi/v3/mem/authenticate to see if credentials are valid.
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, $this->curlOptions);
    $response = curl_exec($ch);
    if ($response === FALSE) {
      $this->messenger->addError(t('request error'));
      curl_close($ch);
    }
    else {
      curl_close($ch);
      $jsonResponse = json_decode($response, TRUE);
      if ($jsonResponse['status'] != 'ok') {
        $this->messenger->addError(t('Invalid credentials'));
      }
      else {
        $this->token = $jsonResponse['data']['token'];
        // Authenticate as an organization if needed.
        if (isset($jsonResponse['data']['org'][0]['id']) && !empty($jsonResponse['data']['org'][0]['id'])) {
          $org_id = $jsonResponse['data']['org'][0]['id'];
          $endpoint = 'organization/' . $org_id . '/authenticate';
          $fullUrl  = $this->baseUrl . $endpoint .
            '?api_key=' . $this->apiKey .
            '&auth_token=' . $this->token;
          $ch = curl_init($fullUrl);
          curl_setopt_array($ch, $this->curlOptions);
          $response = curl_exec($ch);
          $jsonResponse = json_decode($response, TRUE);
          if ($jsonResponse['status'] != 'ok') {
            $this->messenger->addError(t('error when authenticate as an organization'));
          }
          curl_close($ch);
          $this->messenger->addMessage(t('The authentication as an organization is successfull'));
        }
        else {
          $this->messenger->addMessage(t('The authentication is successfull'));
        }
      }
    }
    return $this->token;
  }

  /**
   * Retrieve Photoshelter collections names and id.
   *
   * @return array
   *   Collection array with collection ids and names.
   */
  public function getCollectionsNames() {
    // Get collection and gallery data.
    $endpoint = 'collection';
    $fullUrl  = $this->baseUrl . $endpoint .
      '?api_key=' . $this->apiKey .
      '&auth_token=' . $this->token .
      '&fields=collection_id,name';

    $curl    = curl_init($fullUrl);
    curl_setopt_array($curl, $this->curlOptions);

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $response    = json_decode($response, TRUE);
    $collections = $response['data']['Collection'];
    $collections_array = [];
    foreach ($collections as $collection) {
      $collections_array[$collection['collection_id']] = $collection['name'];
    }
    return $collections_array;
  }

  /**
   * Queue items for synchronization if automatic sync is set in config form.
   */
  public function queueSyncNew() {
    $config = \Drupal::service('config.factory')->getEditable('photoshelter.settings');
    $automaticSync = $config->get('cron_sync');
    if ($automaticSync) {
      $time   = $config->get('last_sync');

      // Get the date.
      if ($time === 'Never') {
        try {
          $time = new DateTime(NULL, new DateTimeZone('GMT'));
        }
        catch (Exception $e) {
          echo $e->getMessage();
          exit(1);
        }
      }
      else {
        $time = DateTime::createFromFormat(DateTime::RFC850, $time,
          new DateTimeZone('GMT'));
      }

      // Get the data.
      $queueItems = $this->getCollections($time, TRUE, 'queue');
      if (!empty($queueItems)) {
        \Drupal::logger('photoshelter')->notice(t('Start photoshelter queueing of galleries for synchronization'));

        $queue_factory = \Drupal::service('queue');
        $queue = $queue_factory->get('photoshelter_syncnew_gallery');
        $queue->createQueue();

        foreach ($queueItems as $queueItem) {
          $queue->createItem($queueItem);
        }

        // Update time saved in config.
        $this->updateConfigPostSync($config);
      }
      else {
        \Drupal::logger('photoshelter')->notice(t('No new data to synchronize on photoshelter'));
      }
    }
  }

  /**
   * Get data to synchronize in a batch.
   *
   * @param DateTime $time
   *   Date to compare with for update.
   * @param bool $update
   *   If update or full sync.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getData(DateTime $time, $update = FALSE) {

    $operations = $this->getCollections($time, $update, 'batch');
    $batch = array(
      'title' => t('galleries import'),
      'operations' => $operations,
      'finished' => 'photoshelter_sync_finished',
      'file' => drupal_get_path('module', 'photoshelter') . '/photoshelter.batch.inc',
    );
    if ($update) {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter synchronization of new additions'));
    }
    else {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter synchronization of all data'));
    }

    batch_set($batch);
  }

  /**
   * Retrieve Collections.
   *
   * @param DateTime $time
   *   Date to compare with for update.
   * @param bool $update
   *   If update or full sync.
   * @param string $process
   *   Type of process (batch or queue).
   *
   * @return array
   *   Array of operations for batch or array of data for queue.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function getCollections(DateTime $time, $update, $process) {
    $collections = $this->collections;
    $extend = [
      'KeyImage' => [
        'fields' => 'image_id',
        'params' => [],
      ],
      'ImageLink' => [
        'fields' => 'link',
        'params' => [
          'image_size' => $this->maxDim,
          'f_https_link' => 't',
        ],
      ],
      'Visibility' => [
        'fields' => 'mode',
        'params' => [],
      ],
      'Children' => [
        'Gallery' => [
          'fields' => 'gallery_id,name,description,f_list,modified_at,access_inherit',
          'params' => [],
        ],
        'Collection' => [
          'fields' => 'collection_id,name,description,f_list,modified_at,access_inherit',
          'params' => [],
        ],
      ],
    ];
    $extend_json = json_encode($extend);
    // Cycle through all collections.
    $operations = [];
    foreach ($collections as $key => $collectionId) {
      if ($collectionId != '0') {
        $endpoint = 'collection/' . $collectionId;
        $fullUrl  = $this->baseUrl . $endpoint .
          '?fields=collection_id,name,description,mode,f_list,modified_at' .
          '&api_key=' . $this->apiKey .
          '&auth_token=' . $this->token .
          '&extend=' . $extend_json;

        $curl    = curl_init($fullUrl);
        curl_setopt_array($curl, $this->curlOptions);

        $response = curl_exec($curl);
        $err      = curl_error($curl);

        curl_close($curl);

        if ($err) {
          echo "cURL Error #:" . $err;
          exit(1);
        }
        $response    = json_decode($response, TRUE);
        $collection_array = $response['data']['Collection'];
        if ($collection_array['f_list'] === 'f' && $this->allowPrivate == FALSE) {
          continue;
        }
        if ($process == 'batch') {
          $operations = array_merge($operations, $this->saveOneCollection($collection_array, $time, $update, NULL, 'batch'));
        }
        elseif ($process == 'queue') {
          $operations = array_merge($operations, $this->saveOneCollection($collection_array, $time, $update, NULL, 'queue'));
        }
      }
    }
    return $operations;
  }

  /**
   * Send request for a child collection.
   *
   * @param string $collectionId
   *   The collection ID.
   * @param DateTime $time
   *   Date to compare with for update.
   * @param bool $update
   *   If update or full sync.
   * @param string $process
   *   Type of process (batch or queue).
   * @param string|null $parentId
   *   Parent collection ID.
   *
   * @return array
   *   Array of operations for batch or array of data for queue.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function curlOneCollection($collectionId, DateTime $time, $update, $process, $parentId = NULL) {
    $endpoint = 'collection/' . $collectionId;
    $extend = [
      'KeyImage' => [
        'fields' => 'image_id',
        'params' => [],
      ],
      'ImageLink' => [
        'fields' => 'link',
        'params' => [
          'image_size' => $this->maxDim,
          'f_https_link' => 't',
        ],
      ],
      'Visibility' => [
        'fields' => 'mode',
        'params' => [],
      ],
      'Children' => [
        'Gallery' => [
          'fields' => 'gallery_id,name,description,f_list,modified_at,access_inherit',
          'params' => [],
        ],
        'Collection' => [
          'fields' => 'collection_id,name,description,f_list,modified_at,access_inherit',
          'params' => [],
        ],
      ],
    ];
    $extend_json = json_encode($extend);
    $fullUrl  = $this->baseUrl . $endpoint .
      '?fields=collection_id,name,description,f_list,mode,modified_at' .
      '&api_key=' . $this->apiKey .
      '&auth_token=' . $this->token .
      "&extend=" . $extend_json;

    $curl    = curl_init($fullUrl);
    curl_setopt_array($curl, $this->curlOptions);

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
    }

    $jsonResponse = json_decode($response, TRUE);
    $collection   = $jsonResponse['data']['Collection'];
    $operations = [];
    if ($process == 'batch') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['mode'], $parentId, 'batch');
    }
    elseif ($process == 'queue') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['mode'], $parentId, 'queue');
    }

    return $operations;
  }

  /**
   * Get one collection data.
   *
   * @param array $collection
   *   The collection data array.
   * @param DateTime $time
   *   Date to compare with for update.
   * @param bool $update
   *   If update or full sync.
   * @param string $collectionVisibility
   *   Collection visibility.
   * @param string $process
   *   Type of process (batch or queue).
   * @param string|null $parentId
   *   Parent collection ID.
   *
   * @return array
   *   Array of operations for batch or array of data for queue.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function saveOneCollection(array $collection, DateTime $time, $update, $collectionVisibility, $process, $parentId = NULL) {
    $collectionId   = $collection['collection_id'];
    $collectionName = $collection['name'];
    $cModified      = $collection['modified_at'];
    $cDescription   = $collection['description'];
    $cKeyImage      = $collection['KeyImage']['image_id'];
    $cChildren      = $collection['Children'];
    $cKeyImageFile  = $collection['KeyImage']['ImageLink']['link'];
    unset($collection);

    // Check if modified time is after time.
    $collectionTime = DateTime::createFromFormat('Y-m-d H:i:s e',
      $cModified, new DateTimeZone('GMT'));
    if ($update && $collectionTime < $time) {

    }
    else {
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
        $term->set('field_ps_permission', $collectionVisibility);
        $term->set('field_ps_parent_id', $parentId);
        $term->set('field_ps_parent_collection', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
        $term->set('field_ps_modified_at', $cModified);
        $term->set('field_ps_key_image_id', $cKeyImage);
        $term->set('field_ps_key_image', isset($file) ? ['target_id' => $file->id()] : NULL);
      }
      else {
        // Create term from $collection.
        $term = Term::create([
          'langcode'             => 'en',
          'vid'                 => 'ps_collection',
          'name'           => $collectionName,
          'description'    => $cDescription,
          'field_ps_permission'   => $collectionVisibility,
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
      }
      catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
      }

      if (isset($file)) {
        unset($file);
      }
      unset($term);
    }

    $operations = [];
    // Create terms for children.
    if (isset($cChildren)) {
      foreach ($cChildren as $child) {
        switch (key($cChildren)) {
          case 'Gallery':
            foreach ($child as $gallery) {
              if ($update) {
                $galleryModified = $gallery['modified_at'];
                // Check if modified time is after time.
                $galleryTime = DateTime::createFromFormat('Y-m-d H:i:s e', $galleryModified, new DateTimeZone('GMT'));
                if ($galleryTime < $time) {
                  unset($gallery);
                  continue;
                }
              }
              if ($process == 'batch') {
                $operations[] = [
                  'photoshelter_sync_gallery',
                  [$gallery, $time, $update, $collectionId],
                ];
              }
              elseif ($process == 'queue') {
                $operations[] = [
                  'gallery' => $gallery,
                  'time' => $time,
                  'update' => $update,
                  'parentId' => $collectionId,
                ];
              }
              unset($gallery);
            }
            break;

          case 'Collection':
            foreach ($child as $childCollection) {
              if ($process == 'batch') {
                $operations = $this->curlOneCollection($childCollection['collection_id'], $time, $update, 'batch', $collectionId);
              }
              elseif ($process == 'queue') {
                $operations = $this->curlOneCollection($childCollection['collection_id'], $time, $update, 'queue', $collectionId);
              }

              unset($childCollection);
            }
            unset($collection);
            break;
        }
        unset($child);
        next($cChildren);
      }
    }
    return $operations;
  }

  /**
   * Save gallery term and trigger getPhotos.
   *
   * @param array $gallery
   *   Gallery data array.
   * @param DateTime $time
   *   Date to compare with for update.
   * @param bool $update
   *   If update or full sync.
   * @param string $process
   *   Type of process (batch or queue).
   * @param string|null $parentId
   *   Parent collection ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getGallery(array $gallery, DateTime $time, $update, $process, $parentId = NULL) {
    $galleryVisibility  = $gallery['Visibility']['mode'];
    $galleryId          = $gallery['gallery_id'];
    $galleryModified    = $gallery['modified_at'];
    $galleryName        = $gallery['name'];
    $galleryDescription = $gallery['description'];
    $galleryImage       = $gallery['KeyImage']['image_id'];
    $galleryImageFile   = $gallery['KeyImage']['ImageLink']['link'];
    unset($gallery);

    if (isset($galleryImageFile)) {
      $file = File::create(['uri' => $galleryImageFile]);
      $file->save();
    }

    // If already exists, update instead of create.
    $gallery_id = $this->galleryExists($galleryId);
    if (!empty($gallery_id)) {
      $term = Term::load($gallery_id);
      $term->set('name', $galleryName);
      $term->set('description', $galleryDescription);
      $term->set('field_ps_permission', $galleryVisibility);
      $term->set('field_ps_parent_id', $parentId);
      $term->set('field_ps_parent_collection', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
      $term->set('field_ps_modified_at', $galleryModified);
      $term->set('field_ps_key_image_id', $galleryImage);
      $term->set('field_ps_key_image', isset($file) ? ['target_id' => $file->id()] : NULL);
    }
    else {
      $term = Term::create([
        'langcode'             => 'en',
        'vid'                 => 'ps_gallery',
        'name'           => $galleryName,
        'description'    => $galleryDescription,
        'field_ps_permission'   => $galleryVisibility ,
        'field_ps_id'             => $galleryId,
        'field_ps_parent_id'      => $parentId,
        'field_ps_parent_collection' => isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL,
        'field_ps_modified_at' => $galleryModified,
        'field_ps_key_image_id'   => $galleryImage,
        'field_ps_key_image' => isset($file) ? ['target_id' => $file->id()] : NULL,
      ]);
    }

    try {
      $term->save();
    }
    catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }

    if (isset($file)) {
      unset($file);
    }
    unset($term);

    $this->getPhotos($galleryId, $galleryVisibility, $time, $process, $update);
  }

  /**
   * Get Photo list and set synchronization process.
   *
   * @param string $parentId
   *   The parent gallery Id.
   * @param string $parentVisibility
   *   The parent visibility.
   * @param \DateTime $time
   *   Date to compare with for update.
   * @param string $process
   *   Type of process (batch or queue).
   * @param bool $update
   *   If update or full sync.
   */
  public function getPhotos($parentId, $parentVisibility, DateTime $time, $process, $update) {
    if ($process == 'queue') {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter queueing of photo for synchronization'));
      $queue_factory = \Drupal::service('queue');
      $queue = $queue_factory->get('photoshelter_syncnew_photo');
      $queue->createQueue();
    }
    $endpoint = 'gallery/' . $parentId . '/images';
    $extend = [
      'Image' => [
        'fields' => 'image_id,file_name,updated_at',
        'params' => [],
      ],
      'ImageLink' => [
        'fields' => 'link,auth_link',
        'params' => [
          'image_size' => $this->maxDim,
          'f_https_link' => 't',
        ],
      ],
      'Iptc' => [
        'fields' => 'keyword,credit,caption,copyright',
        'params' => [],
      ],
    ];
    $extend_json = json_encode($extend);
    $page = 1;
    do {
      // Get list of images in gallery.
      $fullUrl  = $this->baseUrl . $endpoint .
        '?fields=image_id,f_visible' .
        '&api_key=' . $this->apiKey .
        '&auth_token=' . $this->token .
        '&per_page=750' .
        '&page=' . $page .
        "&extend=" . $extend_json;

      $curl    = curl_init($fullUrl);curl_setopt_array($curl, $this->curlOptions);
      $response = curl_exec($curl);
      $err      = curl_error($curl);
      curl_close($curl);
      if ($err) {
        echo "cURL Error #:" . $err;
        exit(1);
      }
      $response = json_decode($response, TRUE);
      if ($response['status'] != 'ok') {
        $this->messenger->addError(t('authentication problem.'));
        exit(1);
      }
      $images   = $response['data']['GalleryImage'];
      $paging   = $response['data']['Paging'];
      if (!empty($paging) && !array_key_exists('next', $paging)) {
        $page = 0;
      }
      unset($paging);
      unset($response);

      // Cycle through all images.
      $operations = [];
      foreach ($images as $image) {
        if ($update) {
          $imageUpdate   = $image['Image']['updated_at'];
          // Check if modified time is after time.
          $imageTime = DateTime::createFromFormat('Y-m-d H:i:s e', $imageUpdate, new DateTimeZone('GMT'));
          if ($imageTime < $time) {
            continue;
          }
        }
        if ($process == 'batch') {
          $operations[] = [
            'photoshelter_sync_photo',
            [$image, $parentVisibility],
          ];
        }
        elseif ($process == 'queue') {
          $data = [
            'image' => $image,
            '$parentVisibility' => $parentVisibility,
          ];
          $queue->createItem($data);
        }
      }

      if ($page !== 0) {
        $page++;
      }
    } while ($page !== 0);

    if ($process == 'batch') {
      $batch = array(
        'title' => t('photos import'),
        'operations' => $operations,
        'finished' => 'photoshelter_sync_photo_finished',
        'file' => drupal_get_path('module', 'photoshelter') . '/photoshelter.batch.inc',
      );

      batch_set($batch);
    }
  }

  /**
   * Save one photo.
   *
   * @param array $image
   *   Image data array.
   * @param string $parentVisibility
   *   Parent gallery visibility.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getPhoto(array $image, $parentVisibility) {
    // Skip if image isn't public.
    if ($image['f_visible'] === 'f' && $this->allowPrivate == FALSE) {
      return;
    }
    $imageUpdate   = $image['Image']['updated_at'];
    $imageId       = $image['image_id'];
    $imageName     = $image['Image']['file_name'];
    $imageKeywords = $image['Image']['Iptc']['keyword'];
    $imageLink     = $image['ImageLink']['link'];
    $imageCaption  = $image['Image']['Iptc']['caption'];
    $imageCredit   = $image['Image']['Iptc']['credit'];
    $imageCopyright = $image['Image']['Iptc']['copyright'];
    $parentId      = $image['Image']['gallery_id'];
    unset($image);
    \Drupal::logger('photoshelter')->notice($imageCopyright);
    if (isset($imageLink)) {
      $file = File::create([
        'uri' => $imageLink,
        'alt' => $imageName,
      ]);
      $file->save();
    }

    // If already exists, update instead of create.
    $media_id = $this->imageExists($imageId);
    if (!empty($media_id)) {
      $media = Media::load($media_id);
      $media->set('name', $imageName);
      $media->set('field_ps_permission', $parentVisibility);
      $media->set('field_ps_parent_id', $parentId);
      $media->set('field_ps_parent_gallery', isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL);
      $media->set('field_ps_modified_at', $imageUpdate);
      $media->set('field_ps_caption', $imageCaption);
      $media->set('field_ps_credit', $imageCredit);
      $media->set('field_ps_copyright', $imageCopyright);
      $media->set('field_media_image', isset($file) ? [
        'target_id' => $file->id(),
        'alt' => $imageName,
      ] : NULL);

    }
    else {
      // Create media entity from $image.
      $media = Media::create([
        'langcode'             => 'en',
        'uid'                  => $this->uid,
        'bundle'                 => 'ps_image',
        'name'                => $imageName,
        'status'               => 1,
        'created'              => \Drupal::time()->getRequestTime(),
        'field_ps_permission'   => $parentVisibility,
        'field_ps_id'             => $imageId,
        'field_ps_parent_id'      => $parentId,
        'field_ps_parent_gallery' => isset($parentId) ? ['target_id' => $this->getParentTerm($parentId)] : NULL,
        'field_ps_modified_at' => $imageUpdate,
        'field_ps_caption'        => $imageCaption,
        'field_ps_credit'         => $imageCredit,
        'field_ps_copyright' => $imageCopyright,
        'field_media_image' => isset($file) ? [
          'target_id' => $file->id(),
          'alt' => $imageName,
        ] : NULL,
      ]);
    }
    $terms = [];
    if (isset($imageKeywords) && !empty($imageKeywords)) {
      $taxonomy = explode(',', $imageKeywords);
      foreach ($taxonomy as $term) {
        $term = trim($term);
        $termId = $this->termExists($term, 'ps_tags');
        if ($termId === 0) {
          $keyword = Term::create([
            'name' => $term,
            'vid'  => 'ps_tags',
          ]);
          $keyword->save();
          $terms[] = ['target_id' => $keyword->id()];
        }
        else {
          $terms[] = ['target_id' => $termId];
        }
      }
    }

    $media->set('field_ps_tags', $terms);

    try {
      $media->save();
    }
    catch (Exception $e) {
      echo $e->getMessage();
      exit(1);
    }
    if (isset($file)) {
      unset($file);
    }
    unset($media);
  }

  /**
   * Get the parent term id.
   *
   * @param string $parent_ps_id
   *   Photoshelter id of the parent collection or gallery.
   *
   * @return string
   *   The parent term id.
   */
  private function getParentTerm($parent_ps_id) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('field_ps_id', $parent_ps_id);
    $tids = $query->execute();
    $tid = !empty($tids) ? reset($tids) : '';
    return $tid;
  }

  /**
   * Check if collection term exist.
   *
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
   * Check if gallery term exist.
   *
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
   * Check if media entity exist.
   *
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

  /**
   * Check by name if PS tag term exist.
   *
   * @param string|null $name
   *   The term name.
   * @param string|null $vid
   *   The term vocabulary.
   *
   * @return bool
   *   True or False.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function termExists($name = NULL, $vid = NULL) {
    $properties = [];
    if (!empty($name)) {
      $properties['name'] = $name;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);

    return !empty($term) ? $term->id() : 0;
  }

  /**
   * Update the photoshelter config last synchronization date.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   * @param bool $isFullSync
   *   If it's a full sync or an update.
   */
  public function updateConfigPostSync(Config &$config, $isFullSync = FALSE) {
    try {
      $currentTime = new DateTime(NULL, new DateTimeZone('GMT'));
    }
    catch (Exception $e) {
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
