<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionMetadata;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Routes view_revision access checks to the correct policy method with open-by-default fallback.
 *
 * Per contracts/revisionable-entity.md §11.2:
 * - If the resolved policy declares viewRevision(), that method decides access.
 * - If the policy does NOT declare viewRevision(), access falls back to the entity-level
 *   view() check. The framework MUST NOT default-deny in this case.
 *
 * A structured log line (info, channel entity.lifecycle, outcome=view_revision_fallback) is
 * emitted whenever the fallback path is taken so operators can detect policies that have not
 * yet been updated to declare viewRevision().
 *
 * @api
 */
final class RevisionAccessRouter
{
    private readonly LoggerInterface $logger;

    /**
     * @param AccessPolicyInterface[] $policies  All registered access policies (same set passed to EntityAccessHandler).
     * @param LoggerInterface|null    $logger     Structured logger; defaults to NullLogger.
     */
    public function __construct(
        private readonly array $policies = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Route a view_revision access check.
     *
     * Resolution order:
     * 1. Find a policy that applies to the entity type.
     * 2. If the policy has a viewRevision() method → call it and return its AccessResult.
     * 3. If no viewRevision() method exists → fall back to view(), emit a log line, return result.
     * 4. If no policy applies at all → fall back to view() via the same log-and-return path.
     *
     * INVARIANT: this method NEVER returns Forbidden without first consulting view().
     * Any code path that skips view() and returns Forbidden is a contract violation.
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    public function route(
        RevisionableEntityInterface $entity,
        AccountInterface $account,
        RevisionMetadata $revision,
    ): AccessResult {
        $policy = $this->resolvePolicy($entity->getEntityTypeId());

        if ($policy !== null && method_exists($policy, 'viewRevision')) {
            // Policy explicitly handles view_revision — delegate directly.
            return $policy->viewRevision($entity, $account, $revision);
        }

        // No explicit viewRevision() → fall back to view(). This is open-by-default:
        // the absence of a viewRevision() declaration does NOT flip to Forbidden.
        $this->emitFallbackLog($entity, $revision, $policy);

        if ($policy !== null) {
            return $policy->access($entity, GateInterface::VIEW, $account);
        }

        // No policy found at all → neutral (same as EntityAccessHandler with no matching policy).
        return AccessResult::neutral('No policy registered for entity type; revision access is neutral.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolvePolicy(string $entityTypeId): ?AccessPolicyInterface
    {
        foreach ($this->policies as $policy) {
            if ($policy->appliesTo($entityTypeId)) {
                return $policy;
            }
        }

        return null;
    }

    private function emitFallbackLog(
        RevisionableEntityInterface $entity,
        RevisionMetadata $revision,
        ?AccessPolicyInterface $policy,
    ): void {
        $this->logger->info('view_revision access fell back to view()', [
            'channel'        => 'entity.lifecycle',
            'outcome'        => 'view_revision_fallback',
            'entity_type_id' => $entity->getEntityTypeId(),
            'entity_id'      => (string) $entity->id(),
            'vid'            => (string) $entity->revisionId(),
            'policy_fqcn'    => $policy !== null ? $policy::class : 'none',
        ]);
    }
}
