<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Provider\HealthSchemaServiceProvider;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Regression coverage for #2820: `HealthSchemaServiceProvider` published the
 * `health:report` command but never bound `HealthReportHandler` itself, so a
 * consumer application (no application-local container bindings or handler
 * subclasses) fell through to reflection-based auto-wiring, which cannot
 * resolve the handler's plain `string $projectRoot` constructor parameter —
 * "Cannot auto-wire ... unresolvable parameter "$projectRoot"".
 */
#[CoversClass(HealthSchemaServiceProvider::class)]
final class HealthSchemaServiceProviderTest extends TestCase
{
    #[Test]
    public function health_report_handler_resolves_from_a_container_without_a_pre_bound_project_root(): void
    {
        $checker = $this->createStub(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::pass('Database', 'OK'),
        ]);
        $authority = $this->authorityContext();

        $provider = new HealthSchemaServiceProvider();
        // The provider's project root comes only from the framework
        // composition contract (setKernelContext), exactly as a consumer
        // application's kernel wires it — never a value the test hands to
        // the handler's constructor directly.
        $provider->setKernelContext('/srv/consumer-app', [], []);
        $provider->setKernelServices(new readonly class ($checker, $authority) implements KernelServicesInterface {
            public function __construct(
                private HealthCheckerInterface $checker,
                private ConfigurationAuthorityContext $authority,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    HealthCheckerInterface::class => $this->checker,
                    ConfigurationAuthorityContext::class => $this->authority,
                    default => null,
                };
            }
        });
        $provider->register();

        $handler = $provider->resolve(HealthReportHandler::class);

        self::assertInstanceOf(HealthReportHandler::class, $handler);

        $projectRoot = new \ReflectionProperty(HealthReportHandler::class, 'projectRoot');
        self::assertSame('/srv/consumer-app', $projectRoot->getValue($handler));

        // Same singleton instance on a second resolve, matching the
        // FieldAccessPreflightHandler binding this follows.
        self::assertSame($handler, $provider->resolve(HealthReportHandler::class));
    }

    private function authorityContext(): ConfigurationAuthorityContext
    {
        return new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: '/srv/waaseyaa/config-sync',
            selectorProvenance: ['config.sync_path'],
            activeGenerationId: str_repeat('b', 64),
            activationSequence: 1,
        );
    }
}
