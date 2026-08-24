<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\Config\ConfigManifestSignCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Manifest\ConfigManifestBundleSigner;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSigningResult;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;

/**
 * The CFG-03 authoring command (#2430).
 *
 * This is the only command in the framework that mints signing evidence, so its
 * refusals matter as much as its success: a verifier-only host must be told it
 * cannot sign rather than quietly producing something, and a first signature
 * must not invent a bundle scope.
 */
#[CoversClass(ConfigManifestSignCommand::class)]
#[CoversClass(ConfigManifestSigningResult::class)]
final class ConfigManifestSignCommandTest extends TestCase
{
    private string $root;

    private string $syncPath;

    private ConfigSchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_sign_command_' . bin2hex(random_bytes(6));
        $this->syncPath = $this->root . '/config/sync';
        mkdir($this->syncPath, 0o755, true);
        $this->registry = $this->freshRegistry();
        $this->writeBundle();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function it_signs_the_authored_bundle_and_reports_public_evidence(): void
    {
        [$io, $output] = $this->io(['scope' => 'site:test']);

        $exit = new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io);

        self::assertSame(0, $exit);
        $text = $output->fetch();
        self::assertStringContainsString('Signed 1 configuration entry', $text);
        self::assertStringContainsString('scope           site:test', $text);
        self::assertStringContainsString('sequence        1', $text);
        self::assertStringContainsString('contract        waaseyaa/config@1', $text);
        self::assertStringContainsString('Envelope written to', $text);
        self::assertStringContainsString('The signing key stays here.', $text);
        self::assertFileExists(ConfigManifestEnvelopeFile::pathFor($this->syncPath));
    }

    /** The evidence an operator reads must never carry key material. */
    #[Test]
    public function its_output_names_no_secret(): void
    {
        [$io, $output] = $this->io(['scope' => 'site:test']);

        new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io);

        self::assertDoesNotMatchRegularExpression('/private|secret|BEGIN [A-Z ]*KEY/i', $output->fetch());
    }

    /**
     * A profile with no `config_manifest_signing.signing_key` composes no signer.
     * It must refuse rather than degrade: a verifier-only host is not a signing
     * host, and producing anything here would be manufacturing custody.
     */
    #[Test]
    public function it_refuses_on_a_verifier_only_profile(): void
    {
        [$io, $output] = $this->io(['scope' => 'site:test']);

        $exit = new ConfigManifestSignCommand(static fn(): ?ConfigManifestBundleSigner => null, $this->authority())->execute($io);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No configuration manifest signing custody', $output->fetch());
        self::assertFileDoesNotExist(ConfigManifestEnvelopeFile::pathFor($this->syncPath));
    }

    /**
     * Scope identifies the authoring authority for replay purposes. Inventing
     * one for a first signature would silently start a lineage nobody chose.
     */
    #[Test]
    public function it_requires_a_scope_for_a_first_signature(): void
    {
        [$io, $output] = $this->io([]);

        $exit = new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io);

        self::assertSame(1, $exit);
        self::assertStringContainsString('bundle scope is required', $output->fetch());
    }

    /** Later signatures continue the lineage already on disk. */
    #[Test]
    public function it_continues_an_existing_lineage_without_repeating_the_scope(): void
    {
        [$first] = $this->io(['scope' => 'site:test']);
        new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($first);

        [$io, $output] = $this->io([]);
        $exit = new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io);

        self::assertSame(0, $exit);
        $text = $output->fetch();
        self::assertStringContainsString('scope           site:test', $text);
        self::assertStringContainsString('sequence        2', $text, 'A second signature advances the sequence.');
    }

    #[Test]
    public function an_explicit_sequence_overrides_the_derived_one(): void
    {
        [$io, $output] = $this->io(['scope' => 'site:test', 'sequence' => '42']);

        self::assertSame(0, new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io));
        self::assertStringContainsString('sequence        42', $output->fetch());
    }

    /** A directory that fails strict validation is reported, not signed. */
    #[Test]
    public function it_refuses_an_invalid_sync_directory(): void
    {
        file_put_contents($this->syncPath . '/stray.txt', 'not a config file');
        [$io, $output] = $this->io(['scope' => 'site:test']);

        $exit = new ConfigManifestSignCommand($this->signerFactory(), $this->authority())->execute($io);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Refused to sign', $output->fetch());
        self::assertFileDoesNotExist(ConfigManifestEnvelopeFile::pathFor($this->syncPath));
    }

    /** @param array<string, string> $options */
    private function io(array $options): array
    {
        $definition = new InputDefinition([
            new InputOption('scope', null, InputOption::VALUE_REQUIRED),
            new InputOption('sequence', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = [];
        foreach ($options as $name => $value) {
            $input['--' . $name] = $value;
        }
        $output = new BufferedOutput();

        return [new SymfonyCommandIO(new ArrayInput($input, $definition), $output), $output];
    }

    private function authority(): ConfigurationAuthorityContext
    {
        return new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: $this->syncPath,
            selectorProvenance: ['default'],
        );
    }

    /** @return callable(): ?ConfigManifestBundleSigner */
    private function signerFactory(): callable
    {
        return fn(): ConfigManifestBundleSigner => new ConfigManifestBundleSigner(
            new ConfigSyncBundleValidator($this->registry),
            $this->registry,
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
            new SignCommandTestSigner('trusted-secret'),
        );
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

    private function writeBundle(): void
    {
        $registration = $this->registry->get('waaseyaa.system.site', 1);
        assert($registration !== null);
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
        file_put_contents($this->syncPath . '/' . $file->filename(), new ConfigSyncSerializer()->toYaml($file));
    }

}

/** Deterministic stand-in for CFG-04 custody. */
final readonly class SignCommandTestSigner implements ConfigManifestSignerInterface
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
