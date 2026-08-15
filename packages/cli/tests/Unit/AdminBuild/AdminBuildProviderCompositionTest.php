<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\AdminBuildHandler;
use Waaseyaa\CLI\Provider\MiscAServiceProvider;
use Waaseyaa\Foundation\Log\Handler\NullHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(MiscAServiceProvider::class)]
final class AdminBuildProviderCompositionTest extends TestCase
{
    #[Test]
    public function production_handler_reuses_the_kernel_log_managers_mandatory_sink_sanitizer(): void
    {
        $sanitizer = new RedactorProcessor();
        $logger = new LogManager(new NullHandler(), $sanitizer);
        $provider = new MiscAServiceProvider();
        $provider->setKernelContext('/synthetic/project', [], []);
        $provider->setKernelServices(new class($logger) implements KernelServicesInterface {
            public function __construct(private readonly LogManager $logger) {}

            public function get(string $abstract): ?object
            {
                return $abstract === LoggerInterface::class ? $this->logger : null;
            }
        });
        $provider->register();

        $handler = $provider->resolve(AdminBuildHandler::class);
        $property = new \ReflectionProperty($handler, 'sanitizer');

        self::assertSame($sanitizer, $property->getValue($handler));
    }
}
