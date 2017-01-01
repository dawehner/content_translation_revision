<?php

namespace Drupal\Tests\content_translation_revision\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the core API for revision translations.
 *
 * Note: THis test is mostly about checking out the API layer.
 *
 *
 * @group content_translation_revision
 */
class CoreApiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['node', 'user', 'content_moderation', 'language', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('kernel')->rebuildContainer();

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $node_type->setThirdPartySetting('content_moderation', 'enabled', TRUE);
    $node_type->setThirdPartySetting('content_moderation', 'allowed_moderation_states', ['draft', 'published']);
    $node_type->setThirdPartySetting('content_moderation', 'default_moderation_state', 'draft');

    $node_type->save();

    ContentLanguageSettings::create([
      'id' => 'node.article',
      'target_entity_type_id' => 'node',
      'target_bundle' => 'article',
    ]);

    $this->installSchema('system', 'sequence');
    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig('content_moderation');
  }

  public function testApi() {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $entity = Node::create([
      'type' => 'article',
      'title' => 'en-name--0',
      'moderation_state' => ['target_id' => 'draft'],
    ]);
    $entity->save();
    $entity_rev_id = $entity->getRevisionId();

    $entity = clone $entity;
    $entity_fr = $entity->addTranslation('fr');
    $entity_fr->get('title')->value = 'fr-name--0';
    $entity_fr->save();
    $entity_fr_rev_id = $entity_fr->getRevisionId();

    $entity_rev1 = clone $entity;
    $entity_rev1->setNewRevision(TRUE);
    $entity_rev1->get('title')->value = 'en-name--1';
    $entity_rev1->get('moderation_state')->target_id = 'published';
    $entity_rev1->save();
    $entity_rev1_rev_id = $entity_rev1->getRevisionId();

    $entity_fr_rev1 = clone $entity_fr;
    $entity_fr_rev1->setNewRevision(TRUE);
    $entity_fr_rev1->get('title')->value = 'fr-name--1';
    $entity_fr_rev1->get('moderation_state')->target_id = 'published';
    $entity_fr_rev1->save();
    $entity_fr_rev1_rev_id = $entity_fr_rev1->getRevisionId();

    $this->assertEquals('en-name--0', $storage->loadRevision($entity_rev_id)->label());
    $this->assertEquals('fr-name--0', $storage->loadRevision($entity_fr_rev_id)->getTranslation('fr')->label());
    $this->assertEquals('en-name--1', $storage->loadRevision($entity_rev1_rev_id)->label());
    $this->assertEquals('fr-name--1', $storage->loadRevision($entity_fr_rev1_rev_id)->getTranslation('fr')->label());

    $published_entity = $storage->load($entity->id());
    $this->assertEquals('en-name--1', $published_entity->label());
    $this->assertEquals('fr-name--1', $published_entity->getTranslation('fr')->label());
  }

}
