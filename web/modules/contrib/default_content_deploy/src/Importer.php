<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content_deploy\Event\PreSaveEntityEvent;
use Drupal\default_content_deploy\Queue\DefaultContentDeployBatch;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Rogervila\ArrayDiffMultidimensional;
use Symfony\Component\Serializer\Serializer;

/**
 * A service for handling import of default content.
 *
 * The importContent() method is almost duplicate of
 *   \Drupal\default_content\Importer::importContent with injected code for
 *   content update. We are waiting for better DC code structure in a future.
 */
class Importer {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Deploy manager.
   *
   * @var \Drupal\default_content_deploy\DeployManager
   */
  protected $deployManager;

  /**
   * Scanned files.
   *
   * @var object[]
   */
  private $files;

  /**
   * Directory to import.
   *
   * @var string
   */
  private $folder;

  /**
   * Data to import.
   *
   * @var array
   */
  private $dataToImport = [];

  /**
   * Data to correct.
   *
   * @var array
   */
  private $dataToCorrect = [];

  /**
   * Path aliases to import.
   *
   * @var array
   */
  private $pathAliasesToImport = [];

  /**
   * Is remove changes of an old content.
   *
   * @var bool
   */
  protected $forceOverride;

  /**
   * Skip referenced entity ID correction.
   *
   * @var bool
   */
  protected $preserveIds = FALSE;

  /**
   * Incremental import.
   *
   * @var bool
   */
  protected $incremental = FALSE;

  /**
   * The Entity repository manager.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The cache data.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The link manager service.
   *
   * @var \Drupal\hal\LinkManager\LinkManagerInterface
   */
  protected $linkManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * DCD Exporter.
   *
   * @var \Drupal\default_content_deploy\Exporter
   */
  protected $exporter;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * The metadata service.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployMetadataService
   */
  protected $metadataService;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var bool
   */
  protected $verbose = FALSE;

  /**
   * Constructs the default content deploy manager.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The link manager service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The account switcher.
   * @param \Drupal\default_content_deploy\DeployManager $deploy_manager
   *   Deploy manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The Entity repository manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache data.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\default_content_deploy\DefaultContentDeployMetadataService $metadata_service
   *   The metadata service.
   * @param \Drupal\Core\State\StateInterface $state
   *    The state.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, AccountSwitcherInterface $account_switcher, DeployManager $deploy_manager, EntityRepositoryInterface $entity_repository, CacheBackendInterface $cache, Exporter $exporter, Connection $database, ContainerAwareEventDispatcher $event_dispatcher, DefaultContentDeployMetadataService $metadata_service, StateInterface $state) {
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
    $this->linkManager = $link_manager;
    $this->accountSwitcher = $account_switcher;
    $this->deployManager = $deploy_manager;
    $this->entityRepository = $entity_repository;
    $this->cache = $cache;
    $this->exporter = $exporter;
    $this->database = $database;
    $this->eventDispatcher = $event_dispatcher;
    $this->metadataService = $metadata_service;
    $this->state = $state;
  }

  /**
   * Is remove changes of an old content.
   *
   * @param bool $is_override
   *
   * @return \Drupal\default_content_deploy\Importer
   */
  public function setForceOverride(bool $is_override) {
    $this->forceOverride = $is_override;
    return $this;
  }

  /**
   * Set directory to import.
   *
   * @param string $folder
   *   The content folder.
   *
   * @return \Drupal\default_content_deploy\Importer
   */
  public function setFolder(string $folder) {
    $this->folder = $folder;
    return $this;
  }

  /**
   * Get directory to import.
   *
   * @return string
   *   The content folder.
   *
   * @throws \Exception
   */
  protected function getFolder() {
    $folder = $this->folder ?: $this->deployManager->getContentFolder();

    if (!isset($folder)) {
      throw new \Exception('Directory for content deploy is not set.');
    }

    return $folder;
  }

  public function setPreserveIds(bool $preserve) {
    $this->preserveIds = $preserve;
  }

  public function setIncremental(bool $incremental) {
    $this->incremental = $incremental;
  }

  /**
   * Get Imported data result.
   *
   * @return array
   */
  public function getResult() {
    return $this->dataToImport + $this->pathAliasesToImport;
  }

  public function setVerbose(bool $verbose): void {
    $this->verbose = $verbose;
  }


  /**
   * Import data from JSON and create new entities, or update existing.
   *
   * @return $this
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  public function prepareForImport(): void {
    // @todo remove because of changes in core >= 9.2
    $this->cache->delete('hal:links:relations');
    $this->files = $this->scan($this->getFolder());

    foreach ($this->files as $file) {
      if (!isset($this->dataToImport[$file->uuid]) && !isset($this->pathAliasesToImport[$file->uuid])) {
        $this->addToImport($file);
      }
    }
  }

  /**
   * Returns a list of file objects.
   *
   * @param string $directory
   *   Absolute path to the directory to search.
   *
   * @return object[]
   *   List of stdClass objects with name and uri properties.
   */
  public function scan($directory) {
    // Use Unix paths regardless of platform, skip dot directories, follow
    // symlinks (to allow extensions to be linked from elsewhere), and return
    // the RecursiveDirectoryIterator instance to have access to getSubPath(),
    // since SplFileInfo does not support relative paths.
    $flags = \FilesystemIterator::UNIX_PATHS;
    $flags |= \FilesystemIterator::SKIP_DOTS;
    $flags |= \FilesystemIterator::CURRENT_AS_SELF;
    $directory_iterator = new \RecursiveDirectoryIterator($directory, $flags);
    $iterator = new \RecursiveIteratorIterator($directory_iterator);
    $files = [];

    /* @var \SplFileInfo $file_info */
    foreach ($iterator as $file_info) {
      // Skip directories and non-json files.
      if ($file_info->isDir() || $file_info->getExtension() !== 'json' || str_contains($file_info->getPathname(), '_deleted')) {
        continue;
      }

      $file = new \stdClass();
      $file->name = $file_info->getFilename();
      $file->uuid = str_replace('.json', '', $file->name);
      $file->uri = $file_info->getPathname();
      $file->entity_type_id = basename(dirname($file->uri));
      $file->forceOverride = $this->forceOverride;

      $files[$file->uri] = $file;
    }

    return $files;
  }

  /**
   * Import to entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function import() {
    // Process files in batches.
    $operations = [];
    $total = count($this->dataToImport) + count($this->dataToCorrect) + count($this->pathAliasesToImport);
    $current = 1;

    if ($total === 0) {
      \Drupal::messenger()->addMessage(t('Nothing to import.'));
      return;
    }

    $context = [
      'skipCorrection' => [],
      'verbose' => $this->verbose,
      'preserveIds' => $this->preserveIds,
      'incremental' => $this->incremental,
      'state_key' => 'dcd.last_import.' . md5($this->getFolder()),
    ];

    $operations[] = [
      [static::class, 'initializeContext'],
      [$context],
    ];

    foreach ($this->dataToImport as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, FALSE],
      ];
    }

    foreach ($this->dataToCorrect as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, TRUE],
      ];
    }

    foreach ($this->pathAliasesToImport as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, FALSE],
      ];
    }

    $batch_definition = [
      'title' => $this->t('Importing Content'),
      'operations' => $operations,
      'finished' => [static::class, 'importFinished'],
      'progressive' => TRUE,
      'queue' => [
        'class' => DefaultContentDeployBatch::class,
        'name' => 'default_content_deploy:import:' . \Drupal::time()->getCurrentMicroTime(),
      ],
    ];

    batch_set($batch_definition);
  }

  public static function initializeContext($vars, &$context): void {
    // Set the start time, so we can access across batch operations.
    if (empty($context['results']['start'])) {
      $context['results']['start'] = microtime(TRUE);
    }
    $context['results']['max_export_timestamp'] = 0;
    $context['results'] = array_merge($context['results'], $vars);
  }

  public static function importFile($file, $current, $total, $correction, &$context): void {
    $importer = \Drupal::service('default_content_deploy.importer');
    $importer->processFile($file, $current, $total, $correction, $context);
  }

  /**
   * Prepare file for import.
   *
   * @param $file
   *   The file object to use for import.
   * @param $current
   *   Indicates progress of the batch operations.
   * @param $total
   *   Total number of batch operations.
   * @param bool $correction
   *   Tbd.
   * @param array &$context
   *   Reference to an array that stores the context of the batch process for status updates.
   *
   * @throws \Exception
   */
  protected function processFile($file, $current, $total, $correction, &$context): void {
    $this->verbose = &$context['results']['verbose'];
    $this->preserveIds = &$context['results']['preserveIds'];
    $this->incremental = &$context['results']['incremental'];

    if ($correction && array_key_exists($file->uuid, $context['results']['skipCorrection'] ?? [])) {
      if ($this->verbose) {
        $context['message'] = $this->t('@current of @total (@time), skipped correction of @entity_type', [
          '@current' => $current,
          '@total' => $total,
          '@time' => $this->getElapsedTime($context['results']['start']),
          '@entity_type' => $file->entity_type_id,
        ]);
      }

      unset($context['results']['skipCorrection'][$file->uuid]);

      return;
    }

    if (PHP_SAPI === 'cli') {
      $root_user = $this->entityTypeManager->getStorage('user')->load(1);
      $this->accountSwitcher->switchTo($root_user);
    }

    try {
      $this->metadataService->reset();
      $is_new = FALSE;
      $this->decodeFile($file);
      $last_import_timestamp = (int) $this->state->get($context['results']['state_key'], 0);
      $export_timestamp = $this->metadataService->getExportTimestamp($file->uuid);

      $entity_type_definition = $this->entityTypeManager->getDefinition($file->entity_type_id);
      $table = $entity_type_definition->getBaseTable();
      $uuid_column = $entity_type_definition->getKey('uuid');

      if (\Drupal::database()->select($table, 'e')
        ->fields('e', [$uuid_column])
        ->condition($uuid_column, $file->uuid)
        ->range(0, 1)
        ->execute()
        ->fetchField()
      ) {
        if ($this->incremental && $export_timestamp && $last_import_timestamp > $export_timestamp) {
          if ($this->verbose) {
            $context['message'] = $this->t('@current of @total (@time), skipped @entity_type @entity_uuid, file is already imported', [
              '@current' => $current,
              '@total' => $total,
              '@time' => $this->getElapsedTime($context['results']['start']),
              '@entity_type' => $file->entity_type_id,
              '@entity_uuid' => $file->uuid,
            ]);
          }
          $context['results']['skipCorrection'][$file->uuid] = TRUE;

          return;
        }

        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        $entity = $this->entityRepository->loadEntityByUuid($file->entity_type_id, $file->uuid);
        // Replace entity ID.
        $file->data[$file->key_id][0]['value'] = $entity->id();

        if (!$file->forceOverride) {
          // Skip if the changed time the same or less in the file.
          if ($entity instanceof EntityChangedInterface) {
            // If an entity was refactored to implement the EntityChangedInterface,
            // older exports don't contain the changed field.
            if (isset($file->data['changed'])) {
              $changed_time_file = 0;
              foreach ($file->data['changed'] as $changed) {
                $changed_time = strtotime($changed['value']);
                if ($changed_time > $changed_time_file) {
                  $changed_time_file = $changed_time;
                }
              }
              $changed_time = $entity->getChangedTimeAcrossTranslations();
              if ($changed_time_file <= $changed_time && !$correction) {
                if ($this->verbose) {
                  $context['message'] = $this->t('@current of @total (@time), skipped @entity_type @entity_id, file (@date_file) is not newer than database (@date_db)', [
                    '@current' => $current,
                    '@total' => $total,
                    '@time' => $this->getElapsedTime($context['results']['start']),
                    '@entity_type' => $entity->getEntityTypeId(),
                    '@entity_id' => $entity->id(),
                    '@date_file' => date('Y-m-d H:i:s', $changed_time_file),
                    '@date_db' => date('Y-m-d H:i:s', $changed_time),
                  ]);
                }
                $context['results']['skipCorrection'][$file->uuid] = TRUE;
                if ($export_timestamp > $context['results']['max_export_timestamp']) {
                  $context['results']['max_export_timestamp'] = $export_timestamp;
                }

                return;
              }
            }
          }
          else {
            $link_domain = $this->getLinkDomain($file);
            $this->linkManager->setLinkDomain($link_domain);
            $this->exporter->setLinkDomain($link_domain);
            $current_entity_decoded = $this->serializer->decode($this->exporter->getSerializedContent($entity, FALSE), 'hal_json');
            $diff = ArrayDiffMultidimensional::looseComparison($file->data, $current_entity_decoded);
            if (!$diff) {
              if ($this->verbose) {
                $context['message'] = $this->t('@current of @total (@time), skipped @entity_type @entity_id, no changes compared to database', [
                  '@current' => $current,
                  '@total' => $total,
                  '@time' => $this->getElapsedTime($context['results']['start']),
                  '@entity_type' => $entity->getEntityTypeId(),
                  '@entity_id' => $entity->id(),
                ]);
              }
              $context['results']['skipCorrection'][$file->uuid] = TRUE;
              if ($export_timestamp > $context['results']['max_export_timestamp']) {
                $context['results']['max_export_timestamp'] = $export_timestamp;
              }

              return;
            }
          }
        }

        $this->linkManager->setLinkDomain(FALSE);
        $this->exporter->setLinkDomain('');
      }
      else {
        $is_new = TRUE;

        if (!$this->preserveIds) {
          // Ignore ID for creating a new entity.
          unset($file->data[$file->key_id]);
        }
        else {
          $entity_storage = \Drupal::entityTypeManager()->getStorage($file->entity_type_id);
          if ($entity_storage->load($file->data[$file->key_id][0]['value'])) {
            $context['message'] = $this->t('@current of @total (@time), skipped @entity_type @entity_id, ID already exists in database', [
              '@current' => $current,
              '@total' => $total,
              '@time' => $this->getElapsedTime($context['results']['start']),
              '@entity_type' => $file->entity_type_id,
              '@entity_id' => $file->data[$file->key_id][0]['value'],
            ]);
            $context['results']['skipCorrection'][$file->uuid] = TRUE;
            if ($export_timestamp > $context['results']['max_export_timestamp']) {
              $context['results']['max_export_timestamp'] = $export_timestamp;
            }

            return;
          }
        }
      }

      if (!$this->metadataService->isCorrectionRequired($file->uuid)) {
        $context['results']['skipCorrection'][$file->uuid] = TRUE;
      }

      // All entities with entity references will be imported two times to ensure
      // that all entity references are present and valid. Path aliases will be
      // imported last to have a chance to rewrite them to the new ids of newly
      // created entities.

      $this->linkManager->setLinkDomain($this->getLinkDomain($file));
      $class = $this->entityTypeManager->getDefinition($file->entity_type_id)
        ->getClass();

      $this->updateTargetRevisionId($file->data);

      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->serializer->denormalize($file->data, $class, 'hal_json', ['request_method' => 'POST']);
      $this->eventDispatcher->dispatch(new PreSaveEntityEvent($entity, $file->data));
      $entity->enforceIsNew($is_new);
      $entity->save();

      if ($entity->getEntityTypeId() === 'user') {
        // Workaround: store the hashed password directly in the database
        // and avoid the entity API which doesn't provide support for
        // setting password hashes directly.
        $hashed_pass = $file->data['pass'][0]['value'] ?? FALSE;
        if ($hashed_pass) {
          $this->database->update('users_field_data')
            ->fields([
              'pass' => $hashed_pass,
            ])
            ->condition('uid', $entity->id(), '=')
            ->execute();
        }
      }

      $this->linkManager->setLinkDomain(FALSE);

      if (PHP_SAPI === 'cli') {
        $this->accountSwitcher->switchBack();
      }

      if ($this->verbose) {
        $context['message'] = $this->t('@current of @total (@time), @operation @entity_type @entity_id', [
          '@current' => $current,
          '@total' => $total,
          '@time' => $this->getElapsedTime($context['results']['start']),
          '@operation' => $is_new ? $this->t('created') : $this->t('updated'),
          '@entity_type' => $entity->getEntityTypeId(),
          '@entity_id' => $entity->id(),
        ]);
      }
      if ($export_timestamp > $context['results']['max_export_timestamp']) {
        $context['results']['max_export_timestamp'] = $export_timestamp;
      }

    }
    catch (\Exception $e) {
      $context['message'] = $this->t('@current of @total (@time), error on importing @entity_type @uuid: @message', [
        '@current' => $current,
        '@total' => $total,
        '@time' => $this->getElapsedTime($context['results']['start']),
        '@entity_type' => $file->entity_type_id,
        '@uuid' => $file->uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Calculates and formats the elapsed time.
   *
   * @param float $start
   *   The start time of the overall batch process.
   *
   * @return string
   *   The formatted elapsed time in minutes.
   */
  public function getElapsedTime($start) {
    $end = microtime(TRUE);
    $diff = $end - $start;
    $elapsed_time = number_format($diff / 60, 2) . ' ' . $this->t('minutes');

    return $elapsed_time;
  }

  /**
   * Gets url from file for set to Link manager.
   *
   * @param $file
   */
  protected function getLinkDomain($file) {
    $link = $file->data['_links']['type']['href'];
    $url_data = parse_url($link);
    $host = "{$url_data['scheme']}://{$url_data['host']}";
    return (!isset($url_data['port'])) ? $host : "{$host}:{$url_data['port']}";
  }

  /**
   * Prepare file to import.
   *
   * @param $file
   *
   * @return $this
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  protected function decodeFile($file): void {
    // Get parsed data.
    $parsed_data = file_get_contents($file->uri);

    // Decode.
    try {
      $decode = $this->serializer->decode($parsed_data, 'hal_json');
    }
    catch (\Exception $e) {
      throw new \RuntimeException(sprintf('Unable to decode %s', $file->uri), $e->getCode(), $e);
    }

    // Prepare data for import.
    $file->data = $decode;
    $this->prepareData($file);
  }

  /**
   * Here we can edit data`s value before importing.
   *
   * @param $file
   */
  protected function prepareData($file): void {
    $entity_type_object = $this->entityTypeManager->getDefinition($file->entity_type_id);
    // Keys of entity.
    $file->key_id = $entity_type_object->getKey('id');

    // @see path_entity_base_field_info().
    // @todo offer an event to let third party modules register their content
    //       types. On the other hand, the path is only part of the export if
    //       computed fields are included, which could be turned off in 2.1.x.
    if (isset($file->data['path']) && in_array($file->entity_type_id, ['taxonomy_term', 'node', 'media', 'commerce_product'])) {
      unset($file->data['path']);
    }

    // Ignore revision and id of entity.
    if ($key_revision_id = $entity_type_object->getKey('revision')) {
      unset($file->data[$key_revision_id]);
    }
  }

  /**
   * Adding prepared data for import.
   *
   * @param $file
   */
  protected function addToImport($file) {
    switch ($file->entity_type_id) {
      case 'path_alias':
        $this->pathAliasesToImport[$file->uuid] = $file;
        break;

      case 'file':
        $this->dataToImport[$file->uuid] = $file;
        break;

      default:
        $this->dataToImport[$file->uuid] = $file;
        $this->dataToCorrect[$file->uuid] = $file;
        break;
    }
  }

  /**
   * Get Entity type ID by link.
   *
   * @param $link
   *
   * @return string|string[]
   */
  private function getEntityTypeByLink($link): string {
    $type = $this->linkManager->getTypeInternalIds($link);

    if ($type) {
      $entity_type_id = $type['entity_type'];
    }
    else {
      $components = array_reverse(explode('/', $link));
      $entity_type_id = $components[1];
      // @todo remove this line when core is >= 9.2
      $this->cache->invalidate('hal:links:types');
    }

    return $entity_type_id;
  }

  /**
   * If this entity contains a reference field with target revision is value,
   * we should to update it.
   *
   * @param $decode
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function updateTargetRevisionId(&$decode): void {
    if (isset($decode['_embedded'])) {
      foreach ($decode['_embedded'] as $link_key => $link) {
        if (array_column($link, 'target_revision_id')) {
          foreach ($link as $ref_key => $reference) {
            $url = $reference['_links']['type']['href'];
            $uuid = $reference['uuid'][0]['value'];
            $entity_type = $this->getEntityTypeByLink($url);
            $entity = $this->entityRepository->loadEntityByUuid($entity_type, $uuid);

            // Update the Target revision id if child entity exist on this site.
            if ($entity) {
              $revision_id = $entity->getRevisionId();
              $decode['_embedded'][$link_key][$ref_key]['target_revision_id'] = $revision_id;
            }
          }
        }
      }
    }
  }

  /**
   * Callback function to handle batch processing completion.
   *
   * @param bool $success
   *   Indicates whether the batch processing was successful.
   */
  public static function importFinished($success, $results, $operations): void {
    if ($success) {
      // Batch processing completed successfully.
      \Drupal::messenger()->addMessage(t('Batch import completed successfully.'));

      if ($results['max_export_timestamp'] > ((int) \Drupal::state()->get($results['state_key'], 0))) {
        \Drupal::state()
          ->set($results['state_key'], $results['max_export_timestamp']);
      }
    }
    else {
      // Batch processing encountered an error.
      \Drupal::messenger()->addMessage(t('An error occurred during the batch export process.'), 'error');
    }
  }

}