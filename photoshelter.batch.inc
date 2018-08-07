<?php

/**
 * @file
 * Contains batch functions for photoshelter synchronization.
 */

/**
 * Batch function for gallery synchronization.
 *
 * @param string $gallery_id
 *   The gallery ID.
 * @param DateTime $time
 *   Date to compare with for update.
 * @param bool $update
 *   If update or full sync.
 * @param string $parentId
 *   Parent collection ID.
 * @param array $context
 *   The Batch context array.
 */
function photoshelter_sync_gallery($gallery_id, DateTime $time, $update, $parentId, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }

  $service = $context['service'];
  $service->getGallery($gallery_id, $time, $update, 'batch', $parentId);

  $context['results']['galleries'][] = $gallery_id;

  $message = t('Synchronization of gallery') . ' ' . $gallery_id;

  $context['message'] = $message;

}

/**
 * Batch function for collection synchronization.
 *
 * @param string $collection_id
 *   The collection ID.
 * @param DateTime $time
 *   Date to compare with for update.
 * @param bool $update
 *   If update or full sync.
 * @param string $parentId
 *   Parent collection ID.
 * @param array $context
 *   The Batch context array.
 */
function photoshelter_sync_collection($collection_id, DateTime $time, $update, $parentId, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }
  $service = $context['service'];
  $service->getCollection($collection_id, $time, $update, 'batch', $parentId);

  $context['results']['collections'][] = $collection_id;

  $message = t('Synchronization of collection') . ' ' . $collection_id;

  $context['message'] = $message;

}

/**
 * Gallery synchronization batch finish callback.
 *
 * @param bool $success
 *   If it succeed or not.
 * @param array $results
 *   Results item from context variable.
 * @param array $operations
 *   If success is false, array of not completed operations.
 */
function photoshelter_sync_finished($success, $results, $operations) {
  $messenger = \Drupal::messenger();
  $logger = \Drupal::logger('photoshelter');
  if ($success) {
    $message1 = \Drupal::translation()->formatPlural(
      count($results['collections']),
      '1 Synchronized collection', '@count Synchronized collections.'
    );
    $logger->notice($message1);
    $messenger->addMessage($message1);
    $message2 = \Drupal::translation()->formatPlural(
      count($results['galleries']),
      '1 Synchronized gallery', '@count Synchronized galleries.'
    );
    $logger->notice($message2);
    $messenger->addMessage($message2);
  }
  else {
    $message = t('Finished with an error.');
    $logger->error($message);
    $messenger->addMessage($message);
  }
}

/**
 * Batch function for photo synchronization.
 *
 * @param array $image
 *   Image data array.
 * @param string $parentVisibility
 *   Parent visibility.
 * @param array $context
 *   The Batch context array.
 */
function photoshelter_sync_photo(array $image, $parentVisibility, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }

  $service = $context['service'];
  $service->getPhoto($image, $parentVisibility);

  $context['results'][] = $image['Image']['file_name'];

  $message = t('Synchronization of photo') . ' ' . $image['Image']['file_name'];

  $context['message'] = $message;
}

/**
 * Photo synchronization batch finish callback.
 *
 * @param bool $success
 *   If it succeed or not.
 * @param array $results
 *   Results item from context variable.
 * @param array $operations
 *   If success is false, array of not completed operations.
 */
function photoshelter_sync_photo_finished($success, $results, $operations) {
  $messenger = \Drupal::messenger();
  $logger = \Drupal::logger('photoshelter');
  if ($success) {
    $message = \Drupal::translation()->formatPlural(
      count($results),
      '1 Synchronized photo', '@count Synchronized photos.'
    );
    $logger->notice($message);
  }
  else {
    $message = t('Finished with an error.');
    $logger->error($message);
  }
  $messenger->addMessage($message);

}
