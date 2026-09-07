<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Oidc\Exception\AmbiguousClientIdException;
use Waaseyaa\Oidc\Exception\DuplicateClientIdException;

/** Operator recovery must identify the conflicting public identities and real migration runner. */
#[CoversClass(AmbiguousClientIdException::class)]
#[CoversClass(DuplicateClientIdException::class)]
final class ClientIdentityRecoveryContractTest extends TestCase
{
    public function testAmbiguousLookupReportsEveryMatchingRowAndMigrationRecovery(): void
    {
        $error = new AmbiguousClientIdException('studio', ['17', '42']);

        self::assertSame('oidc_client_id_ambiguous', $error->errorCode);
        self::assertSame('studio', $error->clientId);
        self::assertSame(['17', '42'], $error->matchingIds);
        self::assertStringContainsString('2 rows match (ids: 17, 42)', $error->getMessage());
        self::assertStringContainsString('reconcile the underlying duplicate rows', $error->getMessage());
        self::assertStringContainsString('bin/waaseyaa migrate', $error->getMessage());
        self::assertStringNotContainsString('schema:sync', $error->getMessage());
    }

    public function testMigrationRefusalPreservesIdentitiesAndDoesNotClaimAutomaticRepair(): void
    {
        $error = new DuplicateClientIdException(['studio', 'operator']);

        self::assertSame('oidc_client_id_duplicates', $error->errorCode);
        self::assertSame(['studio', 'operator'], $error->duplicateClientIds);
        self::assertStringContainsString('2 client_id value(s)', $error->getMessage());
        self::assertStringContainsString('studio, operator', $error->getMessage());
        self::assertStringContainsString('No row was deleted or merged automatically.', $error->getMessage());
        self::assertStringContainsString('bin/waaseyaa migrate', $error->getMessage());
        self::assertStringNotContainsString('schema:sync', $error->getMessage());
    }
}
