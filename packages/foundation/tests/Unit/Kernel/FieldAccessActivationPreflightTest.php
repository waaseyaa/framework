<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Exception\FieldAccessActivationBlocked;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightData;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightResult;
use Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight;

final class FieldAccessActivationPreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa-field-activation-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.waaseyaa', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.waaseyaa/field-access-preflight.json');
        @rmdir($this->root . '/.waaseyaa');
        @rmdir($this->root);
    }

    public function testBootGuardHardFailsAChecksumValidArtifactWithRemainingBlockers(): void
    {
        $this->writeArtifact(['queue:waaseyaa_queue_jobs:9']);

        $this->expectException(FieldAccessActivationBlocked::class);
        new FieldAccessActivationPreflight()->assertReady($this->root, 'candidate-1', 'schema-1');
    }

    public function testBootGuardAcceptsOnlyCurrentChecksumValidReadiness(): void
    {
        $this->writeArtifact([]);

        new FieldAccessActivationPreflight()->assertReady($this->root, 'candidate-1', 'schema-1');
        self::addToAssertionCount(1);
    }

    public function testBootGuardRejectsAStaleOrTamperedReadyArtifact(): void
    {
        $this->writeArtifact([]);
        $artifact = json_decode(
            (string) file_get_contents($this->root . '/.waaseyaa/field-access-preflight.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $artifact['checksum'] = str_repeat('0', 64);
        file_put_contents(
            $this->root . '/.waaseyaa/field-access-preflight.json',
            json_encode($artifact, JSON_THROW_ON_ERROR),
        );

        $this->expectException(FieldAccessActivationBlocked::class);
        new FieldAccessActivationPreflight()->assertReady($this->root, 'candidate-1', 'schema-1');
    }

    /** @param list<string> $legacyPayloads */
    private function writeArtifact(array $legacyPayloads): void
    {
        $result = FieldAccessPreflightResult::fromData(new FieldAccessPreflightData(
            frameworkVersion: 'candidate-1',
            schemaFingerprint: 'schema-1',
            scannerVersion: 1,
            fields: ['user|*|uid' => 'public:structural'],
            conflicts: [],
            unclassifiedEntries: [],
            v1Drivers: [],
            serializedEntities: [],
            legacyPayloads: $legacyPayloads,
        ));
        file_put_contents(
            $this->root . '/.waaseyaa/field-access-preflight.json',
            json_encode($result->toArray(), JSON_THROW_ON_ERROR),
        );
    }
}
