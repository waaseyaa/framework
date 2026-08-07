<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalBootstrapReaderInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Auth\Token\Bearer\BearerTokenRecord;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\Auth\Token\Bearer\IssuedBearerToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Mcp\Auth\DurableBearerTokenAuth;
use Waaseyaa\Mcp\Auth\ScopedMcpAuthInterface;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
use Waaseyaa\Mcp\Tests\Support\RecordingLogger;
use Waaseyaa\User\User;

#[CoversClass(DurableBearerTokenAuth::class)]
final class DurableBearerTokenAuthTest extends TestCase
{
    private const string TOKEN = 'mbt_0123456789abcdef.'
        . '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    /** @var \ArrayObject<int, array{token: string, audience: string}> */
    private \ArrayObject $verifyCalls;

    protected function setUp(): void
    {
        $this->verifyCalls = new \ArrayObject();
    }

    private function record(int $uid = 42, array $scopes = ['present guided content']): BearerTokenRecord
    {
        $now = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone('UTC'));

        return new BearerTokenRecord(
            id: 'mbt_0123456789abcdef',
            accountUid: $uid,
            audience: 'mcp:write',
            scopes: $scopes,
            label: 'ci-agent',
            fingerprint: 'aabbccddeeff0011',
            issuedAt: $now,
            expiresAt: $now->modify('+1 hour'),
        );
    }

    private function storeAnswering(?BearerTokenRecord $record): BearerTokenStoreInterface
    {
        return new class($record, $this->verifyCalls) implements BearerTokenStoreInterface {
            /** @param \ArrayObject<int, array{token: string, audience: string}> $calls */
            public function __construct(
                private readonly ?BearerTokenRecord $record,
                private readonly \ArrayObject $calls,
            ) {}

            public function verify(string $presentedToken, string $audience): ?BearerTokenRecord
            {
                $this->calls->append(['token' => $presentedToken, 'audience' => $audience]);

                return $this->record;
            }

            public function issue(int $accountUid, string $audience, array $scopes, ?int $ttlSeconds = null, string $label = ''): IssuedBearerToken
            {
                throw new \LogicException('not exercised');
            }

            public function rotate(string $tokenId, ?int $ttlSeconds = null): IssuedBearerToken
            {
                throw new \LogicException('not exercised');
            }

            public function revoke(string $tokenId): void
            {
                throw new \LogicException('not exercised');
            }

            public function find(string $tokenId): ?BearerTokenRecord
            {
                return null;
            }

            public function all(int $limit = 100): array
            {
                return [];
            }
        };
    }

    private function throwingStore(): BearerTokenStoreInterface
    {
        return new class implements BearerTokenStoreInterface {
            public function verify(string $presentedToken, string $audience): ?BearerTokenRecord
            {
                throw new \RuntimeException('database gone: dsn sqlite://secret');
            }

            public function issue(int $accountUid, string $audience, array $scopes, ?int $ttlSeconds = null, string $label = ''): IssuedBearerToken
            {
                throw new \LogicException('not exercised');
            }

            public function rotate(string $tokenId, ?int $ttlSeconds = null): IssuedBearerToken
            {
                throw new \LogicException('not exercised');
            }

            public function revoke(string $tokenId): void
            {
                throw new \LogicException('not exercised');
            }

            public function find(string $tokenId): ?BearerTokenRecord
            {
                return null;
            }

            public function all(int $limit = 100): array
            {
                return [];
            }
        };
    }

    /**
     * The owner lookup is an ACTIVE-owner query (`uid` + `status = 1`), so a
     * blocked/missing owner is represented by an empty result set — never by
     * an entity whose Protected `status` field would need a read context.
     */
    private function accountsWith(?User $user): EntityRepositoryInterface
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findBy')->willReturn($user !== null ? [$user] : []);

        return $repository;
    }

    private function activeUser(int $uid = 42): User
    {
        $user = new User(['uid' => $uid]);
        $user->enforceIsNew();

        return $user;
    }

    /**
     * A real {@see AccountPrincipalFactory} over a stub audited bootstrap
     * reader: the entity-backed owner snapshots into an immutable principal
     * whose id() is the owner uid.
     */
    private function principals(): AccountPrincipalFactoryInterface
    {
        return new AccountPrincipalFactory(new class implements AuthorizationPrincipalBootstrapReaderInterface {
            public function fromEntity(EntityInterface $account, ?string $tenantId = null, ?string $communityId = null): AuthorizationPrincipalInterface
            {
                return new AuthorizationPrincipal(
                    accountId: (int) $account->id(),
                    authenticated: true,
                    roles: [],
                    permissions: [],
                    claimsGeneration: 'test-generation',
                );
            }
        });
    }

    private function auth(
        BearerTokenStoreInterface $store,
        EntityRepositoryInterface $accounts,
        ?string $audience = null,
        ?RecordingLogger $logger = null,
    ): DurableBearerTokenAuth {
        return new DurableBearerTokenAuth(
            store: $store,
            accounts: $accounts,
            principals: $this->principals(),
            audience: $audience ?? DurableBearerTokenAuth::DEFAULT_AUDIENCE,
            logger: $logger,
        );
    }

    #[Test]
    public function it_is_a_write_tier_and_scope_aware_auth_strategy(): void
    {
        $auth = $this->auth($this->storeAnswering(null), $this->accountsWith(null));

        self::assertInstanceOf(WriteTierAuthInterface::class, $auth);
        self::assertInstanceOf(ScopedMcpAuthInterface::class, $auth);
    }

    #[Test]
    public function a_valid_token_resolves_the_real_owning_account_with_its_scopes(): void
    {
        $user = $this->activeUser(42);
        $auth = $this->auth($this->storeAnswering($this->record(42)), $this->accountsWith($user));

        $principal = $auth->authenticateWithScopes('Bearer ' . self::TOKEN);

        self::assertNotNull($principal);
        self::assertInstanceOf(
            AuthorizationPrincipalInterface::class,
            $principal->account,
            'the owner crosses the audited principal factory',
        );
        self::assertSame(42, (int) $principal->account->id(), 'the principal id IS the owner uid, never a token id');
        self::assertSame(['present guided content'], $principal->scopes);

        self::assertSame(
            [['token' => self::TOKEN, 'audience' => DurableBearerTokenAuth::DEFAULT_AUDIENCE]],
            $this->verifyCalls->getArrayCopy(),
            'the store must be asked for exactly the configured audience',
        );
    }

    #[Test]
    public function the_plain_authenticate_contract_returns_the_same_account(): void
    {
        $user = $this->activeUser(42);
        $auth = $this->auth($this->storeAnswering($this->record(42)), $this->accountsWith($user));

        $account = $auth->authenticate('Bearer ' . self::TOKEN);
        self::assertNotNull($account);
        self::assertSame(42, (int) $account->id());
    }

    #[Test]
    public function absent_empty_and_non_bearer_headers_fail_closed_without_a_store_roundtrip(): void
    {
        $auth = $this->auth($this->storeAnswering($this->record()), $this->accountsWith($this->activeUser()));

        self::assertNull($auth->authenticateWithScopes(null));
        self::assertNull($auth->authenticateWithScopes(''));
        self::assertNull($auth->authenticateWithScopes('Basic dXNlcjpwdw=='));
        self::assertSame([], $this->verifyCalls->getArrayCopy());
    }

    #[Test]
    public function an_unknown_token_fails_closed(): void
    {
        $auth = $this->auth($this->storeAnswering(null), $this->accountsWith($this->activeUser()));

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function a_store_outage_fails_closed_and_logs_no_token_material(): void
    {
        $logger = new RecordingLogger();
        $auth = $this->auth($this->throwingStore(), $this->accountsWith($this->activeUser()), logger: $logger);

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));

        self::assertNotSame([], $logger->records, 'the outage must be diagnosable');
        $log = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $log);
        self::assertStringNotContainsString('dsn sqlite://secret', $log, 'driver detail stays out of the log line');
    }

    #[Test]
    public function a_record_with_no_scopes_is_malformed_and_fails_closed(): void
    {
        $auth = $this->auth($this->storeAnswering($this->record(42, [])), $this->accountsWith($this->activeUser()));

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function a_missing_owner_account_fails_closed(): void
    {
        $auth = $this->auth($this->storeAnswering($this->record(42)), $this->accountsWith(null));

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function a_blocked_owner_account_fails_closed(): void
    {
        // The active-owner query matches no row for a blocked owner.
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['uid' => 42, 'status' => 1], null, 1)
            ->willReturn([]);
        $auth = $this->auth($this->storeAnswering($this->record(42)), $repository);

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function an_account_lookup_failure_fails_closed(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findBy')->willThrowException(new \RuntimeException('user table missing'));
        $auth = $this->auth($this->storeAnswering($this->record(42)), $repository);

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function a_principal_snapshot_failure_fails_closed(): void
    {
        $auth = new DurableBearerTokenAuth(
            store: $this->storeAnswering($this->record(42)),
            accounts: $this->accountsWith($this->activeUser()),
            principals: new AccountPrincipalFactory(bootstrapReader: null),
        );

        self::assertNull($auth->authenticateWithScopes('Bearer ' . self::TOKEN));
    }

    #[Test]
    public function the_audience_is_configurable(): void
    {
        $auth = $this->auth($this->storeAnswering(null), $this->accountsWith(null), audience: 'mcp:custom');

        $auth->authenticateWithScopes('Bearer ' . self::TOKEN);

        self::assertSame([['token' => self::TOKEN, 'audience' => 'mcp:custom']], $this->verifyCalls->getArrayCopy());
    }
}
