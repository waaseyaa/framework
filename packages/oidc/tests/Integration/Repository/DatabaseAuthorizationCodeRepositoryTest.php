<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Repository\AuthorizationCode;
use Waaseyaa\Oidc\Repository\DatabaseAuthorizationCodeRepository;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;

#[CoversClass(DatabaseAuthorizationCodeRepository::class)]
#[CoversClass(AuthorizationCode::class)]
final class DatabaseAuthorizationCodeRepositoryTest extends TestCase
{
    private DBALDatabase $database;

    /** @var int Fixed "now" timestamp controlled by the clock below. */
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        OidcSchema::installAuthorizationCodes($this->database);
    }

    public function testIssueReturnsCodeWithExpectedFields(): void
    {
        $repo = $this->buildRepo();

        $code = $repo->issue(
            clientId: 'minoo-web',
            account: $this->account('42'),
            redirectUri: 'https://minoo.test/callback',
            scopes: ['openid', 'profile'],
            codeChallenge: 'ch4lleng3',
            codeChallengeMethod: 'S256',
        );

        self::assertNotEmpty($code->code, 'generated code must not be empty');
        self::assertSame('minoo-web', $code->clientId);
        self::assertSame('42', $code->accountId);
        self::assertSame('https://minoo.test/callback', $code->redirectUri);
        self::assertSame(['openid', 'profile'], $code->scopes);
        self::assertSame('ch4lleng3', $code->codeChallenge);
        self::assertSame('S256', $code->codeChallengeMethod);
        self::assertSame($this->now, $code->issuedAt);
        self::assertSame($this->now + 60, $code->expiresAt, 'OAuth 2.1 mandates 60 s TTL');
        self::assertNull($code->consumedAt);
        self::assertNull($code->nonce, 'nonce defaults to null when not supplied');
    }

    public function testIssueStoresNonceWhenProvided(): void
    {
        $repo = $this->buildRepo();

        $code = $repo->issue(
            clientId: 'minoo-web',
            account: $this->account('42'),
            redirectUri: 'https://minoo.test/callback',
            scopes: ['openid'],
            codeChallenge: 'ch4lleng3',
            codeChallengeMethod: 'S256',
            nonce: 'n-0S6_WzA2Mj',
        );

        self::assertSame('n-0S6_WzA2Mj', $code->nonce);

        $consumed = $repo->consume($code->code);
        self::assertNotNull($consumed);
        self::assertSame('n-0S6_WzA2Mj', $consumed->nonce, 'nonce must survive consume() round-trip');
    }

    public function testConsumeReturnsNullNonceWhenIssuedWithoutNonce(): void
    {
        $repo = $this->buildRepo();

        $issued = $repo->issue('minoo-web', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        $consumed = $repo->consume($issued->code);

        self::assertNotNull($consumed);
        self::assertNull($consumed->nonce);
    }

    public function testIssueProducesUniqueCodesForDistinctCalls(): void
    {
        $repo = $this->buildRepo();

        $a = $repo->issue('minoo-web', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');
        $b = $repo->issue('minoo-web', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        self::assertNotSame($a->code, $b->code);
    }

    public function testConsumeReturnsIssuedCodeOnFirstCall(): void
    {
        $repo = $this->buildRepo();

        $issued = $repo->issue(
            clientId: 'minoo-web',
            account: $this->account('42'),
            redirectUri: 'https://minoo.test/callback',
            scopes: ['openid'],
            codeChallenge: 'ch4lleng3',
            codeChallengeMethod: 'S256',
        );

        $consumed = $repo->consume($issued->code);

        self::assertNotNull($consumed);
        self::assertSame($issued->code, $consumed->code);
        self::assertSame('minoo-web', $consumed->clientId);
        self::assertSame('42', $consumed->accountId);
        self::assertSame('https://minoo.test/callback', $consumed->redirectUri);
        self::assertSame(['openid'], $consumed->scopes);
        self::assertSame('ch4lleng3', $consumed->codeChallenge);
        self::assertSame('S256', $consumed->codeChallengeMethod);
        self::assertSame($this->now, $consumed->consumedAt);
        self::assertNull($consumed->nonce);
    }

    public function testSecondConsumeReturnsNull(): void
    {
        $repo = $this->buildRepo();

        $issued = $repo->issue('minoo-web', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        self::assertNotNull($repo->consume($issued->code));
        self::assertNull($repo->consume($issued->code), 'single-use guarantee');
    }

    public function testConsumeReturnsNullForUnknownCode(): void
    {
        $repo = $this->buildRepo();

        self::assertNull($repo->consume('no-such-code'));
    }

    public function testConsumeReturnsNullForExpiredCode(): void
    {
        $repo = $this->buildRepo();

        $issued = $repo->issue('minoo-web', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        $this->now += 61;

        self::assertNull($repo->consume($issued->code));
    }

    public function testPurgeExpiredRemovesOnlyExpiredRows(): void
    {
        $repo = $this->buildRepo();

        $expired = $repo->issue('a', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        $this->now += 61;

        $fresh = $repo->issue('b', $this->account('2'), 'https://x/y', ['openid'], 'c', 'S256');

        $purged = $repo->purgeExpired();

        self::assertSame(1, $purged);
        self::assertNull($repo->consume($expired->code));
        self::assertNotNull($repo->consume($fresh->code));
    }

    public function testPurgeExpiredReturnsZeroWhenNothingToPurge(): void
    {
        $repo = $this->buildRepo();

        $repo->issue('a', $this->account('1'), 'https://x/y', ['openid'], 'c', 'S256');

        self::assertSame(0, $repo->purgeExpired());
    }

    public function testExplicitMigrationAddsNonceColumnToLegacySchema(): void
    {
        $this->database = DBALDatabase::createSqlite();

        // Simulate a table provisioned by #1283 before nonce was introduced.
        $this->database->query(<<<'SQL'
            CREATE TABLE oidc_authorization_codes (
                code VARCHAR(128) PRIMARY KEY,
                client_id VARCHAR(255) NOT NULL,
                account_id VARCHAR(255) NOT NULL,
                redirect_uri TEXT NOT NULL,
                scopes TEXT NOT NULL,
                code_challenge VARCHAR(128) NOT NULL,
                code_challenge_method VARCHAR(16) NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER
            )
        SQL);

        OidcSchema::installAuthorizationCodes($this->database);

        $repo = $this->buildRepo();

        $issued = $repo->issue(
            clientId: 'minoo-web',
            account: $this->account('42'),
            redirectUri: 'https://minoo.test/callback',
            scopes: ['openid'],
            codeChallenge: 'ch4lleng3',
            codeChallengeMethod: 'S256',
            nonce: 'n-migrated',
        );

        self::assertSame('n-migrated', $issued->nonce);
        $consumed = $repo->consume($issued->code);
        self::assertNotNull($consumed);
        self::assertSame('n-migrated', $consumed->nonce);
    }

    private function buildRepo(): DatabaseAuthorizationCodeRepository
    {
        return new DatabaseAuthorizationCodeRepository(
            database: $this->database,
            clock: fn(): int => $this->now,
        );
    }

    private function account(string $id): AccountInterface
    {
        return new class($id) implements AccountInterface {
            public function __construct(private readonly string $id) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
