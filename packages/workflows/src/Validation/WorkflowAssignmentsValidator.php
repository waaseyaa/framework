<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Validation;

use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Structural validator for the `workflows.assignments` config (CW-v1
 * option-1, #1920 PR-2, docs/specs/content-workflow.md "Concepts and config
 * schema" — "Binding requires the entity type to be revisionable — rejected
 * at config-import validation otherwise").
 *
 * {@see \Waaseyaa\Workflows\Binding\WorkflowBindingResolver::resolve()} is
 * the RUNTIME enforcement of this same rule (a bound type that is not
 * revisionable, or that is revisionable AND translatable, hard-throws on
 * first resolve). This class is the same two checks run PURELY over the raw
 * assignments array plus the entity type manager's registered definitions —
 * no config-store I/O, no caching — so a config-import surface can reject a
 * malformed `workflows.assignments` payload before it is ever persisted,
 * the same way {@see WorkflowValidator} lets the boot seed (and, per its own
 * docblock, "the config-import surface (later work)") reject a malformed
 * workflow definition before persisting it.
 *
 * The production `config:import` handler invokes this validator against the
 * sync-store value before `ConfigManager::import()` performs any writes.
 *
 * @internal
 */
final class WorkflowAssignmentsValidator
{
    /**
     * @param array<array-key, mixed> $assignments Raw `workflows.assignments` config data:
     *   `{entity_type}.{bundle}` or `{entity_type}.*` => workflow id. Keys are
     *   documented as strings by the config schema, but PHP silently casts a
     *   numeric-string key to an int array key — checked explicitly below
     *   rather than assumed via the PHPDoc type.
     * @return list<string> Human-readable violations; empty list means valid.
     */
    public function validate(array $assignments, EntityTypeManagerInterface $entityTypeManager): array
    {
        $violations = [];

        foreach ($assignments as $key => $workflowId) {
            if (!\is_string($key) || !\is_string($workflowId) || $workflowId === '') {
                continue;
            }

            $separator = \strrpos($key, '.');
            if ($separator === false) {
                continue;
            }
            $entityTypeId = \substr($key, 0, $separator);

            if (!$entityTypeManager->hasDefinition($entityTypeId)) {
                continue;
            }

            $definition = $entityTypeManager->getDefinition($entityTypeId);

            if (!$definition->isRevisionable()) {
                $violations[] = \sprintf(
                    "Workflow binding '%s' => '%s' is invalid: entity type '%s' is not revisionable. "
                    . 'Workflow bindings require revisionable storage.',
                    $key,
                    $workflowId,
                    $entityTypeId,
                );

                continue;
            }

            if ($definition->isTranslatable()) {
                $violations[] = \sprintf(
                    "Workflow binding '%s' => '%s' is invalid: entity type '%s' is revisionable AND "
                    . 'translatable (two-axis storage). Workflow bindings require single-axis revisionable '
                    . 'storage — per-translation workflow state is a post-v1 stage.',
                    $key,
                    $workflowId,
                    $entityTypeId,
                );
            }
        }

        return $violations;
    }
}
