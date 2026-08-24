<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigImportPreflightException;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

/**
 * The consumer half of the CFG-03 trust boundary (#2430).
 *
 * Everything here runs as the importing host: it holds public trust keys and
 * never the signing key. Each refusal below is a way an untrusted or stale
 * bundle could otherwise reach the active store.
 */
#[CoversClass(SignedEnvelopeConfigImportPreflight::class)]
final class SignedEnvelopeConfigImportPreflightTest extends TestCase
{
    private string $root;

    private string $syncPath;

    private ConfigSchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_signed_preflight_' . bin2hex(random_bytes(6));
        $this->syncPath = $this->root . '/config/sync';
        mkdir($this->syncPath, 0o755, true);
        $this->registry = $this->freshRegistry();
        $this->writeBundle('Waaseyaa');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function anAuthoredBundleWithItsSignedEnvelopeIsAuthorized(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope());

        $bundle = $this->preflight()->assertReady([], [], false, false, false);

        self::assertInstanceOf(VerifiedConfigBundle::class, $bundle);
        self::assertTrue($bundle->verification->signed);
        self::assertSame('site:test', $bundle->verification->bundleScope);
    }

    /**
     * The default state of a freshly installed site: genesis activated an empty
     * generation and nobody has authored a signed bundle yet. The refusal has
     * to name the missing sidecar, because "import refused" with no location is
     * the failure mode this whole issue is correcting.
     */
    #[Test]
    public function anAbsentEnvelopeRefusesAndNamesTheExpectedPath(): void
    {
        $this->expectException(ConfigImportPreflightException::class);
        $this->expectExceptionMessageMatches('#config/sync\.envelope\.json#');
        $this->preflight()->assertReady([], [], false, false, false);
    }

    /**
     * Unsigned configuration stays refused. There is no envelope shape that
     * omits a signature, so unsigned input is exactly the absent-envelope case
     * — it must never fall through to an "allow because nothing objected" path.
     */
    #[Test]
    public function anEnvelopeSignedByAnUntrustedKeyIsRefused(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope('attacker-secret'));

        $this->expectException(ConfigImportPreflightException::class);
        $this->preflight()->assertReady([], [], false, false, false);
    }

    /**
     * The signature covers the manifest, and the manifest is recomputed from
     * the directory at import time. Editing authored bytes after signing must
     * therefore be caught on the importing host, not trusted because the
     * envelope itself is still internally valid.
     */
    #[Test]
    public function configBytesModifiedAfterSigningAreRefused(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope());
        $this->writeBundle('Tampered');

        $this->expectException(ConfigImportPreflightException::class);
        $this->preflight()->assertReady([], [], false, false, false);
    }

    /**
     * Compatibility is the installed cohort's verdict. A bundle whose owner
     * package is not installed at the contract version it claims cannot be
     * staged, however well signed it is.
     */
    #[Test]
    public function anIncompatiblePackageContractIsRefused(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope());

        $preflight = $this->preflight(compatibility: new ConfigPackageCompatibility([
            ConfigPackageContract::fromComposerManifest([
                'name' => 'waaseyaa/config',
                'extra' => ['waaseyaa' => ['config-contract' => [
                    'schema-provider' => 'Acme\\Example\\SchemaProvider',
                    'version' => 2,
                    'readable_versions' => [1, 2],
                ]]],
            ]),
        ]));

        $this->expectException(ConfigImportPreflightException::class);
        $preflight->assertReady([], [], false, false, false);
    }

    /**
     * Replaying an already-committed bundle would silently reinstate retired
     * content. Rollback has its own separately authorized CFG-02 path.
     */
    #[Test]
    public function areplayedBundleSequenceIsRefused(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope());

        $preflight = $this->preflight(replay: new PreflightTestReplayState(9));

        $this->expectException(ConfigImportPreflightException::class);
        $preflight->assertReady([], [], false, false, false);
    }

    #[Test]
    public function aMalformedSidecarIsRefused(): void
    {
        file_put_contents(ConfigManifestEnvelopeFile::pathFor($this->syncPath), '{"format":"nope"}');

        $this->expectException(ConfigImportPreflightException::class);
        $this->preflight()->assertReady([], [], false, false, false);
    }

    /**
     * A sync directory that is not strictly valid is refused before any
     * signature work, so a diagnostic-bearing bundle can never be staged on the
     * strength of an envelope that happens to verify.
     */
    #[Test]
    public function aBundleWithValidationDiagnosticsIsRefused(): void
    {
        ConfigManifestEnvelopeFile::write($this->syncPath, $this->signedEnvelope());
        file_put_contents($this->syncPath . '/not-a-config-file.txt', 'stray');

        $this->expectException(ConfigImportPreflightException::class);
        $this->preflight()->assertReady([], [], false, false, false);
    }

    private function preflight(
        ?ConfigPackageCompatibility $compatibility = null,
        ?ConfigReplayStateReaderInterface $replay = null,
    ): SignedEnvelopeConfigImportPreflight {
        return new SignedEnvelopeConfigImportPreflight(
            syncPath: $this->syncPath,
            bundleValidator: new ConfigSyncBundleValidator($this->registry),
            registry: $this->registry,
            compatibility: $compatibility ?? $this->compatibility(),
            envelopeVerifier: new ConfigManifestEnvelopeVerifier(),
            signatureVerifier: new PreflightTestSignatureVerifier('trusted-secret'),
            replayState: $replay ?? new PreflightTestReplayState(null),
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

    private function syncFile(string $title): ConfigSyncFile
    {
        $registration = $this->registry->get('waaseyaa.system.site', 1);
        assert($registration !== null);

        return ConfigSyncFile::writable(
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
    }

    private function writeBundle(string $title): void
    {
        $file = $this->syncFile($title);
        file_put_contents(
            $this->syncPath . '/' . $file->filename(),
            new ConfigSyncSerializer()->toYaml($file),
        );
    }

    private function signedEnvelope(string $secret = 'trusted-secret'): SignedConfigManifestEnvelope
    {
        $file = $this->syncFile('Waaseyaa');
        $bytes = new ConfigSyncSerializer()->toYaml($file);
        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            new ConfigSyncBundleValidationResult([
                new ValidatedConfigSyncEntry($file, $bytes, new ConfigContentHasher()->hash($file, $bytes, $this->registry)),
            ], []),
            $this->registry,
            'site:test',
            5,
            ['producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );

        return SignedConfigManifestEnvelope::sign($manifest, new PreflightTestSigner($secret));
    }

}

/** Stands in for CFG-04 custody on the authoring host. */
final readonly class PreflightTestSigner implements ConfigManifestSignerInterface
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

/** Stands in for the importing host's public trust policy. */
final readonly class PreflightTestSignatureVerifier implements ConfigManifestSignatureVerifierInterface
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

final class PreflightTestReplayState implements ConfigReplayStateReaderInterface
{
    public function __construct(private readonly ?int $last) {}

    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
    {
        return $this->last;
    }
}
