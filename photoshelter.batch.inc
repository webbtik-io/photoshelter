<?php

function photoshelter_sync_gallery(array $gallery, DateTime $time, $update, $parentId, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }

  $service = $context['service'];
  $service->getGallery($gallery, $time, $update, $parentId);

  $context['results'][] = $gallery['name'];

  $message = t('Synchronization of gallery '. $gallery['name']);

  $context['message'] = $message;

}

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

function photoshelter_sync_photo(array $image, $parentId, $parentCas, &$context) {
  if (!isset($context['service'])) {
    $context['service'] = \Drupal::service('photoshelter.photoshelter_service');
  }

  $service = $context['service'];
  $service->getPhoto($image, $parentId, $parentCas);

  $context['results'][] = $image['Image']['file_name'];

  $message = t('Synchronization of photo '. $image['Image']['file_name']);

  $context['message'] = $message;

}

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
