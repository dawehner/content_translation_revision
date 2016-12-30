<?php

namespace Drupal\revision_translation_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

class NodeController extends ControllerBase {

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
    $items = array_map(function (NodeInterface $revision) {

      $item = [];
      $item[] = ['#markup' => \Drupal::service('date.formatter')->format($revision->getRevisionCreationTime())];
      $item[] = ['#markup' => $revision->label()];

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

}
