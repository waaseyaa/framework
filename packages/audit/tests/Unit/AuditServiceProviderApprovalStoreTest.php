<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * #2177 F1 slice B: `AuditServiceProvider` wires the durable approval store —
 * `OperationApprovalStoreInterface` → `DatabaseOperationApprovalStore`, with the
 * `mcp_approval_event` schema ensured lazily on first resolution so a
 * deployment that never uses the write tier pays nothing at boot.
 */
final class AuditServiceProviderApprovalStoreTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    /** @param array<string, mixed> $config */
    private function provider(array $config = []): AuditServiceProvider
    {
        $provider = new AuditServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class ($this->database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();

        return $provider;
    }

    private function tuple(): ApprovalTuple
    {
        return ApprovalTuple::forCall('7', 'mcp.write', 'article.publish', ['id' => 'a1']);
    }

    #[Test]
    public function the_provider_binds_the_database_approval_store(): void
    {
        $store = $this->provider()->resolve(OperationApprovalStoreInterface::class);

        self::assertInstanceOf(DatabaseOperationApprovalStore::class, $store);
    }

    #[Test]
    public function the_schema_is_ensured_lazily_so_the_store_is_usable_on_first_resolution(): void
    {
        // register() must NOT have created the table; only resolution may.
        $provider = $this->provider();
        self::assertSame(
            [],
            iterator_to_array($this->database->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'mcp_approval_event'",
            )),
            'The approval schema must not be created at register() time.',
        );

        $store = $provider->resolve(OperationApprovalStoreInterface::class);
        assert($store instanceof OperationApprovalStoreInterface);

        // A real open() proves the lazily-ensured schema actually exists.
        $request = $store->open($this->tuple(), 'abcdef0123456789', ['id' => 'a1']);

        self::assertSame($request->id, $store->find($request->id)?->id);
    }

    #[Test]
    public function the_ttl_defaults_to_the_store_default_when_no_config_is_supplied(): void
    {
        $store = $this->provider()->resolve(OperationApprovalStoreInterface::class);
        assert($store instanceof OperationApprovalStoreInterface);

        $request = $store->open($this->tuple(), 'abcdef0123456789', []);

        self::assertSame(
            DatabaseOperationApprovalStore::DEFAULT_TTL_SECONDS,
            $request->expiresAt->getTimestamp() - $request->requestedAt->getTimestamp(),
        );
    }

    #[Test]
    public function a_configured_ttl_reaches_the_store(): void
    {
        $store = $this->provider([
            'mcp' => ['write_tier' => ['approval' => ['ttl_seconds' => 120]]],
        ])->resolve(OperationApprovalStoreInterface::class);
        assert($store instanceof OperationApprovalStoreInterface);

        $request = $store->open($this->tuple(), 'abcdef0123456789', []);

        self::assertSame(120, $request->expiresAt->getTimestamp() - $request->requestedAt->getTimestamp());
    }

    /** An integer-shaped string is what YAML/env config actually carries. */
    #[Test]
    public function an_integer_string_ttl_is_accepted(): void
    {
        $store = $this->provider([
            'mcp' => ['write_tier' => ['approval' => ['ttl_seconds' => '300']]],
        ])->resolve(OperationApprovalStoreInterface::class);
        assert($store instanceof OperationApprovalStoreInterface);

        $request = $store->open($this->tuple(), 'abcdef0123456789', []);

        self::assertSame(300, $request->expiresAt->getTimestamp() - $request->requestedAt->getTimestamp());
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedTtls(): array
    {
        return [
            'zero' => [0],
            'negative' => [-60],
            'zero string' => ['0'],
            'negative string' => ['-60'],
            'float' => [1.5],
            'numeric-float string' => ['1.5'],
            'word' => ['fifteen minutes'],
            'empty string' => [''],
            'bool' => [true],
            'null' => [null],
            'list' => [[900]],
        ];
    }

    /**
     * The TTL bounds how long a standing approval can authorize a destructive
     * call, so a malformed value is refused at wiring rather than guessed.
     */
    #[Test]
    #[DataProvider('malformedTtls')]
    public function a_malformed_ttl_fails_closed_at_resolution(mixed $ttl): void
    {
        $provider = $this->provider([
            'mcp' => ['write_tier' => ['approval' => ['ttl_seconds' => $ttl]]],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.write_tier\.approval\.ttl_seconds/');

        $provider->resolve(OperationApprovalStoreInterface::class);
    }
}
