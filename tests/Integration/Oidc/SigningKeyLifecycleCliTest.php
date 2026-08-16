<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Oidc\EmergencyRevokeSigningKeyCommand;
use Waaseyaa\CLI\Command\Oidc\SigningKeyLifecycleCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Key\SigningKeyEmergencyRevocationService;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;

/** Drives the gated CFG-04 signing-key lifecycle through the operator CLI surface. */
final class SigningKeyLifecycleCliTest extends TestCase
{
    private DBALDatabase $database;
    private SigningKeyRepository $repository;
    private SigningKeyLifecycleCommand $lifecycle;
    private EmergencyRevokeSigningKeyCommand $emergencyRevoke;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($this->database);
        OidcSchema::installTokenStorage($this->database);
        $this->repository = new SigningKeyRepository(
            database: $this->database,
            encryptionKey: random_bytes(32),
            lifecyclePolicy: new SigningKeyLifecyclePolicy(
                maximumTokenLifetimeSeconds: 3_600,
                maximumClockSkewSeconds: 0,
                jwksCacheLifetimeSeconds: 0,
                propagationMarginSeconds: 0,
            ),
        );
        $this->lifecycle = new SigningKeyLifecycleCommand($this->repository);
        $this->emergencyRevoke = new EmergencyRevokeSigningKeyCommand(
            new SigningKeyEmergencyRevocationService(
                $this->database,
                $this->repository,
                $this->repository->lifecyclePolicy(),
            ),
        );
    }

    #[Test]
    public function unconfirmed_lifecycle_mutations_are_refused_before_touching_custody(): void
    {
        $refusals = [
            'oidc:init-signing-key' => $this->lifecycle->initialize($initIo = new CapturingSymfonyCommandIO()),
            'oidc:stage-signing-key' => $this->lifecycle->stage($stageIo = new CapturingSymfonyCommandIO()),
            'oidc:record-signing-key-propagation' => $this->lifecycle->recordPropagation(
                $recordIo = new CapturingSymfonyCommandIO(['kid' => 'k', 'evidence-hash' => hash('sha256', 'e')]),
            ),
            'oidc:activate-signing-key' => $this->lifecycle->activate(
                $activateIo = new CapturingSymfonyCommandIO(['kid' => 'k', 'expected-active-version' => '1']),
            ),
            'oidc:cleanup-signing-keys' => $this->lifecycle->cleanup($cleanupIo = new CapturingSymfonyCommandIO()),
        ];

        self::assertSame(array_fill_keys(array_keys($refusals), 1), $refusals);
        foreach ([$initIo, $stageIo, $recordIo, $activateIo, $cleanupIo] as $io) {
            self::assertStringContainsString('requires --confirm', $io->stderr());
        }
        self::assertSame(0, $this->keyCount());
    }

    #[Test]
    public function the_confirmed_operator_surface_drives_a_full_staged_succession(): void
    {
        $initIo = new CapturingSymfonyCommandIO(['confirm' => true]);
        self::assertSame(0, $this->lifecycle->initialize($initIo));
        self::assertStringContainsString('Initialized active signing key kid=', $initIo->stdout());
        $active = $this->repository->currentKey();

        $stageIo = new CapturingSymfonyCommandIO(['confirm' => true]);
        self::assertSame(0, $this->lifecycle->stage($stageIo));
        self::assertStringContainsString('Published staged verify-only key kid=', $stageIo->stdout());
        $stagedKid = $this->stagedKid();

        $recordIo = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'kid' => $stagedKid,
            'evidence-hash' => hash('sha256', 'jwks-propagation-evidence'),
        ]);
        self::assertSame(0, $this->lifecycle->recordPropagation($recordIo));
        self::assertStringContainsString(
            sprintf('Recorded propagation evidence for kid=%s', $stagedKid),
            $recordIo->stdout(),
        );

        $activateIo = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'kid' => $stagedKid,
            'expected-active-version' => (string) $active->version,
        ]);
        self::assertSame(0, $this->lifecycle->activate($activateIo));
        self::assertStringContainsString(sprintf('Activated signing key kid=%s', $stagedKid), $activateIo->stdout());
        self::assertSame($stagedKid, $this->repository->currentKey()->kid);

        $cleanupIo = new CapturingSymfonyCommandIO(['confirm' => true]);
        self::assertSame(0, $this->lifecycle->cleanup($cleanupIo));
        self::assertStringContainsString('Removed 0 policy-expired retired signing key(s).', $cleanupIo->stdout());
        self::assertSame(2, $this->keyCount());
    }

    #[Test]
    public function activation_requires_a_kid_and_a_positive_integer_version_fence(): void
    {
        $missingKid = new CapturingSymfonyCommandIO(['confirm' => true, 'expected-active-version' => '1']);
        self::assertSame(1, $this->lifecycle->activate($missingKid));
        self::assertStringContainsString('Option --kid is required.', $missingKid->stderr());

        $garbageFence = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'kid' => 'some-kid',
            'expected-active-version' => 'latest',
        ]);
        self::assertSame(1, $this->lifecycle->activate($garbageFence));
        self::assertStringContainsString(
            'Option --expected-active-version must be a positive integer.',
            $garbageFence->stderr(),
        );
        self::assertSame(0, $this->keyCount());
    }

    #[Test]
    public function propagation_evidence_requires_kid_and_evidence_hash_options(): void
    {
        $missingKid = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'evidence-hash' => hash('sha256', 'evidence'),
        ]);
        self::assertSame(1, $this->lifecycle->recordPropagation($missingKid));
        self::assertStringContainsString('Option --kid is required.', $missingKid->stderr());

        $missingEvidence = new CapturingSymfonyCommandIO(['confirm' => true, 'kid' => 'some-kid']);
        self::assertSame(1, $this->lifecycle->recordPropagation($missingEvidence));
        self::assertStringContainsString('Option --evidence-hash is required.', $missingEvidence->stderr());
        self::assertSame(0, $this->keyCount());
    }

    #[Test]
    public function custody_conflicts_surface_as_operator_errors_not_exceptions(): void
    {
        $prematureStage = new CapturingSymfonyCommandIO(['confirm' => true]);
        self::assertSame(1, $this->lifecycle->stage($prematureStage));
        self::assertStringContainsString('active signing key', $prematureStage->stderr());

        self::assertSame(0, $this->lifecycle->initialize(new CapturingSymfonyCommandIO(['confirm' => true])));
        $reinitialize = new CapturingSymfonyCommandIO(['confirm' => true]);
        self::assertSame(1, $this->lifecycle->initialize($reinitialize));
        self::assertStringContainsString('empty lifecycle', $reinitialize->stderr());
        self::assertSame(1, $this->keyCount());
    }

    #[Test]
    public function emergency_revocation_requires_confirm_and_the_full_request_identity(): void
    {
        $unconfirmed = new CapturingSymfonyCommandIO([
            'request-id' => 'compromise-0001',
            'kid' => 'some-kid',
            'actor' => 'operator',
            'reason' => 'compromise',
        ]);
        self::assertSame(1, $this->emergencyRevoke->execute($unconfirmed));
        self::assertStringContainsString('not ordinary rotation', $unconfirmed->stderr());

        $missingReason = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'request-id' => 'compromise-0001',
            'kid' => 'some-kid',
            'actor' => 'operator',
        ]);
        self::assertSame(1, $this->emergencyRevoke->execute($missingReason));
        self::assertStringContainsString('Option --reason is required.', $missingReason->stderr());
        self::assertSame(0, $this->revocationCount());
    }

    #[Test]
    public function a_confirmed_emergency_revocation_revokes_the_active_key(): void
    {
        $key = $this->repository->initialize();

        $io = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'request-id' => 'compromise-cli-0001',
            'kid' => $key->kid,
            'actor' => 'cli-test-operator',
            'reason' => 'cli compromise proof',
        ]);
        self::assertSame(0, $this->emergencyRevoke->execute($io));
        self::assertStringContainsString(
            sprintf('Emergency revocation request=compromise-cli-0001 kid=%s version=%d', $key->kid, $key->version),
            $io->stdout(),
        );
        self::assertSame(1, $this->revocationCount());

        try {
            $this->repository->currentKey();
            self::fail('A revoked active key must immediately lose signing authority.');
        } catch (\RuntimeException) {
        }
    }

    #[Test]
    public function an_unknown_kid_fails_emergency_revocation_without_ledger_evidence(): void
    {
        $this->repository->initialize();

        $io = new CapturingSymfonyCommandIO([
            'confirm' => true,
            'request-id' => 'compromise-cli-0002',
            'kid' => 'unknown-kid',
            'actor' => 'cli-test-operator',
            'reason' => 'cli compromise proof',
        ]);
        self::assertSame(1, $this->emergencyRevoke->execute($io));
        self::assertStringContainsString('absent or already revoked', $io->stderr());
        self::assertSame(0, $this->revocationCount());
    }

    private function keyCount(): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_signing_key');
    }

    private function revocationCount(): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_signing_key_revocation');
    }

    private function stagedKid(): string
    {
        return (string) $this->database->getConnection()->fetchOne(
            "SELECT kid FROM oidc_signing_key WHERE state = 'staged-verify-only'",
        );
    }
}
