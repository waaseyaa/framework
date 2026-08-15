<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigSchemaRegistration;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

#[CoversClass(ConfigSchemaRegistry::class)]
#[CoversClass(ConfigSchemaRegistration::class)]
final class ConfigSchemaRegistryTest extends TestCase
{
    #[Test]
    public function exact_duplicate_registration_is_idempotent(): void
    {
        $registry = new ConfigSchemaRegistry();

        $first = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());
        $second = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());

        self::assertSame($first, $second);
        self::assertCount(1, $registry->all());
    }

    #[Test]
    public function conflicting_identity_is_rejected(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());

        $changed = $this->schema();
        $changed['properties']['title']['default'] = 'Changed';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting configuration schema registration');
        $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $changed);
    }

    #[Test]
    public function registration_after_freeze_is_rejected_even_when_duplicate(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());
        $registry->freeze();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('frozen');
        $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());
    }

    #[Test]
    public function registry_checksum_is_independent_of_registration_order(): void
    {
        $left = new ConfigSchemaRegistry();
        $left->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());
        $left->register('waaseyaa.system.mail', 1, 'waaseyaa/config', 1, $this->schema());

        $right = new ConfigSchemaRegistry();
        $right->register('waaseyaa.system.mail', 1, 'waaseyaa/config', 1, $this->schema());
        $right->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $this->schema());

        self::assertSame($left->checksum(), $right->checksum());
    }

    #[Test]
    public function v1_dialect_is_required(): void
    {
        $schema = $this->schema();
        unset($schema['dialect']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dialect');
        (new ConfigSchemaRegistry())->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $schema);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'default' => 'Waaseyaa'],
            ],
        ];
    }
}
