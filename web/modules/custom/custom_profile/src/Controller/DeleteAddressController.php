<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles DELETE requests for address nodes.
 *
 * Exposes a single DELETE endpoint at /delete-address/{nid} that removes an
 * add_address content node. A companion access callback enforces ownership:
 * only users who own the node (or hold the "delete any" permission) may
 * trigger the deletion.
 */
class DeleteAddressController {

  /**
   * Custom access callback for the delete-address route.
   *
   * Loads the node by its numeric ID and grants access only when both
   * conditions are met: the node is an add_address bundle and the current
   * account has the appropriate delete permission.
   *
   * @param int $nid
   *   The numeric node ID passed in the URL.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently authenticated user.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   AccessResult::allowed() when the user may delete the node;
   *   AccessResult::forbidden() otherwise.
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
   * Deletes an address node and returns a JSON status response.
   *
   * The $request parameter is accepted to satisfy the route contract but is
   * not needed for deletion logic; it is immediately discarded.
   *
   * @param int $nid
   *   The numeric node ID of the address to delete.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request (unused).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with status "success" (200) or "not found" (404).
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

  /**
   * Checks whether a node exists and belongs to the add_address bundle.
   *
   * @param \Drupal\node\Entity\Node|null $node
   *   The node to inspect, or NULL if loading failed.
   *
   * @return bool
   *   TRUE only when a non-null add_address node is provided.
   */
  protected function isAddressNode(?Node $node): bool {
    return $node !== NULL && $node->bundle() === 'add_address';
  }

  /**
   * Determines whether an account may delete a given address node.
   *
   * Grants access to accounts with the "delete any add_address content"
   * permission, or accounts with "delete own add_address content" that also
   * own the node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The address node targeted for deletion.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting the deletion.
   *
   * @return bool
   *   TRUE when the account is permitted to delete the node.
   */
  protected function canDeleteAddress(Node $node, AccountInterface $account): bool {
    return $account->hasPermission('delete any add_address content') || (
      $account->hasPermission('delete own add_address content') &&
      $account->id() === $node->getOwnerId()
    );
  }

}
