<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Config;

use Waaseyaa\Config\Schema\ConfigSchemaRegistration;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** CFG-03 schema authority for bundle-to-workflow bindings. @api */
final class WorkflowAssignmentsConfig
{
    public const string CONFIG_NAME = 'workflows.assignments';
    public const int SCHEMA_VERSION = 1;
    public const string OWNER_PACKAGE = 'waaseyaa/workflows';
    public const int OWNER_CONFIG_CONTRACT_VERSION = 1;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => ['type' => 'string'],
        ];
    }

    /**
     * The closed dialect can only say "a map of strings". Which bindings are
     * admissible depends on the installed entity types, so the semantic
     * authority is a required argument (#2458): there is no registration of
     * this schema without it, and no structural-only fallback that would let
     * an arbitrary string reach the trusted CFG-03 identity.
     */
    public static function register(
        ConfigSchemaRegistry $registry,
        EntityTypeManagerInterface $entityTypeManager,
    ): ConfigSchemaRegistration {
        $registry->register(
            self::CONFIG_NAME,
            self::SCHEMA_VERSION,
            self::OWNER_PACKAGE,
            self::OWNER_CONFIG_CONTRACT_VERSION,
            self::schema(),
        );
        $registry->registerSemanticValidator(
            self::CONFIG_NAME,
            self::SCHEMA_VERSION,
            new WorkflowAssignmentsSemanticValidator($entityTypeManager),
        );

        // Read back rather than reuse the pre-semantic registration: binding the
        // semantic contract re-derives the canonical schema hash.
        return $registry->get(self::CONFIG_NAME, self::SCHEMA_VERSION)
            ?? throw new \LogicException('The workflow assignment schema vanished from the registry after registration.');
    }
}
