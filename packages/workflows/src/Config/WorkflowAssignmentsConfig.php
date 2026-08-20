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

    public static function register(
        ConfigSchemaRegistry $registry,
        ?EntityTypeManagerInterface $entityTypeManager = null,
    ): ConfigSchemaRegistration {
        $registration = $registry->register(
            self::CONFIG_NAME,
            self::SCHEMA_VERSION,
            self::OWNER_PACKAGE,
            self::OWNER_CONFIG_CONTRACT_VERSION,
            self::schema(),
        );
        if ($entityTypeManager instanceof EntityTypeManagerInterface) {
            $registry->registerSemanticValidator(
                self::CONFIG_NAME,
                self::SCHEMA_VERSION,
                new WorkflowAssignmentsSemanticValidator($entityTypeManager),
            );
        }

        return $registration;
    }
}
