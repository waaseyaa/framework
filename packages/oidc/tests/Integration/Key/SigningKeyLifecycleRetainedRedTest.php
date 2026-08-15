<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Key;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;

/** Retained-red proof for the CFG-04 signing-key lifecycle boundary. */
final class SigningKeyLifecycleRetainedRedTest extends TestCase
{
    #[Test]
    public function an_empty_read_refuses_without_generating_or_persisting_a_key(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $repository = $this->repository($database);

        $thrown = null;
        try {
            $repository->currentKey();
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertSame(0, $this->rowCount($database));
    }

    #[Test]
    public function public_signing_key_metadata_never_contains_private_pem(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $repository = $this->repository($database);

        $key = $repository->rotate();
        $publicProperties = array_keys(get_object_vars($key));

        self::assertNotContains('privateKeyPem', $publicProperties);
        self::assertStringNotContainsString('PRIVATE KEY', var_export($key, true));
    }

    #[Test]
    public function a_failed_successor_insert_cannot_rotate_out_the_only_active_key(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $repository = $this->repository($database);
        $active = $repository->rotate();
        $database->getConnection()->executeStatement(
            "CREATE TRIGGER refuse_oidc_successor BEFORE INSERT ON oidc_signing_key BEGIN SELECT RAISE(ABORT, 'synthetic successor refusal'); END",
        );

        try {
            $repository->stageSuccessor();
            self::fail('The synthetic successor insert must fail.');
        } catch (\Throwable) {
        }

        self::assertSame(
            $active->kid,
            $database->getConnection()->fetchOne(
                "SELECT kid FROM oidc_signing_key WHERE state = 'active-sign-and-verify'",
            ),
        );
    }

    #[Test]
    public function normal_rotation_never_prunes_an_unexpired_older_verification_key(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $repository = $this->repository($database);

        $first = $repository->initialize();
        $second = $repository->stageSuccessor();
        $repository->recordPropagation($second->kid, hash('sha256', 'second'));
        $repository->activateSuccessor($second->kid, $first->version);
        $third = $repository->stageSuccessor();
        $repository->recordPropagation($third->kid, hash('sha256', 'third'));
        $repository->activateSuccessor($third->kid, $second->version);

        self::assertSame(3, $this->rowCount($database));
    }

    private function repository(DBALDatabase $database): SigningKeyRepository
    {
        return new SigningKeyRepository(
            database: $database,
            encryptionKey: random_bytes(32),
            lifecyclePolicy: new SigningKeyLifecyclePolicy(
                maximumTokenLifetimeSeconds: 3_600,
                maximumClockSkewSeconds: 0,
                jwksCacheLifetimeSeconds: 0,
                propagationMarginSeconds: 0,
            ),
        );
    }

    private function rowCount(DBALDatabase $database): int
    {
        return (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_signing_key');
    }
}
