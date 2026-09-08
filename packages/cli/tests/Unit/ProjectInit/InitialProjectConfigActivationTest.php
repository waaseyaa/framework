<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\ProjectInit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivation;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivationException;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationGenesisIdentity;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigImportPreflightException;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

#[CoversClass(InitialProjectConfigActivation::class)]
final class InitialProjectConfigActivationTest extends TestCase
{
    private string $root;
    private string $syncPath;
    private ConfigSchemaRegistry $registry;
    private ConfigurationAuthorityContext $authority;
    private InitialActivationReplayState $replay;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_initial_project_activation_' . bin2hex(random_bytes(6));
        $this->syncPath = $this->root . '/config/sync';
        mkdir($this->syncPath, 0o755, true);
        $this->registry = new ConfigSchemaRegistry();
        $this->registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
        ]);
        $this->registry->freeze();
        $this->writeBundle('Community Events');
        $this->authority = new ConfigurationAuthorityContext(
            str_repeat('a', 64),
            'sqlite:' . $this->root . '/app.sqlite',
            $this->syncPath,
            ['config.sync_path'],
        );
        $this->replay = new InitialActivationReplayState();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function activatesFromCanonicalGenesisAndExactRetryIsReadOnly(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay);
        $coordinator = $this->coordinator($activator);

        $first = $coordinator->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);
        $retry = $coordinator->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);

        self::assertSame('completed', $first->status);
        self::assertSame('already_completed', $retry->status);
        self::assertSame(1, $activator->activationCalls);
        self::assertSame($first->token->generationId, $retry->token->generationId);
    }

    #[Test]
    public function retryAfterCommitInterruptionReconcilesWithoutASecondActivation(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay, interruptAfterCommit: true);

        $result = $this->coordinator($activator)->activate(
            $authorization,
            $authorization->siteManifestDigest,
            $authorization->sitePlanDigest,
        );

        self::assertSame('already_completed', $result->status);
        self::assertSame(1, $activator->activationCalls);
    }

    #[Test]
    public function failureBeforeACommitReportsUncertainAndMakesNoRollbackClaim(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay, interruptBeforeCommit: true);

        try {
            $this->coordinator($activator)->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);
            self::fail('Expected an uncertain activation result.');
        } catch (InitialProjectConfigActivationException $exception) {
            self::assertTrue($exception->outcomeUncertain);
            self::assertStringContainsString('retry', $exception->getMessage());
            self::assertSame(0, $activator->rollbackCalls);
        }
    }

    #[Test]
    public function replayRefusesWhenCurrentSyncBytesChangedAfterCommit(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay);
        $coordinator = $this->coordinator($activator);
        $coordinator->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);
        $this->writeBundle('Tampered');

        $this->expectException(ConfigImportPreflightException::class);
        $coordinator->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);
    }

    #[Test]
    public function aPreviouslyCommittedBundleSequenceRefusesBeforeActivation(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay);
        $this->replay->last = 1;

        try {
            $this->coordinator($activator)->activate(
                $authorization,
                $authorization->siteManifestDigest,
                $authorization->sitePlanDigest,
            );
            self::fail('Expected the stale initial bundle to be refused.');
        } catch (ConfigImportPreflightException) {
            self::assertSame(0, $activator->activationCalls);
        }
    }

    #[Test]
    public function anExistingNonGenesisApplicationRefusesBeforeVerificationOrMutation(): void
    {
        $authorization = $this->authorization();
        $activator = new InitialActivationTestActivator($this->authority, $this->replay);
        $activator->current = new ConfigurationActiveToken(str_repeat('f', 64), 9);

        $this->expectException(InitialProjectConfigActivationException::class);
        $this->expectExceptionMessage('existing-application migration is outside');
        $this->coordinator($activator)->activate($authorization, $authorization->siteManifestDigest, $authorization->sitePlanDigest);
    }

    private function coordinator(InitialActivationTestActivator $activator): InitialProjectConfigActivation
    {
        $compatibility = new ConfigPackageCompatibility([
            ConfigPackageContract::fromComposerManifest([
                'name' => 'waaseyaa/config',
                'extra' => ['waaseyaa' => ['config-contract' => [
                    'schema-provider' => 'Acme\\Example\\SchemaProvider',
                    'version' => 1,
                    'readable_versions' => [1],
                ]]],
            ]),
        ]);
        $preflight = new SignedEnvelopeConfigImportPreflight(
            $this->syncPath,
            new ConfigSyncBundleValidator($this->registry),
            $this->registry,
            $compatibility,
            new ConfigManifestEnvelopeVerifier(),
            new InitialActivationSignatureVerifier(),
            $this->replay,
        );

        return new InitialProjectConfigActivation(
            new ConfigSyncRepository($this->syncPath),
            $preflight,
            $activator,
            $this->authority,
        );
    }

    private function authorization(): ProjectConfigAuthorization
    {
        $manifestDigest = str_repeat('b', 64);
        $planDigest = str_repeat('c', 64);
        $file = $this->syncFile('Community Events');
        $bytes = new ConfigSyncSerializer()->toYaml($file);
        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            new ConfigSyncBundleValidationResult([
                new ValidatedConfigSyncEntry($file, $bytes, new ConfigContentHasher()->hash($file, $bytes, $this->registry)),
            ], []),
            $this->registry,
            ProjectConfigAuthorization::scope($manifestDigest, $planDigest),
            1,
            ProjectConfigAuthorization::producerEvidence($manifestDigest, $planDigest),
            ['waaseyaa/config' => 1],
        );

        return ProjectConfigAuthorization::issue(
            $manifestDigest,
            $planDigest,
            SignedConfigManifestEnvelope::sign($manifest, new InitialActivationSigner()),
        );
    }

    private function syncFile(string $title): ConfigSyncFile
    {
        $registration = $this->registry->get('waaseyaa.system.site', 1);
        assert($registration !== null);

        return ConfigSyncFile::writable(
            'system',
            'site',
            ConfigSyncFile::deterministicUuid('system', 'site'),
            [],
            'en',
            ['title' => $title],
            $registration->schemaId,
            $registration->schemaVersion,
            $registration->canonicalSchemaHash,
            $registration->ownerPackage,
            $registration->ownerConfigContractVersion,
        );
    }

    private function writeBundle(string $title): void
    {
        $file = $this->syncFile($title);
        file_put_contents($this->syncPath . '/' . $file->filename(), new ConfigSyncSerializer()->toYaml($file));
    }
}

final class InitialActivationReplayState implements ConfigReplayStateReaderInterface
{
    public ?int $last = null;

    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
    {
        return $this->last;
    }
}

final class InitialActivationTestActivator implements ConfigurationActivatorInterface
{
    public ConfigurationActiveToken $current;
    public int $activationCalls = 0;
    public int $rollbackCalls = 0;
    private ?ConfigurationActivationResult $committed = null;
    /** @var list<ConfigSyncFile> */
    private array $files = [];

    public function __construct(
        private readonly ConfigurationAuthorityContext $authority,
        private readonly InitialActivationReplayState $replay,
        private readonly bool $interruptAfterCommit = false,
        private readonly bool $interruptBeforeCommit = false,
    ) {
        $this->current = ConfigurationGenesisIdentity::token($authority);
    }

    public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
    {
        ++$this->activationCalls;
        if ($this->interruptBeforeCommit) {
            throw new \RuntimeException('simulated interruption before observable commit');
        }
        $bundle = $request->verifiedBundle ?? throw new \LogicException('test requires a verified bundle');
        $this->current = new ConfigurationActiveToken($bundle->effectiveManifest->generationId, 2);
        $this->files = $request->files();
        $this->replay->last = $bundle->verification->bundleSequence;
        $this->committed = new ConfigurationActivationResult(
            'committed',
            $this->current,
            $request->requestId,
            hash('sha256', 'plan'),
            $request->inputHash(),
            $request->expectedToken,
            $request->requestId,
        );
        if ($this->interruptAfterCommit) {
            throw new \RuntimeException('simulated interruption after commit');
        }

        return $this->committed;
    }

    public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
    {
        ++$this->rollbackCalls;
        throw new \LogicException('rollback is outside the initial activation composition');
    }

    public function committedResult(string $requestId): ?ConfigurationActivationResult
    {
        return $this->committed?->requestId === $requestId ? $this->committed : null;
    }

    public function currentToken(): ?ConfigurationActiveToken
    {
        return $this->current;
    }

    public function readGeneration(ConfigurationActiveToken $token): iterable
    {
        if ($token->generationId === $this->current->generationId && $token->activationSequence === $this->current->activationSequence) {
            yield from $this->files;
        }
    }
}

final class InitialActivationSigner implements ConfigManifestSignerInterface
{
    public function algorithm(): string
    {
        return SignedConfigManifestEnvelope::ALGORITHM_V1;
    }
    public function trustKeyReference(): string
    {
        return 'cfg04:test-key';
    }
    public function sign(string $message): string
    {
        return substr(hash_hmac('sha512', $message, 'trusted', true), 0, 64);
    }
}

final class InitialActivationSignatureVerifier implements ConfigManifestSignatureVerifierInterface
{
    public function verify(string $trustKeyReference, string $algorithm, string $message, string $signature): bool
    {
        return $trustKeyReference === 'cfg04:test-key'
            && $algorithm === SignedConfigManifestEnvelope::ALGORITHM_V1
            && hash_equals(substr(hash_hmac('sha512', $message, 'trusted', true), 0, 64), $signature);
    }
}
