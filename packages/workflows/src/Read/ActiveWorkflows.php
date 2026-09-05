<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Workflow;

/**
 * Reads the {@see Workflow} entities actually bound via `workflows.assignments`
 * config (CW-v1, docs/specs/content-workflow.md) — the active, verified
 * workflow configuration, as opposed to any in-code preset.
 *
 * Distinct assignment target ids are collected from the raw config map and
 * loaded once via {@see \Waaseyaa\Entity\Repository\EntityRepositoryInterface::findMany()}
 * — deliberately not `getQuery()`, so the framework's unbound-getQuery gate
 * (`bin/check-getquery-bindings`) stays clean; a config entity read has no
 * per-row account to bind.
 *
 * #2835: this is the seam the production `GET /api/workflow-definitions`
 * route resolves through ({@see \Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter}),
 * replacing the retired `EditorialWorkflowPreset` fallback the controller
 * used when constructed with no provider — a preset that had already
 * drifted from the live editorial transition set.
 *
 * @api
 */
final class ActiveWorkflows
{
    private const string ASSIGNMENTS_CONFIG_NAME = 'workflows.assignments';

    public function __construct(
        private readonly ConfigFactoryInterface $configFactory,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * Every distinct workflow named anywhere in `workflows.assignments`, in
     * the order each id first appears. Returns `[]` — a well-formed empty
     * result — when no entity type/bundle is bound to a workflow, rather
     * than any plausible fictional default.
     *
     * @return list<Workflow>
     */
    public function all(): array
    {
        $assignments = $this->configFactory->get(self::ASSIGNMENTS_CONFIG_NAME)->getRawData();

        $ids = [];
        foreach ($assignments as $workflowId) {
            if (\is_string($workflowId) && $workflowId !== '' && !\in_array($workflowId, $ids, true)) {
                $ids[] = $workflowId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $entities = $this->entityTypeManager->getRepository('workflow')->findMany($ids);

        $workflows = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Workflow) {
                $workflows[] = $entity;
            }
        }

        return $workflows;
    }
}
