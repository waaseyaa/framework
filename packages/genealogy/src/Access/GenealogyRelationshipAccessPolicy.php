<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipTopologyReader;

/**
 * Genealogy graph edges inherit endpoint visibility. Registered from
 * {@see \Waaseyaa\Genealogy\GenealogyServiceProvider::configureHttpKernel()} so the
 * handler can delegate to endpoint entities without discovery-time cycles.
 */
final class GenealogyRelationshipAccessPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly RelationshipTopologyReader $topologyReader = new RelationshipTopologyReader(),
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!$entity instanceof Relationship) {
            return AccessResult::neutral();
        }

        $type = (string) $entity->get('relationship_type');
        if (!GenealogyRelationshipType::isGenealogyEdge($type)) {
            return AccessResult::neutral('Not a genealogy relationship edge.');
        }

        return match ($operation) {
            'view' => $this->viewEdge($entity, $account),
            default => AccessResult::neutral(),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    private function viewEdge(Relationship $edge, AccountInterface $account): AccessResult
    {
        $topology = $this->topologyReader->read($edge);
        $from = $this->loadEndpoint($topology->fromType, $topology->fromId);
        $to = $this->loadEndpoint($topology->toType, $topology->toId);

        if ($from === null || $to === null) {
            return AccessResult::forbidden('Genealogy edge endpoint is missing or unloadable.');
        }

        $fromOk = $this->accessHandler->check($from, 'view', $account)->isAllowed();
        $toOk = $this->accessHandler->check($to, 'view', $account)->isAllowed();

        if ($fromOk && $toOk) {
            return AccessResult::allowed('Both genealogy endpoints are viewable.');
        }

        return AccessResult::forbidden('Genealogy edge is not viewable when an endpoint is hidden.');
    }

    private function loadEndpoint(string $entityTypeId, string $id): ?EntityInterface
    {
        if ($id === '' || !$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return null;
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $loaded = $this->entityTypeManager->getRepository($entityTypeId)->find($id);

        return $loaded instanceof EntityInterface ? $loaded : null;
    }
}
