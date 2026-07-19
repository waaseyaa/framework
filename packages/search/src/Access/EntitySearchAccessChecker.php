<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Access;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Default {@see SearchAccessChecker}: enforces entity `view` access for
 * entity-backed documents and lets non-entity documents through.
 *
 * - **Entity-backed** hits (the `entity_type` names a registered entity type —
 *   e.g. `node`, `user`) are loaded and gated on `view` for the acting account
 *   via {@see EntityAccessHandler}. A hit is dropped unless the policy
 *   explicitly allows it (fail-closed: a missing acting context, an
 *   unparseable id, an entity that no longer loads, or a neutral/forbidden
 *   policy result all deny).
 * - **Non-entity** hits (`entity_type` is empty or not a registered entity
 *   type — e.g. crawled markdown / spec documents indexed as `document`) carry
 *   no entity access policy. They are content the application deliberately
 *   indexed and are returned as-is.
 */
final class EntitySearchAccessChecker implements SearchAccessChecker
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountContextInterface $accountContext,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
    ) {}

    public function canView(string $documentId, string $entityType): bool
    {
        if ($entityType === '' || !$this->entityTypeManager->hasDefinition($entityType)) {
            return true;
        }

        // Entity-backed document: prove `view` for the acting account. With no
        // acting context (CLI/queue/bootstrap) access cannot be proven, so the
        // hit is denied — matching the fail-closed posture of the HTTP layer.
        $account = $this->accountContext->current();
        if ($account === null) {
            return false;
        }
        $principal = $this->fieldReadScope?->current();
        if ($principal !== null && (string) $principal->id() !== (string) $account->id()) {
            return false;
        }
        $authorizationAccount = $principal ?? ($account instanceof \Waaseyaa\Access\AuthorizationPrincipalInterface ? $account : null);
        if ($authorizationAccount === null) {
            return false;
        }

        $id = $this->entityIdFromDocumentId($documentId, $entityType);
        if ($id === '') {
            return false;
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $entity = $this->entityTypeManager->getRepository($entityType)->find($id);
        if ($entity === null) {
            // Indexed entity no longer loads (deleted, or a stale index row).
            return false;
        }

        return $this->accessHandler->check($entity, 'view', $authorizationAccount)->isAllowed();
    }

    /**
     * Document ids are `"type:id"` (e.g. `"node:42"`). Strip the entity-type
     * prefix when present, otherwise take everything after the first colon.
     */
    private function entityIdFromDocumentId(string $documentId, string $entityType): string
    {
        $prefix = $entityType . ':';
        if (str_starts_with($documentId, $prefix)) {
            return substr($documentId, \strlen($prefix));
        }

        $pos = strpos($documentId, ':');

        return $pos === false ? $documentId : substr($documentId, $pos + 1);
    }
}
