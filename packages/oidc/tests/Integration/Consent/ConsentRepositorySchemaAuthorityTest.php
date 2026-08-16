<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Consent\ConsentRepository;

/** Retained-red proof that an ordinary OIDC request cannot install schema. */
#[CoversClass(ConsentRepository::class)]
final class ConsentRepositorySchemaAuthorityTest extends TestCase
{
    #[Test]
    public function missing_schema_is_refused_without_creating_the_consent_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $repository = new ConsentRepository($database);

        try {
            $repository->hasConsent('account-1', 'client-1', ['openid']);
            self::fail('The repository accepted missing schema and self-installed its table.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
        }

        $objects = $database->getConnection()->executeQuery(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'index') AND name = 'oidc_user_consent'",
        )->fetchFirstColumn();

        self::assertSame([], $objects, 'An ordinary repository call created OIDC consent schema.');
    }
}
