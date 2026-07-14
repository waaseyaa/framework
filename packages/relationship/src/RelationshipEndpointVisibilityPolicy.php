<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Redacts a relationship edge's endpoint-identity fields when the viewing
 * account may not view the referenced endpoint entity.
 *
 * {@see RelationshipAccessPolicy} gates entity-level `view` on the edge's own
 * `status`/permission — it never checks whether the account may see the
 * ENDPOINT entities the edge names via `from_entity_type`/`from_entity_id`/
 * `to_entity_type`/`to_entity_id`. Without this policy, a viewable-but-
 * unrelated edge discloses the identity of hidden/unpublished/access-
 * restricted endpoints to any baseline caller — including anonymous on a
 * public install — through every read path that applies field access
 * (JSON:API collection + single, `entity.read`, GraphQL).
 *
 * At field level it redacts the (type, id) PAIR for whichever endpoint the
 * account cannot view. At entity level it forbids an edge when neither endpoint
 * is viewable, so the edge id and relationship type cannot become existence
 * metadata about two otherwise-hidden entities. An edge with one visible
 * endpoint remains viewable with the other endpoint pair redacted.
 *
 * Discovered via `#[PolicyAttribute]` on the SHARED boot path
 * ({@see \Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry::discover()},
 * `AbstractKernel::discoverAccessPolicies()`), so it is registered on BOTH the
 * HttpKernel AND the ConsoleKernel — the latter matters because `entity.read`
 * has real ConsoleKernel production callers (`ai:run --inline`, `queue:work`
 * driving `RunAgentHandler`). The two-phase registry resolves the
 * discovery-time cycle: a policy whose constructor takes
 * {@see EntityAccessHandler} is DEFERRED to phase 2 and instantiated with the
 * phase-1 preliminary handler injected — so this policy can delegate to
 * endpoint entities' own policies without a construction cycle. An earlier
 * cut registered this only via `RelationshipServiceProvider::configureHttpKernel()`,
 * which `ConsoleKernel` never invokes — that left `entity.read` leaking in
 * CLI/queue contexts, which is why the attribute-discovery path is used here
 * (mirrors {@see \Waaseyaa\Engagement\EngagementAccessPolicy}'s wiring).
 *
 * Fail-closed: an endpoint with an empty id/type, an unregistered entity
 * type, or that fails to load is treated as NOT viewable, so an edge can
 * never accidentally disclose an endpoint it cannot prove is safe to show.
 */
#[PolicyAttribute(entityType: 'relationship')]
final class RelationshipEndpointVisibilityPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    private const array TO_FIELDS = ['to_entity_type', 'to_entity_id'];
    private const array FROM_FIELDS = ['from_entity_type', 'from_entity_id'];

    /**
     * Deliberately NOT memoized across calls: this policy is constructed once
     * at kernel boot and registered on the long-lived {@see EntityAccessHandler}.
     * Under a long-running runtime (e.g. FrankenPHP worker mode, see
     * packages/frankenphp), an instance-level cache would outlive a single
     * request and could serve a stale "viewable" verdict after an endpoint's
     * own access changes (e.g. published -> unpublished) — a self-inflicted
     * disclosure bug worse than the two extra endpoint loads this would save.
     * `fieldAccess()` is invoked once per endpoint field (two calls per
     * endpoint per entity), so each field-access check re-derives its answer.
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view' && $entity instanceof Relationship) {
            $fromViewable = $this->isEndpointViewable(
                (string) ($entity->get('from_entity_type') ?? ''),
                (string) ($entity->get('from_entity_id') ?? ''),
                $account,
            );
            $toViewable = $this->isEndpointViewable(
                (string) ($entity->get('to_entity_type') ?? ''),
                (string) ($entity->get('to_entity_id') ?? ''),
                $account,
            );

            if (!$fromViewable && !$toViewable) {
                return AccessResult::forbidden('Relationship is hidden because neither endpoint is viewable.');
            }
        }

        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($operation !== 'view' || !$entity instanceof Relationship) {
            return AccessResult::neutral();
        }

        if (in_array($fieldName, self::TO_FIELDS, true)) {
            return $this->redactIfEndpointHidden($entity, 'to_entity_type', 'to_entity_id', $account);
        }

        if (in_array($fieldName, self::FROM_FIELDS, true)) {
            return $this->redactIfEndpointHidden($entity, 'from_entity_type', 'from_entity_id', $account);
        }

        return AccessResult::neutral();
    }

    private function redactIfEndpointHidden(
        Relationship $edge,
        string $typeField,
        string $idField,
        AccountInterface $account,
    ): AccessResult {
        $endpointType = (string) ($edge->get($typeField) ?? '');
        $endpointId = (string) ($edge->get($idField) ?? '');

        return $this->isEndpointViewable($endpointType, $endpointId, $account)
            ? AccessResult::neutral('Endpoint entity is viewable.')
            : AccessResult::forbidden('Endpoint entity is hidden from this account.');
    }

    private function isEndpointViewable(string $entityTypeId, string $id, AccountInterface $account): bool
    {
        if ($entityTypeId === '' || $id === '' || !$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return false;
        }

        $endpoint = $this->entityTypeManager->getRepository($entityTypeId)->find($id);
        if ($endpoint === null) {
            return false;
        }

        return $this->accessHandler->check($endpoint, 'view', $account)->isAllowed();
    }
}
