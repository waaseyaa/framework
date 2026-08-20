<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Schema\ConfigSemanticValidatorInterface;
use Waaseyaa\Config\Schema\SchemaViolation;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;

#[CoversClass(ConfigSchemaRegistry::class)]
#[CoversClass(ConfigContentHasher::class)]
final class ConfigSemanticValidationTest extends TestCase
{
    #[Test]
    public function semantic_validator_participates_in_content_validation(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('test.assignments', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => ['type' => 'string'],
        ]);
        $registry->registerSemanticValidator('test.assignments', 1, new class implements ConfigSemanticValidatorInterface {
            public function validate(array $fields): array
            {
                return isset($fields['node.bad'])
                    ? [new SchemaViolation('node.bad', 'The assignment is semantically invalid.')]
                    : [];
            }
        });
        $registry->freeze();
        $file = ConfigSyncFile::writable(
            entityType: 'test',
            entityId: 'assignments',
            uuid: ConfigSyncFile::deterministicUuid('test', 'assignments'),
            dependencies: [],
            langcode: 'en',
            fields: ['node.bad' => 'editorial'],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fails semantic validation at node.bad');
        new ConfigContentHasher()->hash($file, new ConfigSyncSerializer()->toYaml($file), $registry);
    }

    #[Test]
    public function semantic_registration_is_idempotent_by_validator_class_and_frozen_with_the_registry(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('test.assignments', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [],
        ]);
        $validator = new TestSemanticValidator();

        $registry->registerSemanticValidator('test.assignments', 1, $validator);
        $registry->registerSemanticValidator('test.assignments', 1, $validator);
        $registry->freeze();
        self::assertSame([], $registry->semanticViolations('test.assignments', 1, []));

        $this->expectException(\LogicException::class);
        $registry->registerSemanticValidator('test.assignments', 1, $validator);
    }

    #[Test]
    public function semantic_registration_requires_an_existing_schema_identity(): void
    {
        $registry = new ConfigSchemaRegistry();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a registered schema');
        $registry->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
    }

    #[Test]
    public function a_competing_semantic_validator_for_the_same_identity_is_refused(): void
    {
        $registry = $this->registryWithTestSchema();
        $registry->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting semantic validation registration');
        $registry->registerSemanticValidator('test.assignments', 1, new SecondTestSemanticValidator());
    }

    #[Test]
    public function semantic_validation_requires_the_registry_to_be_frozen(): void
    {
        $registry = $this->registryWithTestSchema();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the frozen schema registry');
        $registry->semanticViolations('test.assignments', 1, []);
    }

    private function registryWithTestSchema(): ConfigSchemaRegistry
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('test.assignments', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [],
        ]);

        return $registry;
    }
}

final class TestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function validate(array $fields): array
    {
        return [];
    }
}

final class SecondTestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function validate(array $fields): array
    {
        return [];
    }
}
