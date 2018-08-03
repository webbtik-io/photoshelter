<?php

/**
 * @file
 * Contains batch functions for photoshelter synchronization.
 */

/**
 * Batch function for gallery synchronization.
 *
 * @param array $gallery
 *   The gallery data.
 * @param DateTime $time
 *   Date to compare with for update.
 * @param bool $update
 *   If update or full sync.
 * @param string $parentId
 *   Parent collection ID.
 * @param array $context
 *   The Batch context array.
 */
function photoshelter_sync_gallery(array $gallery, DateTime $time, $update, $parentId, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }

  $service = $context['service'];
  $service->getGallery($gallery, $time, $update, 'batch', $parentId);

  $context['results'][] = $gallery['name'];

  $message = t('Synchronization of gallery') . ' ' . $gallery['name'];

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
    $message = \Drupal::translation()->formatPlural(
      count($results),
      '1 Synchronized gallery', '@count Synchronized galleries.'
    );
    $logger->notice($message);
  }
  else {
    $message = t('Finished with an error.');
    $logger->error($message);
  }
  $messenger->addMessage($message);
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
