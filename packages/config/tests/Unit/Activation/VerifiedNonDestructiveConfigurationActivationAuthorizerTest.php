<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Activation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\VerifiedNonDestructiveConfigurationActivationAuthorizer;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Manifest\VerifiedConfigManifest;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

/**
 * The production activation authority (#2430).
 *
 * It authorizes exactly one shape: an ordinary activation carrying a genuinely
 * verified signed bundle that deletes nothing. Every other shape refuses, and
 * each refusal below is a policy someone would otherwise have had to invent
 * silently.
 */
#[CoversClass(VerifiedNonDestructiveConfigurationActivationAuthorizer::class)]
final class VerifiedNonDestructiveConfigurationActivationAuthorizerTest extends TestCase
{
    private ConfigSchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ConfigSchemaRegistry();
        $this->registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
        ]);
        $this->registry->freeze();
    }

    #[Test]
    public function anOrdinarySignedNonDestructiveActivationIsAuthorized(): void
    {
        $this->expectNotToPerformAssertions();

        new VerifiedNonDestructiveConfigurationActivationAuthorizer()
            ->authorize($this->request(), false);
    }

    /**
     * A signature proves what the new content is, never that removing existing
     * content is intended. Destructive import policy is a separate decision.
     *
     * Tombstones are the test, not the `$deletes` argument: every ordinary
     * verified activation is a complete replacement, so that argument is always
     * true and testing it would refuse every import.
     */
    #[Test]
    public function anActivationCarryingTombstonesIsRefused(): void
    {
        $request = $this->request(['system.retired' => str_repeat('b', 64)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/deletes 1 active entry/i');
        new VerifiedNonDestructiveConfigurationActivationAuthorizer()->authorize($request, true);
    }

    /**
     * The complete-replacement capability alone is not a deletion — the
     * activator requires a content-bound tombstone for every omitted active
     * entry, so no tombstones means nothing is removed. Refusing on the
     * capability would be indistinguishable from having no authorizer at all.
     */
    #[Test]
    public function completeReplacementWithoutTombstonesIsAuthorized(): void
    {
        $this->expectNotToPerformAssertions();

        new VerifiedNonDestructiveConfigurationActivationAuthorizer()->authorize($this->request(), true);
    }

    /** Rollback keeps its own unbound validator and is refused here. */
    #[Test]
    public function rollbackIsRefused(): void
    {
        $request = new ConfigurationActivationRequest(
            requestId: 'rollback-1',
            expectedToken: null,
            files: [],
            operation: 'rollback',
            targetGenerationId: str_repeat('a', 64),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/rollback/i');
        new VerifiedNonDestructiveConfigurationActivationAuthorizer()->authorize($request, false);
    }

    /**
     * Unsigned verification exists only under sealed bootstrap policy, which
     * production refuses. Authorizing it here would reopen that door.
     */
    #[Test]
    public function anUnsignedVerifiedBundleIsRefused(): void
    {
        $request = ConfigurationActivationRequest::activateVerified(
            'unsigned-1',
            null,
            $this->verifiedBundle(signed: false),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/unsigned/i');
        new VerifiedNonDestructiveConfigurationActivationAuthorizer()->authorize($request, false);
    }

    /** Genesis is not operator-authorized and must never resolve here. */
    #[Test]
    public function genesisIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/genesis/i');
        new VerifiedNonDestructiveConfigurationActivationAuthorizer()
            ->authorize(ConfigurationActivationRequest::genesis('install-init-test'), false);
    }

    /** @param array<string, string> $tombstones */
    private function request(array $tombstones = []): ConfigurationActivationRequest
    {
        return ConfigurationActivationRequest::activateVerified(
            'ordinary-1',
            null,
            $this->verifiedBundle(),
            $tombstones,
        );
    }

    private function syncFile(): ConfigSyncFile
    {
        $registration = $this->registry->get('waaseyaa.system.site', 1);
        assert($registration !== null);

        return ConfigSyncFile::writable(
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
    }

    private function verifiedBundle(bool $signed = true): VerifiedConfigBundle
    {
        $file = $this->syncFile();
        $bytes = new ConfigSyncSerializer()->toYaml($file);
        $result = new ConfigSyncBundleValidationResult([
            new ValidatedConfigSyncEntry($file, $bytes, new ConfigContentHasher()->hash($file, $bytes, $this->registry)),
        ], []);
        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $this->registry,
            'site:test',
            1,
            ['producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );

        return VerifiedConfigBundle::bind(
            $result,
            $this->registry,
            new VerifiedConfigManifest(
                manifest: $manifest,
                manifestHash: $manifest->manifestHash,
                bundleScope: 'site:test',
                bundleSequence: 1,
                trustKeyReference: $signed ? 'cfg04:test-key' : 'unsigned-sealed-local:test',
                signed: $signed,
            ),
            new ConfigPackageCompatibility([
                ConfigPackageContract::fromComposerManifest([
                    'name' => 'waaseyaa/config',
                    'extra' => ['waaseyaa' => ['config-contract' => [
                        'schema-provider' => 'Acme\\Example\\SchemaProvider',
                        'version' => 1,
                        'readable_versions' => [1],
                    ]]],
                ]),
            ]),
        );
    }
}
