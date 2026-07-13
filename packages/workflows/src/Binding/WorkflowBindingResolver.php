<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Binding;

use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Workflow;

/**
 * Resolves the {@see Workflow} bound to an entity type + bundle, per the
 * `workflows.assignments` config (CW-v1, docs/specs/content-workflow.md).
 *
 * Raw config shape: `['node.article' => 'editorial', 'node.*' => 'editorial']`
 * — an exact `{entity_type}.{bundle}` key wins over the `{entity_type}.*`
 * wildcard. No entry for either key means the type/bundle is unbound (a
 * workflow-agnostic entity type — this is the common case for most content).
 *
 * Resolved workflows are memoized per (entityTypeId, bundle) pair for the
 * lifetime of this instance. This is boot-stable, not process-stable: a new
 * request/worker constructs a new resolver (via the service container), so
 * there is no cross-request staleness — the same pattern used by the
 * wayfinding anchor registry for its boot-scoped caches.
 *
 * @api
 */
final class WorkflowBindingResolver
{
    private const string ASSIGNMENTS_CONFIG_NAME = 'workflows.assignments';

    /** @var array<string, ?Workflow> */
    private array $resolved = [];

    public function __construct(
        private readonly ConfigFactoryInterface $configFactory,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * @throws \RuntimeException When a binding names a non-revisionable entity
     *   type, or an unknown workflow id.
     */
    public function resolve(string $entityTypeId, string $bundle): ?Workflow
    {
        $cacheKey = $entityTypeId . '.' . $bundle;
        if (\array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $assignments = $this->configFactory->get(self::ASSIGNMENTS_CONFIG_NAME)->getRawData();

        $workflowId = $assignments[$entityTypeId . '.' . $bundle]
            ?? $assignments[$entityTypeId . '.*']
            ?? null;

        if (!\is_string($workflowId) || $workflowId === '') {
            return $this->resolved[$cacheKey] = null;
        }

        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        if (!$definition->isRevisionable()) {
            throw new \RuntimeException(\sprintf(
                "Workflow binding '%s.%s' => '%s' is invalid: entity type '%s' is not revisionable. "
                . 'Workflow bindings require revisionable storage (docs/specs/content-workflow.md).',
                $entityTypeId,
                $bundle,
                $workflowId,
                $entityTypeId,
            ));
        }

        // CW-v1 option-1 (#1920 PR-2, design §1): a revisionable AND
        // translatable ("two-axis") entity type cannot be bound to a
        // workflow at all. Default-revision discipline lives on the
        // single-axis `published_revision_id`/`revision_id` base-row
        // pointers; a two-axis type's per-language peer rows have no such
        // pointer pair to discipline. Per-translation workflow state is a
        // documented post-v1 stage (docs/specs/content-workflow.md, "State
        // lives on revisions" — "Staged limitation"); silently skipping
        // discipline for a bound two-axis type would reintroduce the
        // finding-#11 draft leak the moment an operator binds one. Same loud
        // failure mode as the non-revisionable throw above.
        if ($definition->isTranslatable()) {
            throw new \RuntimeException(\sprintf(
                "Workflow binding '%s.%s' => '%s' is invalid: entity type '%s' is revisionable AND "
                . 'translatable (two-axis storage). Workflow bindings require single-axis revisionable '
                . 'storage — per-translation workflow state is a post-v1 stage '
                . '(docs/specs/content-workflow.md).',
                $entityTypeId,
                $bundle,
                $workflowId,
                $entityTypeId,
            ));
        }

        // Deviation from the plan's literal text (getStorage()->load()):
        // production kernel wiring passes storageFactory: null to
        // EntityTypeManager (EntityTypeManagerFactory::build(), C-22 WP4 —
        // "the legacy SqlEntityStorage engine is removed"), so getStorage()
        // throws for any entity type without an explicit storageClass, which
        // 'workflow' does not declare. getRepository()->find() is the live
        // pipeline for every entity type, config entities included.
        $workflow = $this->entityTypeManager->getRepository('workflow')->find($workflowId);
        if (!$workflow instanceof Workflow) {
            throw new \RuntimeException(\sprintf(
                "Workflow binding '%s.%s' names unknown workflow '%s'.",
                $entityTypeId,
                $bundle,
                $workflowId,
            ));
        }

        return $this->resolved[$cacheKey] = $workflow;
    }
}
