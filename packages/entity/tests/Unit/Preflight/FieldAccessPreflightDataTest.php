<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Preflight;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightData;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightResult;

final class FieldAccessPreflightDataTest extends TestCase
{
    #[Test]
    public function skeleton_result_is_checksum_bound_and_machine_readable(): void
    {
        $data = new FieldAccessPreflightData(
            frameworkVersion: 'candidate-sha',
            schemaFingerprint: 'schema-1',
            scannerVersion: 1,
            fields: ['user.mail' => 'internal'],
            conflicts: [],
            unclassifiedEntries: [],
            v1Drivers: [],
            serializedEntities: [],
            legacyPayloads: [],
        );
        $result = FieldAccessPreflightResult::fromData($data);

        self::assertTrue($result->ready);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->checksum);
        self::assertSame('candidate-sha', $result->toArray()['framework_version']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function blockingInventories(): iterable
    {
        yield 'unclassified' => ['unclassifiedEntries', ['user.custom']];
        yield 'v1 driver' => ['v1Drivers', ['App\\Storage\\Driver']];
        yield 'serialized entity' => ['serializedEntities', ['cache:users']];
        yield 'legacy payload' => ['legacyPayloads', ['queue:42']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('blockingInventories')]
    public function every_activation_inventory_blocks_readiness(string $inventory, array $entries): void
    {
        $arguments = [
            'frameworkVersion' => 'candidate-sha',
            'schemaFingerprint' => 'schema-1',
            'scannerVersion' => 1,
            'fields' => ['user.mail' => 'internal'],
            'conflicts' => [],
            'unclassifiedEntries' => [],
            'v1Drivers' => [],
            'serializedEntities' => [],
            'legacyPayloads' => [],
        ];
        $arguments[$inventory] = $entries;

        self::assertFalse(FieldAccessPreflightResult::fromData(new FieldAccessPreflightData(...$arguments))->ready);
    }
}
