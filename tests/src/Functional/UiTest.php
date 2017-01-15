<?php

namespace Drupal\Tests\content_translation_revision\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
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
  public static $modules = [
    'block',
    'node',
    'user',
    'language',
    'system',
    'workbench_moderation',
    'content_translation',
    'content_translation_revision',
  ];

  /**
   * The editor user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editorUser;

  /**
   * The french language object.
   *
   * @var \Drupal\language\Entity\ConfigurableLanguage
   */
  protected $frLanguage;

  /**
   * The english language object.
   *
   * @var \Drupal\language\Entity\ConfigurableLanguage
   */
  protected $enLanguage;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // workbench_moderation.
    \Drupal::service('theme_installer')->install(['seven']);
    \Drupal::configFactory()
      ->getEditable('system.theme')
      ->set('admin', 'seven')
      ->save();
    \Drupal::configFactory()
      ->getEditable('node.settings')
      ->set('use_admin_theme', TRUE)
      ->save();

    $this->frLanguage = ConfigurableLanguage::createFromLangcode('fr');
    $this->frLanguage->save();

    $this->enLanguage = ConfigurableLanguage::load('en');

    $this->container->get('kernel')->rebuildContainer();

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'preview_mode' => DRUPAL_DISABLED,
    ]);
    $node_type->setThirdPartySetting('workbench_moderation', 'enabled', TRUE);
    $node_type->setThirdPartySetting('workbench_moderation', 'allowed_moderation_states', ['draft', 'needs_review', 'published']);
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

    \Drupal::service('content_translation.manager')->setEnabled('node', 'article', TRUE);

    $this->editorUser = $this->createUser([
      'administer nodes',
      'access content',
      'view own unpublished content',
      'create article content',
      'edit any article content',
      'use draft_published transition',
      'use draft_draft transition',
      'use draft_needs_review transition',
      'use needs_review_published transition',
      'use published_draft transition',
      'translate article node',
      'translate any entity',
      'create content translations',
      'update content translations',
    ]);
    $this->drupalLogin($this->editorUser);

    $this->placeBlock('local_tasks_block');
    Cache::invalidateTags(['local_task']);
  }

  /**
   * Tests the UI.
   */
  public function testNoTranslationWorkflow() {
    $web_assert = $this->assertSession();

    $this->drupalGet('node/add/article');
    $this->drupalPostForm('node/add/article', [
      'title[0][value]' => 'en-name--0',
    ], 'Save and Create New Draft');
    $web_assert->statusCodeEquals(200);

    // Draft state.
    $web_assert->pageTextContains('en-name--0');
    $web_assert->linkExists('Edit');
    $web_assert->linkExists('Translate revisions');

    $this->clickLink('Translate revisions');
    $page = $this->getSession()->getPage();

    $revision0_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table[1]');
    $this->assertContains('English (Original language)', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--0', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertEquals('French', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    // Edit the draft and move it to needs review.
    $this->clickLink('Edit');
    $this->drupalPostForm(NULL, [
      'title[0][value]' => 'en-name--1',
    ], 'Save and Request Review');

    $this->clickLink('Translate revisions');
    $page = $this->getSession()->getPage();

    $this->assertCount(4, $page->findAll('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr'));

    $revision1_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[2]');
    $this->assertContains('English (Original language)', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--1', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Needs Review', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    $revision0_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[4]');
    $this->assertContains('English (Original language)', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--0', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Draft', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    // Edit the review and publish it.
    $this->clickLink('Edit');
    $this->drupalPostForm(NULL, [
      'title[0][value]' => 'en-name--2',
    ], 'Save and Publish');

    $this->clickLink('Translate revisions');
    $page = $this->getSession()->getPage();

    $this->assertCount(6, $page->findAll('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr'));

    $revision2_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[2]');
    $this->assertContains('English (Original language)', $revision2_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--2', $revision2_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Published', $revision2_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision2_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision2_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision2_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    $revision1_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[4]');
    $this->assertContains('English (Original language)', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--1', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Needs Review', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    $revision0_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[6]');
    $this->assertContains('English (Original language)', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--0', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Draft', $revision0_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertEquals('n/a', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertEquals('Not translated', $revision0_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());
  }

  /**
   * Tests the UI when translating right after the draft.
   */
  public function testTranslationWorkflowAfterDraft() {
    $web_assert = $this->assertSession();

    $this->drupalGet('node/add/article');
    $this->drupalPostForm('node/add/article', [
      'title[0][value]' => 'en-name--0',
    ], 'Save and Create New Draft');
    $web_assert->statusCodeEquals(200);

    // Draft state.
    $web_assert->pageTextContains('en-name--0');
    $web_assert->linkExists('Edit');
    $web_assert->linkExists('Translate revisions');

    $this->clickLink('Translate revisions');
    $page = $this->getSession()->getPage();

    $revision0_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[2]');
    $revision0_table->clickLink('Add');

    $this->drupalPostForm(NULL, [
      'title[0][value]' => 'fr-name--0',
    ], 'Save and Create New Draft (this translation)');

    $this->clickLink('Translate revisions');
    $page = $this->getSession()->getPage();

    $this->assertCount(4, $page->findAll('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr'));

    $revision1_table = $page->find('xpath', '//div[contains(@class, "region-content")]/table/tbody/tr[2]');
    $this->assertContains('English (Original language)', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[1]')->getHtml());
    $this->assertContains('en-name--0', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml());
    $this->assertContains('Draft', $revision1_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml());
    $this->assertEquals('French', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[1]')->getHtml());
    $this->assertContains('fr-name--0', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml());
    $this->assertContains('Draft', $revision1_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml());

    // Move the en translation from draft to needs review.
    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--1',
    ], 'Save and Request Review (this translation)');

    // Move the en translation from review to published.
    $this->clickLink('Edit');
    $this->drupalPostForm(NULL, [
      'title[0][value]' => 'en-name--2',
    ], 'Save and Publish (this translation)');

    // Move the fr translation from draft to needs review.
    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--1',
    ], 'Save and Request Review (this translation)');

    // Move the en translation from review to published.
    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--2',
    ], 'Save and Publish (this translation)');

    $node = Node::load(1);
    $this->assertEquals('en-name--2', $node->label());
    $this->assertEquals('published', $node->get('moderation_state')->target_id);
    $fr_node = $node->getTranslation('fr');
    $this->assertEquals('fr-name--2', $fr_node->label());
    $this->assertEquals('published', $fr_node->get('moderation_state')->target_id);
  }

  /**
   * Tests the UI when translated after review and then edited later.
   */
  public function testTranslationWorkflowWithEdit() {
    $this->drupalGet('node/add/article');
    $this->drupalPostForm('node/add/article', [
      'title[0][value]' => 'en-name--0',
    ], 'Save and Create New Draft');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--1',
    ], 'Save and Request Review (this translation)');

    $add_translation_url = Url::fromRoute('entity.node.content_translation_revision_add', [
      'node' => 1,
      'node_revision' => 2,
      'source' => 'en',
      'target' => 'fr',
    ], ['language' => $this->frLanguage]);
    $this->drupalPostForm($add_translation_url, [
      'title[0][value]' => 'fr-name--2',
    ], 'Save and Publish (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->enLanguage]), [
      'title[0][value]' => 'en-name--2',
    ], 'Save and Publish (this translation)');

    // We have published items now, so we can continue and create new drafts.
    // At this point the EN and FR entry are separated already.

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--3',
    ], 'Save and Create New Draft');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--4',
    ], 'Save and Request Review (this translation)');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--5',
    ], 'Save and Publish (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--3',
    ], 'Save and Create New Draft (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--4',
    ], 'Save and Request Review (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--5',
    ], 'Save and Publish (this translation)');

    $expected_rows = [];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--4',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--3',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--4',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--3',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--1',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--1',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'n/a',
        'status' => 'Not translated',
        'operation' => 'Add',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--0',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'n/a',
        'status' => 'Not translated',
        'operation' => 'Add',
      ],
    ];

    $this->assertTranslateRevisionOverview($expected_rows);

    $node = Node::load(1);
    $this->assertEquals('en-name--5', $node->label());
    $this->assertEquals('published', $node->get('moderation_state')->target_id);
    $fr_node = $node->getTranslation('fr');
    $this->assertEquals('fr-name--5', $fr_node->label());
    $this->assertEquals('published', $fr_node->get('moderation_state')->target_id);
  }

  /**
   * Tests the translation revision overview screen.
   *
   * @param array $rows
   *   Each indiviual row. Each row contains of an FR and a EN key. Both of them
   *   then contains the title, status and operation column.
   */
  protected function assertTranslateRevisionOverview(array $rows) {
    $this->drupalGet('node/1/revision-translations');

    foreach ($rows as $index => $row) {
      $en_row = $row['en'];
      $fr_row = $row['fr'];

      $page = $this->getSession()->getPage();
      $index_key = 2 * ($index + 1);
      file_put_contents('/tmp/foo.txt', $index_key . "\n\n", FILE_APPEND);
      $revision_table = $page->find('xpath', "//div[contains(@class, 'region-content')]/table/tbody/tr[$index_key]");

      $this->assertContains($en_row['title'], $revision_table->find('xpath', '//table[1]/tbody/tr[1]/td[2]')->getHtml(), "Index $index");
      $this->assertContains($en_row['status'], $revision_table->find('xpath', '//table[1]/tbody/tr[1]/td[3]')->getHtml(), "Index $index");
      $this->assertContains($en_row['operation'], $revision_table->find('xpath', '//table[1]/tbody/tr[1]/td[4]')->getHtml(), "Index $index");

      $this->assertContains($fr_row['title'], $revision_table->find('xpath', '//table[1]/tbody/tr[2]/td[2]')->getHtml(), "Index $index");
      $this->assertContains($fr_row['status'], $revision_table->find('xpath', '//table[1]/tbody/tr[2]/td[3]')->getHtml(), "Index $index");
      $this->assertContains($fr_row['operation'], $revision_table->find('xpath', '//table[1]/tbody/tr[2]/td[4]')->getHtml(), "Index $index");
    }
  }

  /**
   * Tests the UI when translated after review and then edited later with sync enabled.
   */
  public function testTranslationWorkflowWithEditAndSyncEnabled() {
    \Drupal::configFactory()->getEditable('content_translation_revision.settings')
      ->set('sync_moderation_state_translations', TRUE)
      ->save();

    $this->drupalGet('node/add/article');
    $this->drupalPostForm('node/add/article', [
      'title[0][value]' => 'en-name--0',
    ], 'Save and Create New Draft');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--1',
    ], 'Save and Request Review (this translation)');

    $add_translation_url = Url::fromRoute('entity.node.content_translation_revision_add', [
      'node' => 1,
      'node_revision' => 2,
      'source' => 'en',
      'target' => 'fr',
    ], ['language' => $this->frLanguage]);
    $this->drupalPostForm($add_translation_url, [
      'title[0][value]' => 'fr-name--2',
    ], 'Save and Publish (this translation)');

    // We have published items now, so we can continue and create new drafts.
    // At this point the EN and FR entry are separated already.

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--3',
    ], 'Save and Create New Draft');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--4',
    ], 'Save and Request Review (this translation)');

    $this->drupalPostForm('node/1/edit', [
      'title[0][value]' => 'en-name--5',
    ], 'Save and Publish (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--3',
    ], 'Save and Create New Draft (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--4',
    ], 'Save and Request Review (this translation)');

    $this->drupalPostForm(Url::fromRoute('entity.node.edit_form', ['node' => 1], ['language' => $this->frLanguage]), [
      'title[0][value]' => 'fr-name--5',
    ], 'Save and Publish (this translation)');

    $expected_rows = [];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--4',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--3',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--5',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--4',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--3',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--1',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'fr-name--2',
        'status' => 'Published',
        'operation' => 'Edit',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--1',
        'status' => 'Needs Review',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'n/a',
        'status' => 'Not translated',
        'operation' => 'Add',
      ],
    ];
    $expected_rows[] = [
      'en' => [
        'title' => 'en-name--0',
        'status' => 'Draft',
        'operation' => 'Edit',
      ],
      'fr' => [
        'title' => 'n/a',
        'status' => 'Not translated',
        'operation' => 'Add',
      ],
    ];

    $this->assertTranslateRevisionOverview($expected_rows);

    $node = Node::load(1);
    $this->assertEquals('en-name--5', $node->label());
    $this->assertEquals('published', $node->get('moderation_state')->target_id);
    $fr_node = $node->getTranslation('fr');
    $this->assertEquals('fr-name--5', $fr_node->label());
    $this->assertEquals('published', $fr_node->get('moderation_state')->target_id);
  }

}
