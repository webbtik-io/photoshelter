<?php

namespace Drupal\photoshelter;

use DateTime;
use DateTimeZone;
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
      }
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

      return $this->token;
    }
  }



  /**
   * @param array $gallery
   * @param DateTime $time
   * @param $update
   * @param null $parentId
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getGallery(array &$gallery, DateTime &$time, $update, &$parentId = NULL) {
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

    $this->getPhotos($galleryId, $cas_required, $time, $update);
  }

  /**
   * @param string $parentId
   * @param bool $parentCas
   * @param bool $update
   * @param \DateTime $time
   */
  public function getPhotos(&$parentId, $parentCas, DateTime &$time, $update) {
    $page = 1;
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
        $operations[] = ['photoshelter_sync_photo', array($image, $parentId, $parentCas, $time, $update)];
      }

      $batch = array(
        'title' => t('photos import'),
        'operations' => $operations,
        'finished' => 'photoshelter_sync_photo_finished',
        'file' => drupal_get_path('module', 'photoshelter'). '/photoshelter.batch.inc',
      );

      batch_set($batch);

      if ($page !== 0) {
        $page++;
      }
    } while ($page !== 0);
  }

  /**
   * @param array $image
   * @param $parentId
   * @param $parentCas
   * @param DateTime $time
   * @param $update
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getPhoto(array $image, $parentId, $parentCas, DateTime &$time, $update) {
    // Skip if image isn't public
    /*if ($image['f_visible'] === 'f') {
      unset($image);
      return;
    }*/
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
        return;
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

}
