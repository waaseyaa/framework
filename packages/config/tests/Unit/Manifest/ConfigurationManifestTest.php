<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\EffectiveGenerationManifest;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

#[CoversClass(ConfigSyncBundleManifest::class)]
#[CoversClass(EffectiveGenerationManifest::class)]
final class ConfigurationManifestTest extends TestCase
{
    #[Test]
    public function authoredBundleAndEffectiveGenerationHaveDistinctStableIdentities(): void
    {
        [$registry, $result] = $this->fixture();

        $bundle = ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $registry,
            'site:test',
            7,
            ['framework_ref' => 'abc123', 'producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );
        $generation = EffectiveGenerationManifest::fromValidatedBundle($result);

        self::assertNotSame($bundle->manifestHash, $generation->generationId);
        self::assertSame(7, $bundle->document['bundle_sequence']);
        self::assertArrayNotHasKey('generation_id', $generation->document);
        self::assertArrayNotHasKey('checksum', $generation->document);
        self::assertStringNotContainsString($generation->generationId, $generation->canonicalBytes);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $generation->generationId);
    }

    #[Test]
    public function verifiedManifestBindsOnlyTheExactCompleteValidatedBundle(): void
    {
        [$registry, $result] = $this->fixture();
        $manifest = $this->bundle($result, $registry);
        $verification = new ConfigManifestEnvelopeVerifier()->verifyUnsigned(
            $manifest,
            UnsignedConfigPolicy::sealedLocal('authority:test', 'sha256:' . str_repeat('a', 64)),
        );

        $compatibility = new ConfigPackageCompatibility([
            new ConfigPackageContract('waaseyaa/config', 1, 'test.fixture.Provider', [1]),
        ]);
        $bundle = VerifiedConfigBundle::bind($result, $registry, $verification, $compatibility);
        self::assertSame($manifest->manifestHash, $bundle->verification->manifestHash);
        self::assertCount(1, $bundle->files());

        [, $different] = $this->fixture("# changed exact bytes\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match the complete validated sync directory');
        VerifiedConfigBundle::bind($different, $registry, $verification, $compatibility);
    }

    #[Test]
    public function commentsChangeAuthoredManifestButNotEffectiveGeneration(): void
    {
        [$registry, $plain] = $this->fixture();
        [, $commented] = $this->fixture("# operator note\n");

        $plainBundle = $this->bundle($plain, $registry);
        $commentedBundle = $this->bundle($commented, $registry);

        self::assertNotSame($plainBundle->manifestHash, $commentedBundle->manifestHash);
        self::assertSame(
            EffectiveGenerationManifest::fromValidatedBundle($plain)->generationId,
            EffectiveGenerationManifest::fromValidatedBundle($commented)->generationId,
        );
    }

    #[Test]
    public function missingOwnerContractPreventsManifestConstruction(): void
    {
        [$registry, $result] = $this->fixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not cover');
        ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $registry,
            'site:test',
            1,
            ['producer' => 'test-suite'],
            [],
        );
    }

    private function bundle(ConfigSyncBundleValidationResult $result, ConfigSchemaRegistry $registry): ConfigSyncBundleManifest
    {
        return ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $registry,
            'site:test',
            1,
            ['producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );
    }

    /** @return array{ConfigSchemaRegistry, ConfigSyncBundleValidationResult} */
    private function fixture(string $prefix = ''): array
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'title' => ['type' => 'string'],
            ],
            'required' => ['title'],
        ]);
        $registry->freeze();
        $file = ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['title' => 'Waaseyaa'],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
        $bytes = $prefix . new ConfigSyncSerializer()->toYaml($file);
        $hashes = new ConfigContentHasher()->hash($file, $bytes, $registry);

        return [$registry, new ConfigSyncBundleValidationResult([
            new ValidatedConfigSyncEntry($file, $bytes, $hashes),
        ], [])];
    }
}
