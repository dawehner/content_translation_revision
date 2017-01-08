<?php

namespace Drupal\content_translation_revision\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the content translation revision UI.
 */
class ContentTranslationRevisionController extends ControllerBase {

  /**
   * The content translation manager.
   *
   * @var \Drupal\content_translation\ContentTranslationManagerInterface
   */
  protected $manager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Initializes a content translation controller.
   *
   * @param \Drupal\content_translation\ContentTranslationManagerInterface $manager
   *   A content translation manager instance.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(ContentTranslationManagerInterface $manager, DateFormatterInterface $date_formatter, RendererInterface $renderer) {
    $this->manager = $manager;
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_translation.manager'),
      $container->get('date.formatter'),
      $container->get('renderer')
    );
  }

  /**
   * Provides a list of revisions and translations.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   The render array.
   */
  public function revisionOverview(RouteMatchInterface $route_match, $entity_type_id = NULL) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionLogInterface $entity */
    $entity = $route_match->getParameter($entity_type_id);

    $header = [
      $this->t('Version'),
      $this->t('Operations'),
    ];

    $entity_storage = $this->entityTypeManager()
      ->getStorage($entity->getEntityTypeId());
    $revision_entities = $this->loadRevisions($entity, $entity_storage);

    $revision_rows = array_map(function (ContentEntityInterface $revision) use ($entity) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionLogInterface $revision */

      // @codingStandardsIgnoreStart
      // $translation = $revision->getTranslation($revision->language()->getId());
      //      $metadata = $this->manager->getTranslationMetadata($translation);
      //      $status = [
      //        'data' => [
      //        '#type' => 'inline_template',
      //        '#template' => '<span class="status">{% if status %}{{ "Published"|t }}{% else %}{{ "Not published"|t }}{% endif %}</span>{% if outdated %} <span class="marker">{{ "outdated"|t }}</span>{% endif %}',
      //        '#context' => [
      //          'status' => $metadata->isPublished(),
      //          'outdated' => $metadata->isOutdated(),
      //        ],
      //        ]
      //      ];
      // @codingStandardsIgnoreEnd
      $username = [
        '#theme' => 'username',
        '#account' => $revision->getRevisionUser(),
      ];
      $date = $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short');
      if ($entity->getRevisionId() != $revision->getRevisionId()) {
        $link = Link::fromTextAndUrl($date, $revision->toUrl('revision'));
      }
      else {
        $link = $entity->toLink($date);
      }

      /** @var \Drupal\Core\Render\RendererInterface $renderer */
      $renderer = $this->renderer;
      $revision_message = [
        '#markup' => $revision->getRevisionLogMessage(),
        '#allowed_tags' => Xss::getHtmlTagList(),
      ];
      $link_renderable = $link->toRenderable();
      $column = [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
          '#context' => [
            'date' => $renderer->renderPlain($link_renderable),
            'username' => $renderer->renderPlain($username),
            'message' => $renderer->renderPlain($revision_message),
          ],
        ],
      ];

      $operations = $this->revisionOperations($revision, $entity);
      return [
        $column,
        ['data' => $operations],
      ];
    }, $revision_entities);

    $translation_tables = array_map([
      $this,
      'singleTranslationTable',
    ], $revision_entities);

    $rows = [];
    foreach (array_keys($revision_rows) as $revision_id) {
      $rows[] = $revision_rows[$revision_id];
      // @todo Add colspan properly.
      $rows[] = [
        ['colspan' => 2, 'data' => $translation_tables[$revision_id]],
      ];
    }

    $build['content_translation_revision_overview'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];

    return $build;
  }

  /**
   * Returns available operations for a given revision.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $revision
   *   The revision.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The default version of the entity.
   *
   * @return array
   *   The possible operations.
   */
  protected function revisionOperations(ContentEntityInterface $revision, ContentEntityInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();
    $account = $this->currentUser();
    $bundle = $revision->bundle();
    $vid = $revision->getRevisionId();
    $langcode = $entity->language()->getId();
    $languages = $entity->getTranslationLanguages();
    $has_translations = (count($languages) > 1);

    $revert_permission = (($account->hasPermission("revert $bundle revisions") || $account->hasPermission('revert all revisions') || $account->hasPermission('administer nodes')) && $entity->access('update'));
    $delete_permission = (($account->hasPermission("delete $bundle revisions") || $account->hasPermission('delete all revisions') || $account->hasPermission('administer nodes')) && $entity->access('delete'));

    $links = [];
    if ($revert_permission) {
      $url = $has_translations ?
        Url::fromRoute("$entity_type_id.revision_revert_translation_confirm", [
          $entity_type_id => $entity->id(),
          $entity_type_id . '_revision' => $vid,
          'langcode' => $langcode,
        ]) :
        Url::fromRoute("$entity_type_id.revision_revert_confirm", [
          $entity_type_id => $entity->id(),
          $entity_type_id . '_revision' => $vid,
        ]);
      $links['revert'] = [
        'title' => $vid != $entity->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
        'url' => $url,
      ];
    }

    if ($delete_permission) {
      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('node.revision_delete_confirm', [
          $entity_type_id => $entity->id(),
          $entity_type_id . '_revision' => $vid,
        ]),
      ];
    }

    return [
      '#type' => 'operations',
      '#links' => $links,
    ];
  }

  /**
   * Returns a table with translations for a single revision.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $revision
   *   The revision.
   *
   * @return array
   *   The render array.
   */
  protected function singleTranslationTable(ContentEntityInterface $revision) {
    $entity_type = $revision->getEntityType();
    $entity_type_id = $revision->getEntityTypeId();
    $entity = $this->entityTypeManager()
      ->getStorage($entity_type_id)
      ->load($revision->id());

    $original = $revision->language()->getId();
    $translations = $revision->getTranslationLanguages();
    $handler = $this->entityTypeManager()
      ->getHandler($entity_type_id, 'translation');
    $manager = $this->manager;
    $languages = $this->languageManager()->getLanguages();

    // Start collecting the cacheability metadata, starting with the entity and
    // later merge in the access result cacheability metadata.
    $cacheability = CacheableMetadata::createFromObject($entity);

    // Show source-language column if there are non-original source langcodes.
    $additional_source_langcodes = array_filter(array_keys($translations), function ($langcode) use ($entity, $original, $manager) {
      $source = $manager->getTranslationMetadata($entity->getTranslation($langcode))
        ->getSource();
      return $source != $original && $source != LanguageInterface::LANGCODE_NOT_SPECIFIED;
    });
    $show_source_column = !empty($additional_source_langcodes);

    // Determine whether the current entity is translatable.
    $translatable = FALSE;
    foreach ($this->entityManager()
               ->getFieldDefinitions($entity_type_id, $entity->bundle()) as $instance) {
      if ($instance->isTranslatable()) {
        $translatable = TRUE;
        break;
      }
    }

    $translation_rows = array_map(function (LanguageInterface $language) use ($revision, $entity_type, $entity_type_id, $original, $entity, $translations, $handler, $manager, $translatable, &$cacheability, $show_source_column, $languages) {
      $cacheability = CacheableMetadata::createFromObject($entity);

      $language_name = $language->getName();
      $langcode = $language->getId();

      $add_url = new Url(
        "entity.$entity_type_id.content_translation_revision_add",
        [
          'source' => $original,
          'target' => $language->getId(),
          $entity_type_id => $revision->id(),
          $entity_type_id . '_revision' => $revision->getRevisionId(),
        ],
        [
          'language' => $language,
        ]
      );
      $edit_url = new Url(
        "entity.$entity_type_id.content_translation_revision_edit",
        [
          'language' => $language->getId(),
          $entity_type_id => $revision->id(),
          $entity_type_id . '_revision' => $revision->getRevisionId(),
        ],
        [
          'language' => $language,
        ]
      );
      // @codingStandardsIgnoreStart
      // $delete_url = new Url(
      //          "entity.$entity_type_id.content_translation_delete",
      //          [
      //            'language' => $language->getId(),
      //            $entity_type_id => $entity->id(),
      //          ],
      //          [
      //            'language' => $language,
      //          ]
      //        );.
      // @codingStandardsIgnoreEnd
      $operations = [
        'data' => [
          '#type' => 'operations',
          '#links' => [],
        ],
      ];

      $links = &$operations['data']['#links'];
      if (array_key_exists($langcode, $translations)) {
        // Existing translation in the translation set: display status.
        $translation = $revision->getTranslation($langcode);
        $metadata = $manager->getTranslationMetadata($translation);
        $source = $metadata->getSource() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
        $is_original = $langcode == $original;
        $label = $revision->getTranslation($langcode)->label();
        $link = isset($links->links[$langcode]['url']) ? $links->links[$langcode] : ['url' => $revision->toUrl()];
        if (!empty($link['url'])) {
          $link['url']->setOption('language', $language);
          $row_title = $this->l($label, $link['url']);
        }

        if (empty($link['url'])) {
          $row_title = $is_original ? $label : $this->t('n/a');
        }

        // If the user is allowed to edit the entity we point the edit link to
        // the entity form, otherwise if we are not dealing with the original
        // language we point the link to the translation form.
        $update_access = $revision->access('update', NULL, TRUE);
        $translation_access = $handler->getTranslationAccess($revision, 'update');
        $cacheability = $cacheability
          ->merge(CacheableMetadata::createFromObject($update_access))
          ->merge(CacheableMetadata::createFromObject($translation_access));
        if ($update_access->isAllowed() && $entity_type->hasLinkTemplate('edit-form')) {
          $links['edit']['url'] = $revision->urlInfo('edit-form');
          $links['edit']['language'] = $language;
        }
        elseif (!$is_original && $translation_access->isAllowed()) {
          $links['edit']['url'] = $edit_url;
        }

        if (isset($links['edit'])) {
          $links['edit']['title'] = $this->t('Edit');
        }
        $status = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span class="status">{% if status %}{{ "Published"|t }}{% else %}{{ "Not published"|t }}{% endif %}</span>{% if outdated %} <span class="marker">{{ "outdated"|t }}</span>{% endif %}',
            '#context' => [
              'status' => $metadata->isPublished(),
              'outdated' => $metadata->isOutdated(),
            ],
          ],
        ];

        if ($is_original) {
          $language_name = $this->t('<strong>@language_name (Original language)</strong>', ['@language_name' => $language_name]);
          $source_name = $this->t('n/a');
        }
        else {
          $source_name = isset($languages[$source]) ? $languages[$source]->getName() : $this->t('n/a');
          $delete_access = $entity->access('delete', NULL, TRUE);
          $translation_access = $handler->getTranslationAccess($entity, 'delete');
          $cacheability = $cacheability
            ->merge(CacheableMetadata::createFromObject($delete_access))
            ->merge(CacheableMetadata::createFromObject($translation_access));
          // @codingStandardsIgnoreStart
          // If ($entity->access('delete') && $entity_type->hasLinkTemplate('delete-form')) {
          //            $links['delete'] = [
          //              'title' => $this->t('Delete'),
          //              'url' => $entity->urlInfo('delete-form'),
          //              'language' => $language,
          //            ];
          //          }
          //          elseif ($translation_access->isAllowed()) {
          //            $links['delete'] = [
          //              'title' => $this->t('Delete'),
          //              'url' => $delete_url,
          //            ];
          //          }.
          // @codingStandardsIgnoreEnd
        }
      }
      else {
        // No such translation in the set yet: help user to create it.
        $row_title = $source_name = $this->t('n/a');
        $source = $revision->language()->getId();

        $create_translation_access = $handler->getTranslationAccess($revision, 'create');
        $cacheability = $cacheability
          ->merge(CacheableMetadata::createFromObject($create_translation_access));
        if ($source != $langcode && $create_translation_access->isAllowed()) {
          if ($translatable) {
            $links['add'] = [
              'title' => $this->t('Add'),
              'url' => $add_url,
            ];
          }
        }

        $status = ['#markup' => $this->t('Not translated')];
      }

      $single_translation_row = [];
      $single_translation_row[] = [
        'data' => [
          '#markup' => $language_name,
        ],
      ];

      if ($show_source_column) {
        $single_translation_row = ['data' => ['#markup' => $source_name]];
      }

      $single_translation_row[] = ['data' => ['#markup' => $row_title]];
      $single_translation_row[] = ['data' => $status];
      $single_translation_row[] = ['data' => $operations];

      return $single_translation_row;
    }, $languages);

    if ($show_source_column) {
      $header = [
        $this->t('Language'),
        $this->t('Translation'),
        $this->t('Source language'),
        $this->t('Status'),
        $this->t('Operations'),
      ];
    }
    else {
      $header = [
        $this->t('Language'),
        $this->t('Translation'),
        $this->t('Status'),
        $this->t('Operations'),
      ];
    }

    // Add metadata to the build render array to let other modules know about
    // which entity this is.
    $build['#entity'] = $entity;
    $cacheability
      ->addCacheTags($entity->getCacheTags())
      ->applyTo($build);

    $build['content_translation_overview'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $translation_rows,
    ];

    return $build;
  }

  /**
   * Builds an add translation page.
   *
   * @param \Drupal\Core\Language\LanguageInterface $source
   *   The language of the values being translated. Defaults to the entity
   *   language.
   * @param \Drupal\Core\Language\LanguageInterface $target
   *   The language of the translated values. Defaults to the current content
   *   language.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match object from which to extract the entity type.
   * @param string $entity_type_id
   *   (optional) The entity type ID.
   *
   * @return array
   *   A processed form array ready to be rendered.
   */
  public function add(LanguageInterface $source, LanguageInterface $target, RouteMatchInterface $route_match, $entity_type_id = NULL) {
    $entity = $route_match->getParameter($entity_type_id . '_revision');

    // @todo Exploit the upcoming hook_entity_prepare() when available.
    // See https://www.drupal.org/node/1810394.
    $this->prepareTranslation($entity, $source, $target);

    // @todo Provide a way to figure out the default form operation. Maybe like
    // @codingStandardsIgnoreStart
    //   $operation = isset($info['default_operation']) ? $info['default_operation'] : 'default';
    //   See https://www.drupal.org/node/2006348.
    // @codingStandardsIgnoreEnd
    $operation = 'default';

    $form_state_additions = [];
    $form_state_additions['langcode'] = $target->getId();
    $form_state_additions['content_translation']['source'] = $source;
    $form_state_additions['content_translation']['target'] = $target;
    $form_state_additions['content_translation']['translation_form'] = !$entity->access('update');

    return $this->entityFormBuilder()
      ->getForm($entity, $operation, $form_state_additions);
  }

  /**
   * Populates target values with the source values.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being translated.
   * @param \Drupal\Core\Language\LanguageInterface $source
   *   The language to be used as source.
   * @param \Drupal\Core\Language\LanguageInterface $target
   *   The language to be used as target.
   */
  public function prepareTranslation(ContentEntityInterface $entity, LanguageInterface $source, LanguageInterface $target) {
    /* @var \Drupal\Core\Entity\ContentEntityInterface $source_translation */
    $source_translation = $entity->getTranslation($source->getId());
    $target_translation = $entity->addTranslation($target->getId(), $source_translation->toArray());

    // Ensure to NOT create a new revision, we really just translate.
    $entity->setNewRevision(FALSE);
    $entity->isDefaultRevision(FALSE);

    // Make sure we do not inherit the affected status from the source values.
    if ($entity->getEntityType()->isRevisionable()) {
      $target_translation->setRevisionTranslationAffected(NULL);
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager()
      ->getStorage('user')
      ->load($this->currentUser()->id());
    $metadata = $this->manager->getTranslationMetadata($target_translation);

    // Update the translation author to current user, as well the translation
    // creation time.
    $metadata->setAuthor($user);
    $metadata->setCreatedTime(REQUEST_TIME);
  }

  /**
   * Builds the edit translation page.
   *
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language of the translated values. Defaults to the current content
   *   language.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match object from which to extract the entity type.
   * @param string $entity_type_id
   *   (optional) The entity type ID.
   *
   * @return array
   *   A processed form array ready to be rendered.
   */
  public function edit(LanguageInterface $language, RouteMatchInterface $route_match, $entity_type_id = NULL) {
    $entity = $route_match->getParameter($entity_type_id . '_revision');

    // @codingStandardsIgnoreStart
    // @todo Provide a way to figure out the default form operation. Maybe like
    //   $operation = isset($info['default_operation']) ? $info['default_operation'] : 'default';
    //   See https://www.drupal.org/node/2006348.
    // @codingStandardsIgnoreEnd
    $operation = 'default';

    $form_state_additions = [];
    $form_state_additions['langcode'] = $language->getId();
    $form_state_additions['content_translation']['translation_form'] = TRUE;

    return $this->entityFormBuilder()
      ->getForm($entity, $operation, $form_state_additions);
  }

  /**
   * Loads all revisions for a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Entity\EntityStorageInterface $entity_storage
   *   The entity storage.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Returns all revisions.
   */
  protected function loadRevisions(ContentEntityInterface $entity, EntityStorageInterface $entity_storage) {
    $revision_ids = array_keys($entity_storage->getQuery()
      ->allRevisions()
      // @FIXME There seems to be a lot of issues with access checking on
      // revision queries.
      ->accessCheck(FALSE)
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->sort($entity->getEntityType()->getKey('revision'), 'DESC')
      ->execute());
    $revision_entities = array_map(function ($revision_id) use ($entity_storage) {
      return $entity_storage->loadRevision($revision_id);
    }, $revision_ids);
    return array_combine($revision_ids, $revision_entities);
  }

}
