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
  protected $api_key;

  /**
   * Photoshelter authenticate token.
   *
   * @var string
   */
  protected $token;

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
  private $allow_private;

  /**
   * Options array for curl request.
   *
   * @var array
   */
  private $options;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
    $this->options    = [
      CURLOPT_RETURNTRANSFER   => TRUE,
      CURLOPT_ENCODING         => "",
      CURLOPT_MAXREDIRS        => 10,
      CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST    => "GET",
      CURLOPT_SSL_VERIFYPEER   => FALSE,
      CURLOPT_FOLLOWLOCATION
    ];
    if (defined('CURLOPT_SSL_VERIFYSTATUS')) {
      $this->options[CURLOPT_SSL_VERIFYSTATUS] = FALSE;
    }
    $this->uid = 1;
    $this->authenticate();
  }

  /**
   * Send request to authenticate to the Photoshelter service and update cookie file.
   *
   * @return string
   *   Authentication token string.
   */
  public function authenticate() {

    $config = \Drupal::config('photoshelter.settings');
    $email = $config->get('email');
    $password = $config->get('password');
    $this->api_key = $config->get('api_key');
    $this->allow_private = $config->get('allow_private');

    $endpoint = '/psapi/v3/mem/authenticate';
    $base_url = 'https://www.photoshelter.com';
    $fullUrl  = $base_url . $endpoint .
      '?api_key=' . $this->api_key .
      '&email=' . $email .
      '&password=' . $password .
      '&mode=token';

    // cURL to /psapi/v3/mem/authenticate to see if credentials are valid.
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, $this->options);
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
      } else {
        $this->token = $jsonResponse['data']['token'];
        if (isset($jsonResponse['data']['org'][0]['id']) && !empty($jsonResponse['data']['org'][0]['id'])) {
          $org_id = $jsonResponse['data']['org'][0]['id'];
          $endpoint = '/psapi/v3/mem/organization/' . $org_id . '/authenticate';
          $fullUrl  = $base_url . $endpoint .
            '?api_key=' . $this->api_key .
            '&auth_token=' . $this->token;
          $ch = curl_init($fullUrl);
          curl_setopt_array($ch, $this->options);
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
   * Queue items for synchronization if automatic sync is set in config form.
   */
  public function QueueSyncNew() {
    $config = \Drupal::service('config.factory')->getEditable('photoshelter.settings');
    $automaticSync = $config->get('cron_sync');
    if ($automaticSync) {
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
      $queueItems = $this->getCollections($time, TRUE, 'queue');
      if (!empty($queueItems)) {
        \Drupal::logger('photoshelter')->notice(t('Start photoshelter queueing of galleries for synchronization'));

        $queue_factory = \Drupal::service('queue');
        $queue = $queue_factory->get('photoshelter_syncnew_gallery');
        $queue->createQueue();

        foreach ($queueItems as $queueItem) {
          $queue->createItem($queueItem);
        }

        // Update time saved in config
        $this->updateConfigPostSync($config);
      } else {
        \Drupal::logger('photoshelter')->notice(t('No new data to synchronize on photoshelter'));
      }
    }
  }


  /**
   * @param bool $update
   * @param \DateTime $time
   */
  public function getData(DateTime &$time, $update = FALSE) {

    $operations = $this->getCollections($time, $update, 'batch');
    $batch = array(
      'title' => t('galleries import'),
      'operations' => $operations,
      'finished' => 'photoshelter_sync_finished',
      'file' => drupal_get_path('module', 'photoshelter'). '/photoshelter.batch.inc',
    );
    if ($update) {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter synchronization of new additions'));
    } else {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter synchronization of all data'));
    }

    batch_set($batch);
  }

  /**
   * @param bool $update
   * @param \DateTime $time
   * @param string $process
   */
  private function getCollections(DateTime &$time, $update, $process) {
    // Get collection and gallery data
    $curl    = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection?fields=collection_id,name,description,f_list,modified_at&api_key=$this->api_key&auth_token=$this->token&extend={%22Permission%22:{%22fields%22:%22mode%22,%22params%22:{}},%22KeyImage%22:%20{%22fields%22:%22image_id,gallery_id%22,%22params%22:{}},%22Visibility%22:%20{%22fields%22:%22mode%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Children%22:{%22Gallery%22:{%22fields%22:%22gallery_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}},%22Collection%22:{%22fields%22:%22collection_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}}}}");
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
    $operations = [];
    foreach ($collections as $collection) {
      if ($collection['f_list'] === 'f' && $this->allow_private == FALSE) {
        unset($collection);
        continue;
      }
      if ($process == 'batch') {
        $operations = array_merge($operations, $this->getOneCollection($collection, $time, $update, NULL, 'batch'));
      } elseif ($process == 'queue') {
        $operations = array_merge($operations, $this->getOneCollection($collection, $time, $update, NULL, 'queue'));
      }
      unset($collection);
    }

    unset($collections);

    return $operations;
  }

  /**
   * @param array $collection
   * @param \DateTime $time
   * @param bool $update
   * @param string $parentId
   * @param string $process
   */
  private function getOneCollection(array &$collection, DateTime &$time, $update, $parentId = NULL, $process) {
    if ($process == 'batch') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['Permission']['mode'], $parentId, 'batch');
    } elseif ($process == 'queue') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['Permission']['mode'], $parentId, 'queue');
    }

    return $operations;
  }

  /**
   * @param string $collectionId
   * @param \DateTime $time
   * @param bool $update
   * @param string|NULL $parentId
   * @param string $process
   */

  private function curlOneCollection($collectionId, DateTime &$time, $update, $parentId = NULL, $process) {

    $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/collection/$collectionId?api_key=$this->api_key&auth_token=$this->token&fields=collection_id,name,description,f_list,mode,modified_at&extend={%22Permission%22:{%22fields%22:%22mode%22,%22params%22:{}},%22KeyImage%22:%20{%22fields%22:%22image_id,gallery_id%22,%22params%22:{}},%22Visibility%22:%20{%22fields%22:%22mode%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Children%22:{%22Gallery%22:{%22fields%22:%22gallery_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}},%22Collection%22:{%22fields%22:%22collection_id,name,description,f_list,modified_at,access_inherit%22,%22params%22:{}}}}");
    curl_setopt_array($curl, $this->options);

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
    }

    $jsonResponse = json_decode($response, TRUE);
    $collection   = $jsonResponse['data']['Collection'];
    if ($process == 'batch') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['Visibility']['mode'], $parentId, 'batch');
    } elseif ($process == 'queue') {
      $operations = $this->saveOneCollection($collection, $time, $update, $collection['Visibility']['mode'], $parentId, 'queue');
    }

    unset($collection);
    return $operations;
  }

  /**
   * @param array $collection
   * @param \DateTime $time
   * @param bool $update
   * @param string $cPermission
   * @param string|NULL $parentId
   * @param string $process
   */

  private function saveOneCollection(array &$collection, DateTime &$time, $update, $cPermission, $parentId = NULL, $process) {
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
    if ($update && $collectionTime < $time) {

    } else {
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
    }

    $operations = [];
    // Create nodes for children
    if (isset($cChildren)) {
      foreach ($cChildren as $child) {
        switch (key($cChildren)) {
          case 'Gallery':
            foreach ($child as $gallery) {
              if ($update) {
                $galleryModified = $gallery['modified_at'];
                // Check if modified time is after time
                $galleryTime = DateTime::createFromFormat('Y-m-d H:i:s e', $galleryModified, new DateTimeZone('GMT'));
                if ($galleryTime < $time) {
                  unset($gallery);
                  continue;
                }
              }
              if ($process == 'batch') {
                $operations[] = ['photoshelter_sync_gallery', array($gallery, $time, $update, $collectionId)];
              } elseif ($process == 'queue') {
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
                $operations = $this->curlOneCollection($childCollection['collection_id'], $time, $update, $collectionId, 'batch');
              } elseif ($process == 'queue') {
                $operations = $this->curlOneCollection($childCollection['collection_id'], $time, $update, $collectionId, 'queue');
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
   * @param array $gallery
   * @param DateTime $time
   * @param $update
   * @param null $parentId
   * @param string $process
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getGallery(array &$gallery, DateTime &$time, $update, &$parentId = NULL, $process) {
    $galleryPermission  = $gallery['Visibility']['mode'];
    $galleryId          = $gallery['gallery_id'];
    $galleryModified    = $gallery['modified_at'];
    $galleryName        = $gallery['name'];
    $galleryDescription = $gallery['description'];
    $galleryImage       = $gallery['KeyImage']['image_id'];
    $galleryImageFile   = $gallery['KeyImage']['ImageLink']['link'];
    unset($gallery);

    $cas_required = $this->getPermission($galleryPermission);

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
        'field_ps_key_image' => isset($file) ? ['target_id' => $file->id()] : NULL,
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

    $this->getPhotos($galleryId, $cas_required, $time, $update, $process);
  }

  /**
   * @param string $parentId
   * @param bool $parentCas
   * @param bool $update
   * @param \DateTime $time
   * @param string $process
   */
  public function getPhotos(&$parentId, $parentCas, DateTime &$time, $update, $process) {
    $page = 1;
    if ($process == 'queue') {
      \Drupal::logger('photoshelter')->notice(t('Start photoshelter queueing of photo for synchronization'));
      $queue_factory = \Drupal::service('queue');
      $queue = $queue_factory->get('photoshelter_syncnew_photo');
      $queue->createQueue();
    }
    do {
      // Get list of images in gallery
      $curl = curl_init("https://www.photoshelter.com/psapi/v3/mem/gallery/$parentId/images?fields=image_id,f_visible&api_key=$this->api_key&auth_token=$this->token&per_page=750&page=$page&extend={%22Image%22:{%22fields%22:%22image_id,file_name,updated_at%22,%22params%22:{}},%22ImageLink%22:{%22fields%22:%22link,auth_link%22,%22params%22:{%22image_size%22:%22x700%22,%22f_https_link%22:%22t%22}},%22Iptc%22:{%22fields%22:%22keyword,credit,caption%22,%22params%22:{}}}");
      curl_setopt_array($curl, $this->options);
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

      // Cycle through all images
      $operations = [];
      foreach ($images as $image) {
        if ($update) {
          $imageUpdate   = $image['Image']['updated_at'];
          // Check if modified time is after time
          $imageTime = DateTime::createFromFormat('Y-m-d H:i:s e', $imageUpdate, new DateTimeZone('GMT'));
          if ($imageTime < $time) {
            continue;
          }
        }
        if ($process == 'batch') {
          $operations[] = ['photoshelter_sync_photo', array($image, $parentCas)];
        } elseif ($process == 'queue') {
          $data = [
            'image' => $image,
            'parentCas' => $parentCas,
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
   * @param array $image
   * @param $parentId
   * @param $parentCas
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getPhoto(array $image, $parentCas) {
    // Skip if image isn't public
    if ($image['f_visible'] === 'f' && $this->allow_private == FALSE) {
      return;
    }
    $imageUpdate   = $image['Image']['updated_at'];
    $imageId       = $image['image_id'];
    $imageName     = $image['Image']['file_name'];
    $imageKeywords = $image['Image']['Iptc']['keyword'];
    $imageLink     = $image['ImageLink']['link'];
    $imageCaption  = nl2br($image['Image']['Iptc']['caption']);
    $imageCredit   = $image['Image']['Iptc']['credit'];
    $parentId      = $image['Image']['gallery_id'];
    unset($image);

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

  /**
   * @param string $permission
   *
   * @return bool
   */
  private function getPermission(&$permission) {
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
   * @param bool $isFullSync
   * @param \Drupal\Core\Config\Config $config
   */
  public function updateConfigPostSync(Config &$config, $isFullSync = FALSE) {
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
