<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Config;

use Waaseyaa\Config\Schema\ConfigSemanticValidatorInterface;
use Waaseyaa\Config\Schema\SchemaViolation;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator;

/** @internal */
final readonly class WorkflowAssignmentsSemanticValidator implements ConfigSemanticValidatorInterface
{
    /**
     * Advance when the enforced semantics change. CFG-03 binds
     * {@see self::contract()} into the canonical schema hash, so a bump
     * invalidates content authored under the previous semantics rather than
     * letting it verify against a contract that no longer holds.
     */
    public const int SEMANTIC_CONTRACT_VERSION = 1;

    /**
     * Cross-assignment verdicts belong to the whole authored document, not to
     * one entry: {@see WorkflowAssignmentsValidator} judges the complete map in
     * a single pass so a rule spanning several bindings can be expressed.
     */
    public const string DOCUMENT_PATH = '$';

    public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

    public function contract(): string
    {
        return sprintf(
            '%s:%s@%d/semantic/%d',
            WorkflowAssignmentsConfig::OWNER_PACKAGE,
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
            self::SEMANTIC_CONTRACT_VERSION,
        );
    }

    /**
     * @param array<array-key, mixed> $fields Structurally valid authored fields.
     *   The config schema documents string keys, but PHP silently casts a
     *   numeric-string key to an int array key — checked here rather than
     *   assumed, the same way {@see WorkflowAssignmentsValidator} does.
     * @return list<SchemaViolation>
     */
    public function validate(array $fields): array
    {
        $violations = [];
        foreach ($fields as $binding => $workflowId) {
            if (preg_match('/^[a-z][a-z0-9_]*\.(?:[a-z][a-z0-9_]*|\*)$/D', (string) $binding) !== 1) {
                $violations[] = new SchemaViolation(
                    (string) $binding,
                    'Workflow assignment keys must use the canonical `<entity_type>.<bundle|*>` form.',
                );
                continue;
            }
            if (!is_string($workflowId) || preg_match('/^[a-z][a-z0-9_]*$/D', $workflowId) !== 1) {
                $violations[] = new SchemaViolation(
                    (string) $binding,
                    'Workflow assignment values must be canonical workflow IDs.',
                );
            }
        }
        // A noncanonical entry is not a well-formed assignment, so the map is
        // not yet complete enough to judge as a whole. Report the malformed
        // entries and stop rather than asking the canonical validator to reason
        // about a partial document.
        if ($violations !== []) {
            return $violations;
        }

        foreach (new WorkflowAssignmentsValidator()->validate($fields, $this->entityTypeManager) as $message) {
            $violations[] = new SchemaViolation(self::DOCUMENT_PATH, $message);
        }

        return $violations;
    }
}
