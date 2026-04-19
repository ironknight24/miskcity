<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class DeleteAddressController {

  /**
   * Custom access check for deleting an address.
   */
  public function access($nid, AccountInterface $account) {
    $node = Node::load($nid);
    $result = AccessResult::forbidden();

    if ($this->isAddressNode($node) && $this->canDeleteAddress($node, $account)) {
      $result = AccessResult::allowed();
    }

    return $result;
  }

  /**
   * Deletes an address node.
   */
  public function delete($nid, Request $request) {
    unset($request);
    $node = Node::load($nid);

    if (!$node || $node->bundle() !== 'add_address') {
      return new JsonResponse(['status' => 'not found'], 404);
    }

    $node->delete();
    return new JsonResponse(['status' => 'success'], 200);
  }

  protected function isAddressNode(?Node $node): bool {
    return $node !== NULL && $node->bundle() === 'add_address';
  }

  protected function canDeleteAddress(Node $node, AccountInterface $account): bool {
    return $account->hasPermission('delete any add_address content') || (
      $account->hasPermission('delete own add_address content') &&
      $account->id() === $node->getOwnerId()
    );
  }
}
