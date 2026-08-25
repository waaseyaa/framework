<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Tool;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tests\Support\FakeEntityTypeManager;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\EditTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\EmitBeaconTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\GetTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\RecordTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\ReRecordTrailTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;
use Waaseyaa\Bimaaji\Spec\SpecIndexProvider;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Entity\Trail;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * MCP wire conformance for every agent tool shipped by `waaseyaa/ai-agent`
 * (issue #2520).
 *
 * The MCP `tools/call` result contract admits only the spec's content-block
 * types — `text`, `image`, `audio`, `resource`, `resource_link`. A block of
 * type `json` is not one of them, so a conforming client rejects the whole
 * response and the tool is unusable no matter how correct its payload is.
 * Structured payloads belong in `structuredContent`, mirrored as a JSON `text`
 * block for clients that only read `content` — the in-repo reference is
 * `Waaseyaa\AI\Tools\ContentSearch\ContentSearchTool::execute()`.
 *
 * This suite EXECUTES every tool against real collaborators and inspects the
 * emitted {@see AgentToolResult}; it never reads source. `dryRun()` is
 * exercised alongside `execute()` so a divergent dry-run path cannot slip
 * through. The covered set is cross-checked against a filesystem scan of
 * `src/Tool/`, so a tool added later fails this suite until it is covered.
 */
#[CoversNothing]
final class McpContentBlockConformanceTest extends TestCase
{
    /** Content-block types a conforming MCP client accepts. */
    private const array MCP_BLOCK_TYPES = ['text', 'image', 'audio', 'resource', 'resource_link'];

    /** @var list<string> Temp spec dirs seeded by the SearchSpecsTool factory. */
    private static array $tempDirs = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        self::$tempDirs = [];
    }

    /**
     * Every concrete tool under `src/Tool/` must be exercised below. Without
     * this, a tool added later would silently escape the sweep.
     */
    #[Test]
    public function every_shipped_tool_is_covered_by_this_sweep(): void
    {
        $onDisk = $this->discoverToolClasses();
        self::assertNotEmpty($onDisk, 'Tool discovery found nothing — the scan is broken, not the tools.');

        $covered = [];
        foreach (self::tools() as $case) {
            $covered[] = $case[0];
        }

        sort($onDisk);
        sort($covered);

        self::assertSame(
            $onDisk,
            $covered,
            'A tool under packages/ai-agent/src/Tool/ is not exercised by the MCP conformance sweep. Add it to tools().',
        );
    }

    /**
     * @param class-string $class
     * @param callable(): array{0: AgentToolInterface, 1: array<string, mixed>} $build
     */
    #[Test]
    #[DataProvider('tools')]
    public function execute_emits_only_mcp_content_blocks(string $class, callable $build): void
    {
        [$tool, $arguments] = $build();
        $result = $tool->execute($arguments, $this->permittedAccount($class));

        self::assertFalse(
            $result->isError,
            sprintf('%s::execute() refused the happy-path arguments: %s', $class, $result->summary ?? ''),
        );
        $this->assertConformingResult($class . '::execute()', $result);
    }

    /**
     * `dryRun()` is a separate entrypoint on {@see AgentToolInterface}; a tool
     * that delegates to `execute()` is held to the same bar, and one that does
     * not is checked on its own terms.
     *
     * @param class-string $class
     * @param callable(): array{0: AgentToolInterface, 1: array<string, mixed>} $build
     */
    #[Test]
    #[DataProvider('tools')]
    public function dry_run_emits_only_mcp_content_blocks(string $class, callable $build): void
    {
        [$tool, $arguments] = $build();
        $result = $tool->dryRun($arguments, $this->permittedAccount($class));

        $this->assertNoJsonBlocks($class . '::dryRun()', $result);

        if (!$this->dryRunSupported($class)) {
            // AbstractAgentTool's default refusal — a plain text block.
            self::assertTrue($result->isError, sprintf('%s declares dryRunSupported: false but dryRun() succeeded.', $class));

            return;
        }

        self::assertFalse(
            $result->isError,
            sprintf('%s::dryRun() refused the happy-path arguments: %s', $class, $result->summary ?? ''),
        );
        $this->assertConformingResult($class . '::dryRun()', $result);
    }

    private function assertConformingResult(string $label, AgentToolResult $result): void
    {
        $this->assertNoJsonBlocks($label, $result);

        self::assertNotEmpty($result->content, $label . ' emitted no content blocks.');
        self::assertSame('text', $result->content[0]['type'], $label . ' must mirror its payload as a text block.');
        $text = $result->content[0]['text'] ?? null;
        self::assertIsString($text, $label . ' text block carries no text.');

        self::assertIsArray(
            $result->structuredContent,
            $label . ' must carry its payload in structuredContent, not in a content block.',
        );
        self::assertSame(
            $result->structuredContent,
            json_decode($text, true, 512, JSON_THROW_ON_ERROR),
            $label . ' text block must be the exact JSON encoding of structuredContent.',
        );
    }

    private function assertNoJsonBlocks(string $label, AgentToolResult $result): void
    {
        foreach ($result->content as $index => $block) {
            self::assertContains(
                $block['type'],
                self::MCP_BLOCK_TYPES,
                sprintf('%s content block %d has non-MCP type "%s".', $label, $index, $block['type']),
            );
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: callable(): array{0: AgentToolInterface, 1: array<string, mixed>}}>
     */
    public static function tools(): iterable
    {
        yield 'bimaaji_introspect_graph' => [
            IntrospectGraphTool::class,
            static fn(): array => [new IntrospectGraphTool(self::graphGenerator()), []],
        ];

        yield 'bimaaji_introspect_section' => [
            IntrospectSectionTool::class,
            static fn(): array => [new IntrospectSectionTool(self::graphGenerator()), ['section' => 'entities']],
        ];

        yield 'bimaaji_search_specs' => [
            SearchSpecsTool::class,
            static fn(): array => [new SearchSpecsTool(new SpecIndexProvider(self::seedSpecDir())), ['query' => 'Storage']],
        ];

        yield 'bimaaji_propose_mutation' => [
            ProposeMutationTool::class,
            static fn(): array => [
                new ProposeMutationTool(new MutationValidator(self::graphWithUser())),
                self::mutationArguments(),
            ],
        ];

        yield 'bimaaji_generate_patch' => [
            GeneratePatchTool::class,
            static fn(): array => [
                new GeneratePatchTool(
                    patchGenerator: new PatchGenerator(),
                    validator: new MutationValidator(self::graphWithUser()),
                ),
                self::mutationArguments(),
            ],
        ];

        yield 'wayfinding_record_trail' => [
            RecordTrailTool::class,
            static fn(): array => [new RecordTrailTool(self::trailEntityTypeManager()), self::trailArguments()],
        ];

        yield 'wayfinding_get_trail' => [
            GetTrailTool::class,
            static function (): array {
                [$manager, $trailId] = self::managerWithRecordedTrail();

                return [new GetTrailTool($manager), ['trail_id' => $trailId, 'langcode' => 'en']];
            },
        ];

        yield 'wayfinding_edit_trail' => [
            EditTrailTool::class,
            static function (): array {
                [$manager, $trailId] = self::managerWithRecordedTrail();

                return [
                    new EditTrailTool($manager),
                    ['trail_id' => $trailId, 'langcode' => 'en'] + self::trailArguments(),
                ];
            },
        ];

        yield 'wayfinding_rerecord_trail' => [
            ReRecordTrailTool::class,
            static function (): array {
                [$manager, $trailId] = self::managerWithRecordedTrail();

                return [
                    new ReRecordTrailTool($manager),
                    ['trail_id' => $trailId, 'langcode' => 'en'] + self::trailArguments(),
                ];
            },
        ];

        yield 'wayfinding_emit_beacon' => [
            EmitBeaconTool::class,
            static function (): array {
                $database = DBALDatabase::createSqlite();
                RuntimeSchemaMigrations::broadcast($database);
                $widget = new EntityType(id: 'widget', label: 'Widget', class: \stdClass::class, keys: ['id' => 'id']);
                $tool = new EmitBeaconTool(
                    new AnchorRegistry(new FakeEntityTypeManager([], ['widget' => $widget])),
                    $database,
                );

                return [$tool, ['session_token' => 'tok', 'anchor_id' => 'view:widget', 'content' => 'tip', 'order' => 0]];
            },
        ];
    }

    /** @return list<class-string> */
    private function discoverToolClasses(): array
    {
        $root = \dirname(__DIR__, 3) . '/src/Tool';
        self::assertDirectoryExists($root);

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $class = 'Waaseyaa\\AI\\Agent\\Tool\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(AgentToolInterface::class)) {
                continue;
            }
            $found[] = $class;
        }

        return $found;
    }

    /** @param class-string $class */
    private function dryRunSupported(string $class): bool
    {
        return $this->toolAttribute($class)->dryRunSupported;
    }

    /** @param class-string $class */
    private function permittedAccount(string $class): AccountInterface
    {
        return self::accountGranting($this->toolAttribute($class)->capability);
    }

    /** @param class-string $class */
    private function toolAttribute(string $class): AsAgentTool
    {
        $attributes = new \ReflectionClass($class)->getAttributes(AsAgentTool::class);
        self::assertNotEmpty($attributes, $class . ' carries no #[AsAgentTool] attribute.');

        return $attributes[0]->newInstance();
    }

    private static function accountGranting(string $capability, int $id = 42): AccountInterface
    {
        return new class ($capability, $id) implements AccountInterface {
            public function __construct(
                private readonly string $granted,
                private readonly int $accountId,
            ) {}

            public function id(): int
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === $this->granted;
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

    private static function graphGenerator(): ApplicationGraphGenerator
    {
        $providers = [];
        foreach (['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'] as $key) {
            $providers[] = new class ($key) implements GraphSectionProviderInterface {
                public function __construct(private readonly string $key) {}

                public function getKey(): string
                {
                    return $this->key;
                }

                public function provide(): GraphSection
                {
                    return new GraphSection(key: $this->key, version: '1.0', data: ['entries' => []]);
                }
            };
        }

        return new ApplicationGraphGenerator(providers: $providers);
    }

    private static function graphWithUser(): ApplicationGraph
    {
        return new ApplicationGraph(version: '1.0', sections: [
            new GraphSection(key: 'entities', version: '1.0', data: [
                'user' => ['label' => 'User', 'class' => 'App\\Entity\\User'],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function mutationArguments(): array
    {
        return [
            'operation' => 'add_field',
            'entity_type' => 'user',
            'field' => 'nickname',
            'parameters' => ['type' => 'string'],
        ];
    }

    /** @return array<string, mixed> */
    private static function trailArguments(): array
    {
        return [
            'title' => 'Onboarding',
            'beacons' => [['anchor_id' => 'view:widget', 'content' => 'Start here', 'order' => 0]],
        ];
    }

    /** @return array{0: FakeEntityTypeManager, 1: string} */
    private static function managerWithRecordedTrail(): array
    {
        $manager = self::trailEntityTypeManager();
        $recorded = new RecordTrailTool($manager)->execute(
            self::trailArguments(),
            self::accountGranting(EmitBeaconController::CAPABILITY),
        );

        if ($recorded->isError || !\is_array($recorded->structuredContent)) {
            throw new \RuntimeException('Fixture setup failed: could not record a trail.');
        }

        return [$manager, (string) $recorded->structuredContent['trail_id']];
    }

    private static function trailEntityTypeManager(): FakeEntityTypeManager
    {
        return new FakeEntityTypeManager(['wayfinding_trail' => self::trailRepository()]);
    }

    private static function trailRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Trail::class, revisionable: true, translatable: true, group: 'content');

        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureTranslationRevisionTable();

        $resolver = new SingleConnectionResolver($db);
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver, $entityType->getKeys()['id']),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );
    }

    private static function seedSpecDir(): string
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_mcp_conformance_' . uniqid();
        mkdir($dir, 0o777, true);
        file_put_contents(
            $dir . '/entity-system.md',
            "# Entity System\n\n## Storage\n\nEntities persist via SqlEntityStorage.\n",
        );
        self::$tempDirs[] = $dir;

        return $dir;
    }
}
