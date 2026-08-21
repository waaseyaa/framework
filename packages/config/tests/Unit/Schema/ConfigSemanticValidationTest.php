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
    private const array TEST_SCHEMA = [
        'dialect' => ConfigSchemaRegistry::DIALECT_V1,
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => ['type' => 'string'],
    ];

    #[Test]
    public function semantic_validator_participates_in_content_validation(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('test.assignments', 1, 'waaseyaa/config', 1, self::TEST_SCHEMA);
        $registry->registerSemanticValidator('test.assignments', 1, new RejectingTestSemanticValidator());
        $registry->freeze();
        $registration = $registry->get('test.assignments', 1);
        self::assertNotNull($registration);
        $file = $this->file($registration, ['node.bad' => 'editorial']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fails semantic validation at node.bad');
        new ConfigContentHasher()->hash($file, new ConfigSyncSerializer()->toYaml($file), $registry);
    }

    #[Test]
    public function the_declared_semantic_contract_is_bound_into_the_canonical_schema_hash(): void
    {
        $structural = new ConfigSchemaRegistry();
        $structuralRegistration = $structural->register('test.assignments', 1, 'waaseyaa/config', 1, self::TEST_SCHEMA);

        $semantic = new ConfigSchemaRegistry();
        $semantic->register('test.assignments', 1, 'waaseyaa/config', 1, self::TEST_SCHEMA);
        $semantic->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
        $semanticRegistration = $semantic->get('test.assignments', 1);

        self::assertNotNull($semanticRegistration);
        self::assertNotSame(
            $structuralRegistration->canonicalSchemaHash,
            $semanticRegistration->canonicalSchemaHash,
            'A schema guarded by a semantic contract must not share the unguarded schema hash.',
        );
    }

    #[Test]
    public function a_different_semantic_contract_yields_a_different_canonical_schema_hash(): void
    {
        $first = $this->registryWithTestSchema();
        $first->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
        $second = $this->registryWithTestSchema();
        $second->registerSemanticValidator('test.assignments', 1, new SecondTestSemanticValidator());

        self::assertNotSame(
            $first->get('test.assignments', 1)?->canonicalSchemaHash,
            $second->get('test.assignments', 1)?->canonicalSchemaHash,
        );
    }

    #[Test]
    public function an_identical_semantic_contract_is_deterministic_across_hosts(): void
    {
        $first = $this->registryWithTestSchema();
        $first->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
        $second = $this->registryWithTestSchema();
        $second->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());

        self::assertSame(
            $first->get('test.assignments', 1)?->canonicalSchemaHash,
            $second->get('test.assignments', 1)?->canonicalSchemaHash,
        );
    }

    #[Test]
    public function content_bound_to_a_semantic_contract_is_refused_by_a_host_without_that_validator(): void
    {
        $authoring = $this->registryWithTestSchema();
        $authoring->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
        $authoring->freeze();
        $authored = $authoring->get('test.assignments', 1);
        self::assertNotNull($authored);
        $file = $this->file($authored, ['node.page' => 'editorial']);
        $bytes = new ConfigSyncSerializer()->toYaml($file);

        $consumer = $this->registryWithTestSchema();
        $consumer->freeze();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema or package identity does not match the frozen registry');
        new ConfigContentHasher()->hash($file, $bytes, $consumer);
    }

    #[Test]
    public function re_registering_the_same_schema_after_a_semantic_contract_stays_idempotent(): void
    {
        $registry = $this->registryWithTestSchema();
        $registry->registerSemanticValidator('test.assignments', 1, new TestSemanticValidator());
        $guarded = $registry->get('test.assignments', 1)?->canonicalSchemaHash;

        $reregistered = $registry->register('test.assignments', 1, 'waaseyaa/config', 1, self::TEST_SCHEMA);

        self::assertSame($guarded, $reregistered->canonicalSchemaHash);
    }

    #[Test]
    public function semantic_registration_is_idempotent_for_an_equivalent_validator(): void
    {
        $registry = $this->registryWithTestSchema();
        $authority = new \stdClass();

        $registry->registerSemanticValidator('test.assignments', 1, new DependentTestSemanticValidator($authority));
        $registry->registerSemanticValidator('test.assignments', 1, new DependentTestSemanticValidator($authority));
        $registry->freeze();

        self::assertSame([], $registry->semanticViolations('test.assignments', 1, []));
    }

    #[Test]
    public function a_validator_of_the_same_class_with_different_dependencies_is_refused(): void
    {
        $registry = $this->registryWithTestSchema();
        $registry->registerSemanticValidator('test.assignments', 1, new DependentTestSemanticValidator(new \stdClass()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting semantic validation registration');
        $registry->registerSemanticValidator('test.assignments', 1, new DependentTestSemanticValidator(new \stdClass()));
    }

    #[Test]
    public function a_semantic_validator_must_declare_a_canonical_contract(): void
    {
        $registry = $this->registryWithTestSchema();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('semantic contract');
        $registry->registerSemanticValidator('test.assignments', 1, new UndeclaredTestSemanticValidator());
    }

    #[Test]
    public function semantic_registration_is_frozen_with_the_registry(): void
    {
        $registry = $this->registryWithTestSchema();
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

    /** @param array<string, mixed> $fields */
    private function file(\Waaseyaa\Config\Schema\ConfigSchemaRegistration $registration, array $fields): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            entityType: 'test',
            entityId: 'assignments',
            uuid: ConfigSyncFile::deterministicUuid('test', 'assignments'),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
    }

    private function registryWithTestSchema(): ConfigSchemaRegistry
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('test.assignments', 1, 'waaseyaa/config', 1, self::TEST_SCHEMA);

        return $registry;
    }
}

final class TestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function contract(): string
    {
        return 'waaseyaa/config:test.assignments@1/semantic/1';
    }

    public function validate(array $fields): array
    {
        return [];
    }
}

final class SecondTestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function contract(): string
    {
        return 'waaseyaa/config:test.assignments@1/semantic/2';
    }

    public function validate(array $fields): array
    {
        return [];
    }
}

final class RejectingTestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function contract(): string
    {
        return 'waaseyaa/config:test.assignments@1/rejecting/1';
    }

    public function validate(array $fields): array
    {
        return isset($fields['node.bad'])
            ? [new SchemaViolation('node.bad', 'The assignment is semantically invalid.')]
            : [];
    }
}

final class DependentTestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function __construct(public readonly object $authority) {}

    public function contract(): string
    {
        return 'waaseyaa/config:test.assignments@1/dependent/1';
    }

    public function validate(array $fields): array
    {
        return [];
    }
}

final class UndeclaredTestSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function contract(): string
    {
        return '';
    }

    public function validate(array $fields): array
    {
        return [];
    }
}
