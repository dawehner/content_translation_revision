<?php

namespace Drupal\Tests\content_translation_revision\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the content_translation_revision UI.
 *
 * @group content_translation_revision
 */
class UiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['node', 'user', 'language', 'system', 'workbench_moderation', 'content_translation'];

  /**
   * @var \Drupal\user\UserInterface
   */
  protected $editorUser;

  public function test() {
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // workbench_moderation
    \Drupal::service('theme_installer')->install(['seven']);
    \Drupal::configFactory()
      ->getEditable('system.theme')
      ->set('admin', 'seven')
      ->save();
    \Drupal::configFactory()
      ->getEditable('node.settings')
      ->set('use_admin_theme', TRUE)
      ->save();

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('kernel')->rebuildContainer();

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'preview_mode' => DRUPAL_DISABLED,
    ]);
    $node_type->setThirdPartySetting('workbench_moderation', 'enabled', TRUE);
    $node_type->setThirdPartySetting('workbench_moderation', 'allowed_moderation_states', ['draft', 'published']);
    $node_type->setThirdPartySetting('workbench_moderation', 'default_moderation_state', 'draft');

    $node_type->save();

    $form_display = entity_get_form_display('node', 'article', 'default');
    $form_display->setComponent('moderation_state', [
      'type' => 'moderation_state_default',
    ]);
    $form_display->save();

    $content_language_settings = ContentLanguageSettings::create([
      'id' => 'node.article',
      'target_entity_type_id' => 'node',
      'target_bundle' => 'article',
    ]);

    $content_language_settings->setThirdPartySetting('content_translation', 'enabled', TRUE);
    $content_language_settings->save();

    $this->editorUser = $this->createUser([
      'administer nodes',
      'create article content',
      'edit any article content',
      'use draft_published transition',
      'use draft_draft transition',
      'translate article node',
    ]);
    $this->drupalLogin($this->editorUser);
  }

  public function testUi() {
    $web_assert = $this->assertSession();

    $this->drupalGet('node/add/article');
    file_put_contents('/tmp/foo.html', $this->getSession()->getPage()->getHtml());
    $this->drupalPostForm('node/add/article', [
      'title[0][value]' => 'en-name--0',
    ], 'Save and Publish');
    $web_assert->statusCodeEquals(200);

    $web_assert->pageTextContains('en-name--0');
    $web_assert->linkExists('Translate revisions');
  }

}