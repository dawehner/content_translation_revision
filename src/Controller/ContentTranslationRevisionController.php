<?php

namespace Drupal\content_translation_revision\Controller;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentTranslationRevisionController extends ControllerBase {

  /**
   * The content translation manager.
   *
   * @var \Drupal\content_translation\ContentTranslationManagerInterface
   */
  protected $manager;

  /**
   * Initializes a content translation controller.
   *
   * @param \Drupal\content_translation\ContentTranslationManagerInterface $manager
   *   A content translation manager instance.
   */
  public function __construct(ContentTranslationManagerInterface $manager) {
    $this->manager = $manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('content_translation.manager'));
  }

  public function revisionOverview(NodeInterface $node) {
    // Get all revisions for this node.
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $revision_ids = $node_storage->getQuery()
      ->allRevisions()
      // @fixme There seems to be a lot of issues with access checking on
      //   revision queries.
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->sort('revision_timestamp', 'DESC')
      ->execute();
    $revision_entities = array_map(function ($revision_id) use ($node_storage) {
      return $node_storage->loadRevision($revision_id);
    }, array_keys($revision_ids));

    $languages = $this->languageManager()->getLanguages();
    $items = array_map(function (NodeInterface $revision) use ($languages) {
      $item = [];
      $item[] = ['#markup' => \Drupal::service('date.formatter')->format($revision->getRevisionCreationTime())];
      $item[] = ['#markup' => $revision->label()];

      $entity_type = $revision->getEntityType();
      $entity_type_id = $revision->getEntityTypeId();
      $original = $revision->language()->getId();
      $entity = $revision;
      $translations = $entity->getTranslationLanguages();
      $handler = $this->entityTypeManager()->getHandler($entity_type_id, 'translation');
      $manager = $this->manager;

      // Determine whether the current entity is translatable.
      $translatable = FALSE;
      foreach ($this->entityManager()->getFieldDefinitions($entity_type_id, $entity->bundle()) as $instance) {
        if ($instance->isTranslatable()) {
          $translatable = TRUE;
          break;
        }
      }

      $item[] = array_map(function (LanguageInterface $language) use ($revision, $entity_type, $entity_type_id, $original, $entity, $translations, $handler, $manager, $translatable) {
        $cacheability = CacheableMetadata::createFromObject($entity);
        $result = [
          [
            '#markup' => $language->getName(),
          ],
        ];

        $language_name = $language->getName();
        $langcode = $language->getId();

        $add_url = new Url(
          "entity.$entity_type_id.content_translation_revision_add",
          array(
            'source' => $original,
            'target' => $language->getId(),
            $entity_type_id => $entity->id(),
            $entity_type_id . '_revision' => $entity->getRevisionId(),
          ),
          array(
            'language' => $language,
          )
        );
        $edit_url = new Url(
          "entity.$entity_type_id.content_translation_revision_edit",
          array(
            'language' => $language->getId(),
            $entity_type_id => $entity->id(),
            $entity_type_id . '_revision' => $entity->getRevisionId(),
          ),
          array(
            'language' => $language,
          )
        );
//        $delete_url = new Url(
//          "entity.$entity_type_id.content_translation_delete",
//          array(
//            'language' => $language->getId(),
//            $entity_type_id => $entity->id(),
//          ),
//          array(
//            'language' => $language,
//          )
//        );
        $operations = array(
          'data' => array(
            '#type' => 'operations',
            '#links' => array(),
          ),
        );

        $links = &$operations['data']['#links'];
        if (array_key_exists($langcode, $translations)) {
          // Existing translation in the translation set: display status.
          $translation = $entity->getTranslation($langcode);
          $metadata = $manager->getTranslationMetadata($translation);
          $source = $metadata->getSource() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
          $is_original = $langcode == $original;
          $label = $entity->getTranslation($langcode)->label();
          $link = isset($links->links[$langcode]['url']) ? $links->links[$langcode] : array('url' => $entity->urlInfo());
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
          $update_access = $entity->access('update', NULL, TRUE);
          $translation_access = $handler->getTranslationAccess($entity, 'update');
          $cacheability = $cacheability
            ->merge(CacheableMetadata::createFromObject($update_access))
            ->merge(CacheableMetadata::createFromObject($translation_access));
          if ($update_access->isAllowed() && $entity_type->hasLinkTemplate('edit-form')) {
            $links['edit']['url'] = $entity->urlInfo('edit-form');
            $links['edit']['language'] = $language;
          }
          elseif (!$is_original && $translation_access->isAllowed()) {
            $links['edit']['url'] = $edit_url;
          }

          if (isset($links['edit'])) {
            $links['edit']['title'] = $this->t('Edit');
          }
          $status = array('data' => array(
            '#type' => 'inline_template',
            '#template' => '<span class="status">{% if status %}{{ "Published"|t }}{% else %}{{ "Not published"|t }}{% endif %}</span>{% if outdated %} <span class="marker">{{ "outdated"|t }}</span>{% endif %}',
            '#context' => array(
              'status' => $metadata->isPublished(),
              'outdated' => $metadata->isOutdated(),
            ),
          ));

//          if ($is_original) {
//            $language_name = $this->t('<strong>@language_name (Original language)</strong>', array('@language_name' => $language_name));
//            $source_name = $this->t('n/a');
//          }
//          else {
//            $source_name = isset($languages[$source]) ? $languages[$source]->getName() : $this->t('n/a');
//            $delete_access = $entity->access('delete', NULL, TRUE);
//            $translation_access = $handler->getTranslationAccess($entity, 'delete');
//            $cacheability = $cacheability
//              ->merge(CacheableMetadata::createFromObject($delete_access))
//              ->merge(CacheableMetadata::createFromObject($translation_access));
//            if ($entity->access('delete') && $entity_type->hasLinkTemplate('delete-form')) {
//              $links['delete'] = array(
//                'title' => $this->t('Delete'),
//                'url' => $entity->urlInfo('delete-form'),
//                'language' => $language,
//              );
//            }
//            elseif ($translation_access->isAllowed()) {
//              $links['delete'] = array(
//                'title' => $this->t('Delete'),
//                'url' => $delete_url,
//              );
//            }
//          }
        }
        else {
          // No such translation in the set yet: help user to create it.
          $row_title = $source_name = $this->t('n/a');
          $source = $entity->language()->getId();

          $create_translation_access = $handler->getTranslationAccess($entity, 'create');
          $cacheability = $cacheability
            ->merge(CacheableMetadata::createFromObject($create_translation_access));
          if ($source != $langcode && $create_translation_access->isAllowed()) {
            if ($translatable) {
              $links['add'] = array(
                'title' => $this->t('Add'),
                'url' => $add_url,
              );
            }
          }

          $status = $this->t('Not translated');
        }
//        if ($show_source_column) {
//          $rows[] = array(
//            $language_name,
//            $row_title,
//            $source_name,
//            $status,
//            $operations,
//          );
//        }
//        else {
//          $rows[] = array($language_name, $row_title, $status, $operations);
//        }
        $result[] = ['#markup' => $row_title];
        $result[] = $operations;

        return $result;
      }, $languages);

      return [
        'value' => $item,
      ];
    }, $revision_entities);

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Revision translations'),
      '#items' => $items,
    ];
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
    //   $operation = isset($info['default_operation']) ? $info['default_operation'] : 'default';
    //   See https://www.drupal.org/node/2006348.
    $operation = 'default';

    $form_state_additions = [];
    $form_state_additions['langcode'] = $target->getId();
    $form_state_additions['content_translation']['source'] = $source;
    $form_state_additions['content_translation']['target'] = $target;
    $form_state_additions['content_translation']['translation_form'] = !$entity->access('update');

    return $this->entityFormBuilder()->getForm($entity, $operation, $form_state_additions);
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

    // Make sure we do not inherit the affected status from the source values.
    if ($entity->getEntityType()->isRevisionable()) {
      $target_translation->setRevisionTranslationAffected(NULL);
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
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

    // @todo Provide a way to figure out the default form operation. Maybe like
    //   $operation = isset($info['default_operation']) ? $info['default_operation'] : 'default';
    //   See https://www.drupal.org/node/2006348.
    $operation = 'default';

    $form_state_additions = [];
    $form_state_additions['langcode'] = $language->getId();
    $form_state_additions['content_translation']['translation_form'] = TRUE;

    return $this->entityFormBuilder()->getForm($entity, $operation, $form_state_additions);
  }

}
