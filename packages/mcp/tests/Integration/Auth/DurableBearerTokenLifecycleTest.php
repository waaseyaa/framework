<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalBootstrapReaderInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Mcp\Auth\DurableBearerTokenAuth;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\User\User;
use Waaseyaa\Testing\Clock\MutableEntityClock;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

/**
 * End-to-end #2177 F3 lifecycle: a real sqlite-backed token store composed
 * with {@see DurableBearerTokenAuth} and a scope-enforcing {@see McpEndpoint}.
 * Issue → authenticate (audience + scope enforced) → rotate (old dies, new
 * lives) → revoke (immediate 401). No plaintext ever reaches storage.
 */
#[CoversNothing]
final class DurableBearerTokenLifecycleTest extends TestCase
{
    public const string START = '2026-08-03 10:00:00.000000';

    private TemporarySqliteDatabase $databaseFixture;

    private DatabaseInterface $database;

    private MutableEntityClock $clock;

    private DatabaseBearerTokenStore $store;

    private McpEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->databaseFixture = new TemporarySqliteDatabase();
        $this->database = $this->databaseFixture->database();
        $this->clock = new MutableEntityClock(new \DateTimeImmutable(self::START, new \DateTimeZone('UTC')));
        $this->store = new DatabaseBearerTokenStore($this->database, $this->clock);

        $owner = new User(['uid' => 42]);
        $owner->enforceIsNew();
        $accounts = $this->createMock(EntityRepositoryInterface::class);
        $accounts->method('findBy')
            ->willReturnCallback(static fn(array $criteria): array => $criteria === ['uid' => 42, 'status' => 1] ? [$owner] : []);

        $principals = new AccountPrincipalFactory(new class implements AuthorizationPrincipalBootstrapReaderInterface {
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

        $this->endpoint = new McpEndpoint(
            auth: new DurableBearerTokenAuth($this->store, $accounts, $principals),
            agentRegistry: $this->registry(),
        );
    }

    protected function tearDown(): void
    {
        $this->databaseFixture->remove();
        parent::tearDown();
    }

    private function dispatch(?string $bearer, array $payload): array
    {
        $headers = $bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer] : [];
        $headers += [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));

        $account = $this->createStub(AccountInterface::class);
        $response = $this->endpoint->handle($account, $request);

        return ['status' => $response->statusCode, 'body' => json_decode($response->body, true, 32, JSON_THROW_ON_ERROR)];
    }

    /** @return list<string> */
    private function toolsVisibleWith(string $bearer): array
    {
        $result = $this->dispatch($bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertSame(200, $result['status']);

        return array_map(static fn(array $t): string => $t['name'], $result['body']['result']['tools'] ?? []);
    }

    #[Test]
    public function the_full_lifecycle_holds_at_the_endpoint(): void
    {
        // Issue: scoped to ONE of the two registered capabilities.
        $issued = $this->store->issue(42, 'mcp:write', ['wayfinding'], 3600, 'lifecycle');

        self::assertSame(['guide.publish'], $this->toolsVisibleWith($issued->secret), 'scope intersection holds');

        // A token issued for another audience never authenticates here.
        $foreign = $this->store->issue(42, 'other-service', ['wayfinding'], 3600, 'foreign');
        self::assertSame(
            401,
            $this->dispatch($foreign->secret, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['status'],
        );

        // Rotation: predecessor dies, successor lives, scopes carry over.
        $rotated = $this->store->rotate($issued->record->id);
        self::assertSame(
            401,
            $this->dispatch($issued->secret, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['status'],
            'the rotated-out secret must be dead immediately',
        );
        self::assertSame(['guide.publish'], $this->toolsVisibleWith($rotated->secret));

        // Expiry boundary: at the instant, dead.
        $this->clock->advance(new \DateInterval('PT3599S'));
        self::assertSame(['guide.publish'], $this->toolsVisibleWith($rotated->secret));
        $this->clock->advance(new \DateInterval('PT1S'));
        self::assertSame(
            401,
            $this->dispatch($rotated->secret, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['status'],
        );

        // Revocation is immediate.
        $this->clock->set(new \DateTimeImmutable(self::START, new \DateTimeZone('UTC')));
        $fresh = $this->store->issue(42, 'mcp:write', ['wayfinding'], 3600, '');
        self::assertSame(['guide.publish'], $this->toolsVisibleWith($fresh->secret));
        $this->store->revoke($fresh->record->id);
        self::assertSame(
            401,
            $this->dispatch($fresh->secret, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['status'],
        );

        // Adversarial: no plaintext secret ever reached the database.
        $dump = json_encode(
            iterator_to_array($this->database->query('SELECT * FROM auth_bearer_token')),
            JSON_THROW_ON_ERROR,
        );
        foreach ([$issued, $foreign, $rotated, $fresh] as $credential) {
            $secretHalf = substr($credential->secret, strpos($credential->secret, '.') + 1);
            self::assertStringNotContainsString($secretHalf, $dump);
        }
    }

    private function registry(): AgentToolRegistryInterface
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Lifecycle test tool.';
            }
        };

        $tools = [
            new AgentTool(
                name: 'guide.publish',
                capability: 'wayfinding',
                destructive: false,
                dryRunSupported: false,
                category: 'test',
                inputSchema: ['type' => 'object', 'properties' => []],
                impl: $impl,
            ),
            new AgentTool(
                name: 'entity.delete',
                capability: 'entity-admin',
                destructive: false,
                dryRunSupported: false,
                category: 'test',
                inputSchema: ['type' => 'object', 'properties' => []],
                impl: $impl,
            ),
        ];

        return new class($tools) implements AgentToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                yield from array_values($this->map);
            }
        };
    }
}
