<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Listing;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * #2167, production-shaped: a **booted kernel** must hand the Listing pipeline
 * a `RequestContext` carrying the live request's query parameters.
 *
 * The unit coverage in
 * `packages/foundation/tests/Unit/Http/RequestContextQueryParametersTest` proves
 * the kernel *builds* the right object. This proves the object actually
 * *arrives*: through `ProviderRegistry`, into the kernel-services bus, past the
 * listing ServiceProvider's own local binding, and out of
 * `ListingResolver::resolve()` as a different page of rows.
 *
 * That distinction is the whole defect. Before the fix the kernel could have
 * built a perfect context and it would still never have reached the resolver,
 * because `ServiceProvider::resolve()` checks local bindings **before** the
 * bus — so the provider's anonymous `new RequestContext()` won every time. A
 * test that injected `queryParams` into a hand-built resolver would have passed
 * throughout and proven nothing.
 *
 * Runs in a subprocess against a real project root, so `$_GET` and kernel boot
 * are the genuine article rather than a fixture.
 */
#[CoversNothing]
final class KernelBoundRequestContextTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_reqctx_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        $this->writeAutoloadWrapper();

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/request-context-regression',
            'autoload' => ['psr-4' => ['Waaseyaa\\Tests\\' => $this->repoRoot . '/tests/']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");

        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'local',
                'app' => ['url' => 'http://localhost', 'name' => 'RequestContext regression'],
            ];
            PHP);
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite($databasePath, 'local');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::audit($database);
        $database->getConnection()->close();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isFile() || $item->isLink() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    // ------------------------------------------------------------------

    #[Test]
    public function a_booted_http_kernel_delivers_the_query_to_the_listing_provider(): void
    {
        $report = $this->probe(['page' => '2', 'q' => 'water']);

        self::assertTrue($report['resolved'], 'a provider must be able to resolve RequestContext');
        self::assertSame(
            ['page' => '2', 'q' => 'water'],
            $report['query'],
            'the live query must survive ProviderRegistry, the kernel-services bus, '
            . 'and the listing provider’s own local binding',
        );
    }

    #[Test]
    public function an_empty_query_still_yields_a_usable_context(): void
    {
        $report = $this->probe([]);

        self::assertTrue($report['resolved']);
        self::assertSame([], $report['query']);
    }

    #[Test]
    public function separate_kernel_boots_cannot_leak_query_state(): void
    {
        // Each probe is its own process, which is the strongest form of the
        // isolation claim: nothing from one request can reach the next.
        self::assertSame(['page' => '7'], $this->probe(['page' => '7'])['query']);
        self::assertSame(['page' => '1'], $this->probe(['page' => '1'])['query']);
        self::assertSame([], $this->probe([])['query']);
    }

    #[Test]
    public function the_console_kernel_keeps_the_anonymous_default(): void
    {
        // CLI behaviour is explicitly unchanged: a console run has no request,
        // so consumers keep the provider's anonymous context even when the
        // process happens to have a populated $_GET.
        $report = $this->probe(['page' => '2'], console: true);

        self::assertTrue($report['resolved']);
        self::assertSame([], $report['query'], 'a console kernel has no request query');
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $query
     * @return array{resolved: bool, query: array<string, string>}
     */
    private function probe(array $query, bool $console = false): array
    {
        $process = new Process(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/request_context_probe.php',
                $this->projectRoot,
                json_encode($query, JSON_THROW_ON_ERROR),
                $console ? 'console' : 'http',
            ],
            $this->projectRoot,
        );
        $process->setTimeout(120);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array{resolved: bool, query: array<string, string>} $decoded */
        return $decoded;
    }

    private function writeAutoloadWrapper(): void
    {
        if (!is_dir($this->projectRoot . '/vendor')) {
            mkdir($this->projectRoot . '/vendor', 0o755, true);
        }
        $repoRoot = $this->repoRoot;
        file_put_contents($this->projectRoot . '/vendor/autoload.php', <<<PHP
            <?php

            declare(strict_types=1);

            return require '{$repoRoot}/vendor/autoload.php';
            PHP);
    }
}
