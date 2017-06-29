<?php
/**
 * @file
 * Contains Drupal\photoshelter\Plugin\Block\ExampleEmptyBlock.
 */

namespace Drupal\photoshelter\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Example: empty block' block.
 *
 * @Block (
 *   id = "example_empty",
 *   admin_label = @Translation("Example: empty block")
 * )
 */
class ExampleEmptyBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#type' => 'markup',
      '#markup' => '',
    ];
  }
}