<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Preflight;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Content\ContentToolSet;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Preflight\FieldAccessClassificationArtifact;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\AuthenticatedMcpEndpoint;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SpyAuditWriter;
use Waaseyaa\Publishing\Tests\Fixtures\SymfonyTestSanitizer;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;

/**
 * #2143 regression: ONE field-access preflight artifact must stay valid while
 * first-party services materialize their tables lazily on first production
 * use. Pre-fix, the all-table schema fingerprint changed after the first
 * authenticated MCP request (rate-limit table), after the first createDraft
 * (publishing idempotency table), and again after its replay — each next
 * "request" then failed boot with a stale-preflight error.
 *
 * Production-shaped: a real SQLite file shared across independent
 * connections, the real `field-access:preflight --write-artifact` handler,
 * the real write-tier endpoint chain (BearerTokenAuth →
 * CapabilityScopedToolRegistry → per-principal DatabaseRateLimiter →
 * ContentToolSet → ContentPublisher/IdempotencyStore), and the real boot
 * gate (LiveEntitySchemaFingerprint::compute + FieldAccessActivationPreflight
 * ::assertReady — the exact pair AbstractKernel runs at production boot),
 * re-evaluated on a FRESH database connection before every request exactly
 * like a new php-fpm request bootstraps.
 */
#[CoversNothing]
final class LazyTableCreationPreflightStabilityTest extends TestCase
{
    private const string TOKEN = 'preflight-regression-token';
    private const string CAPABILITY = 'publish test articles';

    private string $root;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa-2143-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage', 0o775, true);
        mkdir($this->root . '/.waaseyaa', 0o775, true);
        file_put_contents($this->root . '/VERSION', "2143-regression\n");
        // Site classification artifact (the production mechanism rhtcircle
        // uses): classify the one non-structural live column so the deploy
        // preflight is READY.
        file_put_contents(
            $this->root . '/.waaseyaa/field-access-classification.json',
            json_encode(['fields' => ['test_article|*|title' => 'public']], JSON_THROW_ON_ERROR),
        );
        $this->dbPath = $this->root . '/storage/waaseyaa.sqlite';
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function one_artifact_survives_rate_limiting_create_draft_replay_and_a_later_request(): void
    {
        // --- Deploy phase: materialize entity schema, then write the artifact once.
        $deployDb = $this->connect();
        $entityType = $this->entityType();
        new SqlSchemaHandler($entityType, $deployDb)->ensureTable();
        new SqlSchemaHandler($entityType, $deployDb)->ensureRevisionTable();

        $manager = $this->manager($entityType);
        $handler = new FieldAccessPreflightHandler(
            new DatabaseFieldAccessInventoryScanner($deployDb, $manager),
            $manager,
            projectRoot: $this->root,
        );
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED, '', 'json'),
            new InputOption('write-artifact', null, InputOption::VALUE_NONE),
        ]);
        self::assertSame(0, $handler->execute(new SymfonyCommandIO(
            new ArrayInput(['--write-artifact' => true], $definition),
            new BufferedOutput(),
        )), 'The deploy-time preflight must be READY before the first request.');
        $artifactBytes = (string) file_get_contents($this->root . '/.waaseyaa/field-access-preflight.json');

        // --- Request 1: boot gate passes, authenticated tools/list with rate
        // limiting enabled materializes the rate-limit table on first use.
        $this->assertProductionBootPasses('request 1 (fresh deploy)');
        $db1 = $this->connect();
        $response = $this->serve($db1, $this->rpc('tools/list'));
        $list = $this->decode($response);
        $names = array_map(static fn(array $tool): string => $tool['name'], $list['result']['tools']);
        self::assertContains('article.createDraft', $names);
        self::assertTrue($this->tableExists($db1, 'rate_limits'), 'Rate limiting must have materialized its table lazily.');

        // --- Request 2: the boot AFTER the rate-limit table appeared. Pre-fix
        // this is the first stale-preflight 500. createDraft then materializes
        // the publishing idempotency table on first use.
        $this->assertProductionBootPasses('request 2 (after lazy rate-limit table)');
        $db2 = $this->connect();
        $draft = $this->decode($this->serve($db2, $this->createDraftRpc()));
        self::assertNotTrue($draft['result']['isError'] ?? false, 'createDraft must succeed: ' . json_encode($draft));
        $draftText = $draft['result']['content'][0]['text'];
        self::assertTrue($this->tableExists($db2, 'publishing_idempotency'), 'Idempotency store must have materialized its table lazily.');

        // --- Request 3: boot after the idempotency table appeared, then the
        // byte-identical replay of the same createDraft (same idempotency key).
        $this->assertProductionBootPasses('request 3 (after lazy idempotency table)');
        $db3 = $this->connect();
        $replay = $this->decode($this->serve($db3, $this->createDraftRpc()));
        self::assertNotTrue($replay['result']['isError'] ?? false, 'The identical replay must succeed: ' . json_encode($replay));
        self::assertSame($draftText, $replay['result']['content'][0]['text'], 'The identical replay must return the stored first response.');

        // --- Request 4: a later, independent request — boots and serves.
        $this->assertProductionBootPasses('request 4 (later independent request)');
        $db4 = $this->connect();
        $later = $this->decode($this->serve($db4, $this->rpc('tools/list')));
        self::assertArrayHasKey('tools', $later['result']);

        // The artifact was never regenerated mid-flight.
        self::assertSame(
            $artifactBytes,
            (string) file_get_contents($this->root . '/.waaseyaa/field-access-preflight.json'),
            'The deploy-time artifact must remain byte-identical across all requests.',
        );
    }

    /** The exact boot-gate pair AbstractKernel runs in production, on a fresh connection. */
    private function assertProductionBootPasses(string $stage): void
    {
        $db = $this->connect();
        $version = trim((string) file_get_contents($this->root . '/VERSION'));
        $version = FieldAccessClassificationArtifact::load($this->root)->bindToFrameworkVersion($version);
        $fingerprint = LiveEntitySchemaFingerprint::compute(
            $db,
            array_keys($this->manager($this->entityType())->getDefinitions()),
        );

        try {
            new FieldAccessActivationPreflight()->assertReady($this->root, $version, $fingerprint);
        } catch (\Throwable $error) {
            self::fail(sprintf('Production boot gate failed at %s: %s', $stage, $error->getMessage()));
        }
        self::addToAssertionCount(1);
    }

    private function serve(DBALDatabase $db, string $body): HttpResponse
    {
        $endpoint = $this->writeTier($db);
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], $server, $body);
        $response = $endpoint->serve(new PublisherAccount(permissions: []), $request);
        self::assertSame(200, $response->getStatusCode(), 'Write-tier dispatch must succeed: ' . $response->getContent());

        return $response;
    }

    /** Fresh per-request write-tier chain over the shared SQLite file, rate limiting ON. */
    private function writeTier(DBALDatabase $db): AuthenticatedMcpEndpoint
    {
        $entityType = $this->entityType();
        $resolver = new SingleConnectionResolver($db);
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );
        $publisher = new ContentPublisher(
            $this->descriptor(),
            $repo,
            new IdempotencyStore($db),
            new SpyAuditWriter(),
        );

        $registry = new class implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

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
                return array_values($this->map);
            }
        };
        new ContentToolSet(
            $publisher,
            $this->descriptor(),
            new PreviewLinkService('preflight-regression-preview-secret'),
            static fn(string $id, int $expires, string $signature): string => sprintf('/preview/%s?exp=%d&sig=%s', $id, $expires, $signature),
        )->register($registry, 'article');

        $inner = new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => new PublisherAccount(permissions: [self::CAPABILITY])]),
            agentRegistry: new CapabilityScopedToolRegistry($registry, [self::CAPABILITY]),
            rateLimiter: new DatabaseRateLimiter($db),
            rateLimitMaxRequests: 50,
            rateLimitWindowSeconds: 60,
            rateLimitTier: 'write',
        );

        return new AuthenticatedMcpEndpoint($inner);
    }

    private function createDraftRpc(): string
    {
        return $this->rpc('tools/call', [
            'name' => 'article.createDraft',
            'arguments' => [
                'idempotency_key' => 'regression-2143-draft',
                'values' => [
                    'slug' => 'preflight-stability-draft',
                    'title' => 'Preflight stability draft',
                    'summary' => 'Lazy tables must not stale the deployment preflight.',
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = []): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('error', $decoded, 'JSON-RPC error: ' . $response->getContent());

        return $decoded;
    }

    private function connect(): DBALDatabase
    {
        return DBALDatabase::createSqlite($this->dbPath);
    }

    private function tableExists(DBALDatabase $db, string $table): bool
    {
        $rows = iterator_to_array($db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $db->getConnection()->quote($table),
        ), false);

        return $rows !== [];
    }

    private function entityType(): EntityType
    {
        return new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
    }

    private function manager(EntityType $entityType): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $manager->registerEntityType($entityType);

        return $manager;
    }

    private function descriptor(): ContentTypeDescriptor
    {
        return new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true, maxLength: 100),
                'title' => new FieldSpec(type: 'string', required: true, maxLength: 200),
                'summary' => new FieldSpec(type: 'text'),
                'body_html' => new FieldSpec(type: 'text', html: true),
            ],
            htmlSanitizer: new SymfonyTestSanitizer(['p', 'strong']),
            validators: [],
            publishCapability: self::CAPABILITY,
        );
    }
}
