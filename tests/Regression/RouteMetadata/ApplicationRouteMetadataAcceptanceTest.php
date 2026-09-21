<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Regression\RouteMetadata;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * Executable RED acceptance for #3123.
 *
 * This file intentionally lives outside the configured PHPUnit suites until
 * the pure-metadata route contract has production support. Run it explicitly.
 */
#[CoversNothing]
final class ApplicationRouteMetadataAcceptanceTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_route_metadata_' . uniqid('', true);
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        $this->materializePackageDiscoveryMetadata();
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/route-metadata-acceptance-fixture',
            'extra' => ['waaseyaa' => ['providers' => [
                'Waaseyaa\\Tests\\Regression\\RouteMetadata\\Fixtures\\InspectableApplicationServiceProvider',
            ]]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', "<?php\n\nreturn ['database' => " . var_export($this->projectRoot . '/storage/waaseyaa.sqlite', true) . ", 'environment' => 'testing', 'app' => ['url' => 'http://localhost', 'name' => 'Route metadata acceptance']];\n");

        $initialization = $this->runAction('init');
        self::assertSame(0, $initialization['exit'], (string) ($initialization['stderr'] ?? ''));
    }

    protected function tearDown(): void
    {
        if (isset($this->projectRoot) && is_dir($this->projectRoot)) {
            new Filesystem()->remove($this->projectRoot);
        }
    }

    #[Test]
    public function http_resolves_and_invokes_the_real_application_handler(): void
    {
        $result = $this->runAction('http');

        self::assertSame(200, $result['status'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertSame('application-handler-invoked', $result['body']);
        self::assertSame(1, $result['counters']['route_contributions']);
        self::assertSame(1, $result['counters']['execution_services_constructed']);
        self::assertSame(1, $result['counters']['handlers_constructed']);
        self::assertSame(1, $result['counters']['handlers_invoked']);
    }

    #[Test]
    public function bimaaji_inspects_the_real_application_route_without_constructing_execution_services(): void
    {
        $result = $this->runAction('inspect');

        self::assertSame(0, $result['after_boot']['route_contributions'], 'Intentional kernel bootstrap must not be confused with route inspection.');
        self::assertGreaterThan(0, $result['route_count']);
        self::assertSame('/application/inspectable', $result['route']['path']);
        self::assertSame(['GET'], $result['route']['methods']);
        self::assertSame(0, $result['after_inspection']['handlers_invoked'], 'Inspection must never invoke the route handler.');

        // RED on the current implementation: BuiltinRouteRegistrar calls the
        // application routes() hook, which constructs both objects before
        // RoutingIntrospectionProvider can describe the populated collection.
        self::assertSame(0, $result['after_inspection']['execution_services_constructed']);
        self::assertSame(0, $result['after_inspection']['handlers_constructed']);
        self::assertSame(0, $result['after_inspection']['route_contributions']);
    }

    /** @return array<string, mixed> */
    private function runAction(string $action): array
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/route_metadata_runner.php', $action, $this->repoRoot, $this->projectRoot]);
        $process->setTimeout(30.0);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $lines = array_values(array_filter(preg_split('/\R/', trim($process->getOutput())) ?: []));
        $json = (string) end($lines);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function materializePackageDiscoveryMetadata(): void
    {
        $vendorWaaseyaa = $this->projectRoot . '/vendor/waaseyaa';
        if (!is_dir($vendorWaaseyaa) && !mkdir($vendorWaaseyaa, 0o755, true)) {
            throw new \RuntimeException(sprintf('Failed to create fixture package directory: %s', $vendorWaaseyaa));
        }
        foreach (glob($this->repoRoot . '/packages/*', GLOB_ONLYDIR) ?: [] as $packageRoot) {
            $target = $vendorWaaseyaa . '/' . basename($packageRoot);
            if (!is_dir($target) && !mkdir($target, 0o755, true)) {
                throw new \RuntimeException(sprintf('Failed to create fixture package metadata directory: %s', $target));
            }
            $manifest = $packageRoot . '/composer.json';
            if (is_file($manifest) && !copy($manifest, $target . '/composer.json')) {
                throw new \RuntimeException(sprintf('Failed to copy fixture package manifest: %s', $manifest));
            }
            if (is_dir($packageRoot . '/src')) {
                new Filesystem()->mirror($packageRoot . '/src', $target . '/src');
            }
        }
    }
}
