<?php

namespace Drupal\file_link\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file_link\FileLinkInterface;
use Drupal\link\Plugin\Field\FieldType\LinkItem;

/**
 * Implements a 'file_link' plugin field type.
 *
 * This field type is an extension of 'link' filed-type that points only to
 * files, not to directories and, additionally, stores some meta-data related to
 * targeted file, like size and mime-type.
 *
 * @FieldType(
 *   id = "file_link",
 *   label = @Translation("File Link"),
 *   description = @Translation("Stores a URL string pointing to a file, optional varchar link text, and file metadata, like size and mime-type."),
 *   default_widget = "file_link_default",
 *   default_formatter = "link",
 *   constraints = {"LinkAccess" = {}, "LinkToFile" = {}, "LinkExternalProtocols" = {}, "LinkNotExistingInternal" = {}}
 * )
 */
class FileLinkItem extends LinkItem implements FileLinkInterface {

  /**
   * Last HTTP response code.
   *
   * @var string
   */
  protected $lastHttpResponseCode = NULL;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'file_extensions' => 'txt',
      'no_extension' => FALSE,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    return parent::propertyDefinitions($field_definition) + [
      'size' => DataDefinition::create('integer')->setLabel(t('Size')),
      'format' => DataDefinition::create('string')->setLabel(t('Format')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);
    $schema['columns']['size'] = [
      'description' => 'The size of the file.',
      'type' => 'int',
      'size' => 'big',
      'unsigned' => TRUE,
    ];
    $schema['columns']['format'] = [
      'description' => 'The format of the file.',
      'type' => 'varchar',
      'length' => 255,
    ];
    $schema['indexes']['size'] = ['size'];
    $schema['indexes']['format'] = ['format'];
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);

    // Make the extension list a little more human-friendly by comma-separation.
    $extensions = str_replace(' ', ', ', $this->getSetting('file_extensions'));
    $element['file_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#default_value' => $extensions,
      '#description' => $this->t('Separate extensions with a space or comma and do not include the leading dot. Leave empty to allow any extension.'),
      // Use the 'file' field type validator.
      '#element_validate' => [[FileItem::class, 'validateExtensions']],
      '#maxlength' => 256,
    ];
    $element['no_extension'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow URLs without file extension'),
      '#description' => $this->t('The link can refer a document such as a wiki page or a dynamic generated page that has no extension. Check this if you want to allow such URLs.'),
      '#default_value' => $this->getSetting('no_extension'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $values = parent::generateSampleValue($field_definition);
    $values['size'] = 1234567;
    $values['format'] = 'image/png';
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    parent::preSave();
    $entity = $this->getEntity();
    $storage = $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId());
    /** @var \Drupal\Core\Entity\ContentEntityInterface $original */
    $original = $entity->isNew() ? NULL : $storage->loadUnchanged($entity->id());
    $field_name = $this->getFieldDefinition()->getName();
    $original_uri = $original ? $original->{$field_name}->uri : NULL;
    $size = $original ? $original->{$field_name}->size: NULL;
    $format = $original ? $original->{$field_name}->format: NULL;

    // We parse the metadata in any of the next cases:
    // - The host entity is new.
    // - The 'file_link' URI has changed.
    // - Stored metadata is empty, possible due to an previous failure. We try
    //   again to parse, hoping the connection was fixed in the meantime.
    $needs_parsing = $entity->isNew() || ($this->uri !== $original_uri) || empty($size) || empty($format);
    if ($needs_parsing) {
      // Don't throw exceptions on HTTP level errors (e.g. 404, 403, etc).
      $options = ['exceptions' => FALSE];
      $url = Url::fromUri($this->uri, ['absolute' => TRUE])->toString();
      // Perform only a HEAD method to save bandwidth.
      $response = \Drupal::httpClient()->head($url, $options);
      $this->setLastHttpResponseCode($response->getStatusCode());
      if ($this->getLastHttpResponseCode() == '200') {
        if ($response->hasHeader('Content-Type')) {
          // The format may have the pattern 'text/html; charset=UTF-8'. In this
          // case, keep only the first relevant part.
          $this->values['format'] = explode(';', $response->getHeaderLine('Content-Type'))[0];
        }
        else {
          $this->values['format'] = NULL;
        }
        if ($response->hasHeader('Content-Length')) {
          $this->values['size'] = (int) $response->getHeaderLine('Content-Length');
        }
        else {
          // The server didn't sent the Content-Length header. In this case,
          // perform a full GET and measure the size of returned body.
          $response = \Drupal::httpClient()->get($url, $options);
          $this->values['size'] = (int) $response->getBody()->getSize();
        }
      }
      else {
        $this->values['format'] = NULL;
        $this->values['size'] = 0;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setLastHttpResponseCode($code) {
    $this->lastHttpResponseCode = $code;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastHttpResponseCode() {
    return $this->lastHttpResponseCode;
  }

  /**
   * Returns the entity type manager service.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager service.
   */
  protected function getEntityTypeManager() {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

}
