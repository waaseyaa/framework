<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Access;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Workflows\Transition\TransitionService;

/**
 * Exact current-working-copy predicate behind workflow-derived visibility.
 *
 * @internal
 */
final class WorkflowAuthorityVisibility
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TransitionService $transitionService,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function allows(
        string $entityTypeId,
        string $bundle,
        int|string|null $entityId,
        AccountInterface $account,
    ): bool {
        if (!$account->isAuthenticated() || $entityId === null || $entityTypeId === '' || $bundle === '') {
            return false;
        }

        try {
            if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
                return false;
            }

            $workingCopy = $this->entityTypeManager
                ->getRepository($entityTypeId)
                ->loadWorkingCopy((string) $entityId);

            // A visibility decision is valid only for the exact current
            // working copy requested. Never accept a repository adapter that
            // hands back a different subject, bundle, or identity.
            if ($workingCopy === null
                || $workingCopy->getEntityTypeId() !== $entityTypeId
                || $workingCopy->bundle() !== $bundle
                || (string) $workingCopy->id() !== (string) $entityId
            ) {
                return false;
            }

            // This is the sanctioned transition-discovery predicate. It
            // evaluates only outgoing edges from the supplied working copy's
            // current state and includes resolved permission plus live,
            // fail-closed group constraints.
            return $this->transitionService->getAvailableTransitions($workingCopy, $account) !== [];
        } catch (\Throwable $e) {
            // Visibility is never worth a permissive fallback. A broken or
            // unknown binding stays hidden while leaving an operator signal.
            $this->logger->warning('workflow.authority_visibility_unresolved', [
                'entity_type' => $entityTypeId,
                'entity_id' => (string) $entityId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
