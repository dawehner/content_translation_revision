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
 * Note: This test is mostly about checking out the API layer.
 *
 * @group content_translation_revision
 */
class CoreApiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'user',
    'workbench_moderation',
    'language',
    'system',
  ];

  /**
   * @FIXME Use TRUE here.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('kernel')->rebuildContainer();

    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $node_type->setThirdPartySetting('workbench_moderation', 'enabled', TRUE);
    $node_type->setThirdPartySetting('workbench_moderation', 'allowed_moderation_states', ['draft', 'published']);
    $node_type->setThirdPartySetting('workbench_moderation', 'default_moderation_state', 'draft');

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
    $this->installConfig('workbench_moderation');
    $this->installConfig('language');
  }

  /**
   * Tests the Drupal core entity API with revisions and translations.
   */
  public function testApi() {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $entity = Node::create([
      'type' => 'article',
      'title' => 'en-name--0',
      'moderation_state' => ['target_id' => 'draft'],
    ]);
    $this->assertCount(0, $entity->validate());
    $entity->save();
    $entity_rev_id = $entity->getRevisionId();

    $entity = clone $entity;
    $entity_fr = $entity->addTranslation('fr');
    $entity_fr->setNewRevision(FALSE);
    $entity_fr->get('title')->value = 'fr-name--0';
    $this->assertCount(0, $entity_fr->validate());
    $entity_fr->save();
    $entity_fr_rev_id = $entity_fr->getRevisionId();

    $entity_rev1 = clone $entity;
    $entity_rev1->setNewRevision(TRUE);
    $entity_rev1->get('title')->value = 'en-name--1';
    $entity_rev1->get('moderation_state')->target_id = 'published';
    $this->assertCount(0, $entity_rev1->validate());
    $entity_rev1->save();
    $entity_rev1_rev_id = $entity_rev1->getRevisionId();

    $entity_fr_rev1 = clone $entity_fr;
    $entity_fr_rev1->setNewRevision(TRUE);
    $entity_fr_rev1->get('title')->value = 'fr-name--1';
    $entity_fr_rev1->get('moderation_state')->target_id = 'published';
    $this->assertCount(0, $entity_fr_rev1->validate());
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

  public function testSyncTranslationRevision() {
    \Drupal::service('module_installer')->install(['content_translation', 'content_translation_revision']);  
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    \Drupal::configFactory()
      ->getEditable('content_translation_revision.settings')
      ->set('sync_moderation_state_translations', TRUE)
      ->save();

    $entity = Node::create([
      'type' => 'article',
      'title' => 'en-name--0',
      'moderation_state' => ['target_id' => 'draft'],
    ]);

    $entity_fr = $entity->addTranslation('fr', ['moderation_state' => ['target_id' => 'draft']]);
    $entity_fr->setNewRevision(FALSE);
    $entity_fr->get('title')->value = 'fr-name--0';
    $entity_fr->save();

    // Publishing the non default translation should also publish the other.
    $entity_fr->set('moderation_state', ['target_id' => 'published']);
    $entity_fr->save();

    $this->assertEquals('published', $entity->get('moderation_state')->target_id);
    $this->assertTrue($entity->isPublished());
    $this->assertEquals(1, \Drupal::database()->query('SELECT status from {node_field_data} where nid = :nid and langcode = :langcode', [':nid' => $entity->id(), ':langcode' => 'en'])->fetchField());
    $this->assertEquals('published', $entity->getTranslation('fr')->get('moderation_state')->target_id);
    $this->assertTrue($entity->getTranslation('fr')->isPublished());
    $this->assertEquals(1, \Drupal::database()->query('SELECT status from {node_field_data} where nid = :nid and langcode = :langcode', [':nid' => $entity->id(), ':langcode' => 'fr'])->fetchField());
  }

}
