<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Manifest\ConfigManifestBundleSigner;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight;

/**
 * The authoring host's half of the CFG-03 trust boundary (#2430).
 *
 * The end-to-end test here is the one that matters: what this side produces is
 * accepted by the importing side's gate, using a verifier that holds only the
 * public half of the key.
 */
#[CoversClass(ConfigManifestBundleSigner::class)]
final class ConfigManifestBundleSignerTest extends TestCase
{
    private string $root;

    private string $syncPath;

    private ConfigSchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_bundle_signer_' . bin2hex(random_bytes(6));
        $this->syncPath = $this->root . '/config/sync';
        mkdir($this->syncPath, 0o755, true);
        $this->registry = $this->freshRegistry();
        $this->writeBundle('Waaseyaa');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * The whole point of the two halves: a bundle signed where custody lives is
     * accepted where only public trust keys live.
     */
    #[Test]
    public function whatTheAuthoringHostSignsIsAcceptedByTheImportingHost(): void
    {
        $result = $this->signer()->sign($this->syncPath, 'site:sheg', 1, ['producer' => 'test-suite']);

        self::assertSame(ConfigManifestEnvelopeFile::pathFor($this->syncPath), $result->envelopePath);
        self::assertSame(1, $result->entryCount);
        self::assertSame(['waaseyaa/config' => 1], $result->requiredPackageContracts);
        self::assertSame('cfg04:test-key', $result->trustKeyReference);

        $bundle = $this->importingHostGate()->assertReady([], [], false, false, false);

        self::assertSame($result->manifestHash, $bundle->verification->manifestHash);
        self::assertSame('site:sheg', $bundle->verification->bundleScope);
        self::assertTrue($bundle->verification->signed);
    }

    /**
     * Signing must be reproducible: two runs over the same bytes, cohort,
     * scope, sequence, and evidence produce the same envelope. A signature that
     * drifted run to run would make review of the sidecar meaningless.
     */
    #[Test]
    public function signingTheSameInputTwiceProducesTheSameEnvelope(): void
    {
        $first = $this->signer()->sign($this->syncPath, 'site:sheg', 3, ['producer' => 'test-suite']);
        $firstBytes = (string) file_get_contents($first->envelopePath);

        $second = $this->signer()->sign($this->syncPath, 'site:sheg', 3, ['producer' => 'test-suite']);

        self::assertSame($firstBytes, (string) file_get_contents($second->envelopePath));
        self::assertSame($first->manifestHash, $second->manifestHash);
    }

    /** Signing reads and writes only the sidecar; it activates nothing. */
    #[Test]
    public function signingLeavesTheAuthoredDirectoryUntouched(): void
    {
        $before = $this->directoryDigest();

        $this->signer()->sign($this->syncPath, 'site:sheg', 1, ['producer' => 'test-suite']);

        self::assertSame($before, $this->directoryDigest(), 'The sync directory is an input, never an output.');
    }

    #[Test]
    public function aDirectoryThatFailsStrictValidationIsNotSigned(): void
    {
        file_put_contents($this->syncPath . '/stray.txt', 'not a config file');

        try {
            $this->signer()->sign($this->syncPath, 'site:sheg', 1, ['producer' => 'test-suite']);
            self::fail('An invalid sync directory was signed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Refusing to sign', $exception->getMessage());
            self::assertFileDoesNotExist(ConfigManifestEnvelopeFile::pathFor($this->syncPath));
        }
    }

    /**
     * A file cannot name a contract into existence: the derived cohort is
     * checked against what the authoring host actually has installed.
     */
    #[Test]
    public function aContractTheAuthoringHostDoesNotHoldIsRefused(): void
    {
        $signer = $this->signer(new ConfigPackageCompatibility([
            ConfigPackageContract::fromComposerManifest([
                'name' => 'waaseyaa/config',
                'extra' => ['waaseyaa' => ['config-contract' => [
                    'schema-provider' => 'Acme\\Example\\SchemaProvider',
                    'version' => 2,
                    'readable_versions' => [1, 2],
                ]]],
            ]),
        ]));

        $this->expectException(\RuntimeException::class);
        $signer->sign($this->syncPath, 'site:sheg', 1, ['producer' => 'test-suite']);
    }

    #[Test]
    public function theNextSequenceAndScopeContinueAnExistingLineage(): void
    {
        self::assertSame(1, ConfigManifestBundleSigner::nextSequence($this->syncPath));
        self::assertNull(ConfigManifestBundleSigner::previousScope($this->syncPath));

        $this->signer()->sign($this->syncPath, 'site:sheg', 4, ['producer' => 'test-suite']);

        self::assertSame(5, ConfigManifestBundleSigner::nextSequence($this->syncPath));
        self::assertSame('site:sheg', ConfigManifestBundleSigner::previousScope($this->syncPath));
    }

    private function signer(?ConfigPackageCompatibility $compatibility = null): ConfigManifestBundleSigner
    {
        return new ConfigManifestBundleSigner(
            new ConfigSyncBundleValidator($this->registry),
            $this->registry,
            $compatibility ?? $this->compatibility(),
            new BundleSignerTestSigner('trusted-secret'),
        );
    }

    private function importingHostGate(): SignedEnvelopeConfigImportPreflight
    {
        return new SignedEnvelopeConfigImportPreflight(
            syncPath: $this->syncPath,
            bundleValidator: new ConfigSyncBundleValidator($this->registry),
            registry: $this->registry,
            compatibility: $this->compatibility(),
            envelopeVerifier: new ConfigManifestEnvelopeVerifier(),
            signatureVerifier: new BundleSignerTestSignatureVerifier('trusted-secret'),
            replayState: new BundleSignerTestReplayState(null),
        );
    }

    private function compatibility(): ConfigPackageCompatibility
    {
        return new ConfigPackageCompatibility([
            ConfigPackageContract::fromComposerManifest([
                'name' => 'waaseyaa/config',
                'extra' => ['waaseyaa' => ['config-contract' => [
                    'schema-provider' => 'Acme\\Example\\SchemaProvider',
                    'version' => 1,
                    'readable_versions' => [1],
                ]]],
            ]),
        ]);
    }

    private function freshRegistry(): ConfigSchemaRegistry
    {
        $registry = new ConfigSchemaRegistry();
        $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
        ]);
        $registry->freeze();

        return $registry;
    }

    private function writeBundle(string $title): void
    {
        $registration = $this->registry->get('waaseyaa.system.site', 1);
        assert($registration !== null);
        $file = ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['title' => $title],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
        file_put_contents($this->syncPath . '/' . $file->filename(), new ConfigSyncSerializer()->toYaml($file));
    }

    private function directoryDigest(): array
    {
        $digest = [];
        foreach ((array) scandir($this->syncPath) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $digest[$name] = hash_file('sha256', $this->syncPath . '/' . $name);
        }
        ksort($digest);

        return $digest;
    }

}

/** Deterministic stand-in for CFG-04 custody on the authoring host. */
final readonly class BundleSignerTestSigner implements ConfigManifestSignerInterface
{
    public function __construct(private string $secret) {}

    public function algorithm(): string
    {
        return SignedConfigManifestEnvelope::ALGORITHM_V1;
    }

    public function trustKeyReference(): string
    {
        return 'cfg04:test-key';
    }

    public function sign(string $preAuthenticatedBytes): string
    {
        return substr(hash_hmac('sha512', $preAuthenticatedBytes, $this->secret, true), 0, SignedConfigManifestEnvelope::SIGNATURE_BYTES_V1);
    }
}

/** The importing host's public trust policy; it never sees the signing key. */
final readonly class BundleSignerTestSignatureVerifier implements ConfigManifestSignatureVerifierInterface
{
    public function __construct(private string $secret) {}

    public function verify(string $trustKeyReference, string $algorithm, string $preAuthenticatedBytes, string $signature): bool
    {
        if ($trustKeyReference !== 'cfg04:test-key' || $algorithm !== SignedConfigManifestEnvelope::ALGORITHM_V1) {
            return false;
        }

        return hash_equals(
            substr(hash_hmac('sha512', $preAuthenticatedBytes, $this->secret, true), 0, SignedConfigManifestEnvelope::SIGNATURE_BYTES_V1),
            $signature,
        );
    }
}

final class BundleSignerTestReplayState implements ConfigReplayStateReaderInterface
{
    public function __construct(private readonly ?int $last) {}

    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
    {
        return $this->last;
    }
}
