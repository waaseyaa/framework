<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

#[CoversClass(ConfigManifestEnvelopeFile::class)]
final class ConfigManifestEnvelopeFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_envelope_file_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config/sync', 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * The envelope is a sibling of the sync directory, never a member of it.
     * ConfigSyncBundleValidator is strict and complete — every file inside the
     * directory must be a valid versioned config sync file — so an envelope
     * placed inside would fail the very bundle it authorizes.
     */
    #[Test]
    public function theEnvelopePathIsASiblingOfTheSyncDirectory(): void
    {
        self::assertSame(
            $this->root . '/config/sync.envelope.json',
            ConfigManifestEnvelopeFile::pathFor($this->root . '/config/sync'),
        );
        self::assertSame(
            $this->root . '/config/sync.envelope.json',
            ConfigManifestEnvelopeFile::pathFor($this->root . '/config/sync/'),
            'A trailing separator must not produce a different sidecar.',
        );
    }

    #[Test]
    public function anAbsentEnvelopeReadsAsNullRatherThanAnError(): void
    {
        self::assertNull(ConfigManifestEnvelopeFile::read($this->root . '/config/sync'));
    }

    #[Test]
    public function aWrittenEnvelopeReadsBackIdentically(): void
    {
        $envelope = $this->envelope();

        $path = ConfigManifestEnvelopeFile::write($this->root . '/config/sync', $envelope);

        self::assertSame($this->root . '/config/sync.envelope.json', $path);
        $read = ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
        self::assertNotNull($read);
        self::assertSame($envelope->toArray(), $read->toArray());
    }

    /**
     * A partially written sidecar must never be readable: an interrupted
     * authoring run would otherwise leave a truncated envelope that fails
     * verification in a way that looks like tampering.
     */
    #[Test]
    public function writingLeavesNoTemporaryFileBesideTheEnvelope(): void
    {
        ConfigManifestEnvelopeFile::write($this->root . '/config/sync', $this->envelope());

        $siblings = array_values(array_diff((array) scandir($this->root . '/config'), ['.', '..']));
        sort($siblings, \SORT_STRING);

        self::assertSame(['sync', 'sync.envelope.json'], $siblings);
    }

    #[Test]
    public function malformedEnvelopeBytesAreRefused(): void
    {
        file_put_contents($this->root . '/config/sync.envelope.json', '{not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/envelope/i');
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    #[Test]
    public function anEnvelopeDocumentThatIsNotAnObjectIsRefused(): void
    {
        file_put_contents($this->root . '/config/sync.envelope.json', '["signed"]');

        $this->expectException(\RuntimeException::class);
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    /**
     * A symlinked sidecar is refused for the same reason bundle members are:
     * the verified path must read the exact bytes an operator reviewed, not
     * whatever a link happens to point at when import runs.
     */
    #[Test]
    public function aSymlinkedEnvelopeIsRefused(): void
    {
        file_put_contents($this->root . '/elsewhere.json', json_encode(
            $this->envelope()->toArray(),
            JSON_THROW_ON_ERROR,
        ));
        symlink($this->root . '/elsewhere.json', $this->root . '/config/sync.envelope.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/link/i');
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    #[Test]
    public function anEmptyEnvelopeFileIsRefused(): void
    {
        file_put_contents($this->root . '/config/sync.envelope.json', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unreadable or empty/i');
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    #[Test]
    public function aDirectoryWhereTheEnvelopeBelongsIsRefused(): void
    {
        mkdir($this->root . '/config/sync.envelope.json', 0o755, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a regular file/i');
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    /** A well-formed JSON object that is not an envelope is still refused. */
    #[Test]
    public function aJsonObjectThatIsNotAnEnvelopeIsRefused(): void
    {
        file_put_contents($this->root . '/config/sync.envelope.json', '{"format":"something-else"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed/i');
        ConfigManifestEnvelopeFile::read($this->root . '/config/sync');
    }

    #[Test]
    public function writingIntoAMissingDirectoryIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        ConfigManifestEnvelopeFile::write($this->root . '/absent/sync', $this->envelope());
    }

    #[Test]
    public function writingThroughASymbolicLinkIsRefused(): void
    {
        symlink($this->root . '/elsewhere.json', $this->root . '/config/sync.envelope.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/symbolic link/i');
        ConfigManifestEnvelopeFile::write($this->root . '/config/sync', $this->envelope());
    }

    private function envelope(): SignedConfigManifestEnvelope
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
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
        $bytes = new ConfigSyncSerializer()->toYaml($file);
        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            new ConfigSyncBundleValidationResult([
                new ValidatedConfigSyncEntry($file, $bytes, new ConfigContentHasher()->hash($file, $bytes, $registry)),
            ], []),
            $registry,
            'site:test',
            1,
            ['producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );

        return SignedConfigManifestEnvelope::sign($manifest, new EnvelopeFileTestSigner('test-secret'));
    }

}

/** Deterministic stand-in for CFG-04 custody; the private key never leaves the test. */
final readonly class EnvelopeFileTestSigner implements ConfigManifestSignerInterface
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
