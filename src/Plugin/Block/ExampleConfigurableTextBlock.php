<?php

/**
 * @file
 * Contains \Drupal\photoshelter\Plugin\Block\ExampleConfigurableTextBlock.
 */

namespace Drupal\photoshelter\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Example: configurable text string' block.
 *
 * Drupal\block\BlockBase gives us a very useful set of basic functionality for
 * this configurable block. We can just fill in a few of the blanks with
 * defaultConfiguration(), blockForm(), blockSubmit(), and build().
 *
 * @Block(
 *   id = "example_configurable_text",
 *   admin_label = @Translation("Title of first block (example_configurable_text)"),
 *   category = @Translation("PhotoShelter")
 * )
 */
class ExampleConfigurableTextBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'block_example_string' => $this->t('A default value. This block was created at %time', ['%time' => date('c')]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['block_example_string_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block contents'),
      '#size' => 60,
      '#description' => $this->t('This text will appear in the example block.'),
      '#default_value' => $this->configuration['block_example_string'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['block_example_string']
      = $form_state->getValue('block_example_string_text');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#type' => 'markup',
      '#markup' => $this->configuration['block_example_string'],
    ];
  }

}
