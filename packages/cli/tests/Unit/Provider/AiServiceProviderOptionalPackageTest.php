<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\CLI\Command\Ai\AiPurgeRunsCommand;
use Waaseyaa\CLI\Command\Ai\AiReapStalledRunsCommand;
use Waaseyaa\CLI\Command\Ai\AiRunCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\AiServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;

/**
 * `ai:*` commands are an optional contribution gated on `waaseyaa/ai-agent`
 * (#2826). The monorepo always has the package, so this test pins the
 * declaration and the present-side behaviour; the absent side is proved by
 * `tests/PackagedForm/check-cli-ai-commands-optional` on a real consumer.
 */
#[CoversClass(AiServiceProvider::class)]
final class AiServiceProviderOptionalPackageTest extends TestCase
{
    #[Test]
    public function the_provider_declares_ai_agent_as_its_optional_package(): void
    {
        self::assertInstanceOf(RequiresOptionalPackagesInterface::class, new AiServiceProvider());

        $requirements = iterator_to_array(AiServiceProvider::optionalPackageRequirements(), false);
        self::assertCount(1, $requirements);
        self::assertSame('waaseyaa/ai-agent', $requirements[0]->package);
        self::assertSame(AgentRunRepository::class, $requirements[0]->sentinelClass);

        $cliManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waaseyaa/ai-agent', $cliManifest['require'], 'cli must not require ai-agent unconditionally.');
        self::assertArrayHasKey('waaseyaa/ai-agent', $cliManifest['suggest'], 'The optional package stays declared in suggest.');

        $agentManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/ai-agent/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $owned = false;
        foreach (array_keys($agentManifest['autoload']['psr-4']) as $prefix) {
            $owned = $owned || str_starts_with($requirements[0]->sentinelClass, $prefix);
        }
        self::assertTrue($owned, 'The sentinel must be a class the optional package itself autoloads.');
    }

    #[Test]
    public function with_ai_agent_present_every_command_is_advertised_and_bound(): void
    {
        self::assertTrue(OptionalPackageGate::satisfied(AiServiceProvider::class), 'The monorepo installs ai-agent.');

        $provider = new AiServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), ['ai' => []], []);
        $provider->register();

        $names = [];
        foreach ($provider->consoleCommands() as $command) {
            self::assertInstanceOf(HandlerCommand::class, $command);
            $names[] = $command->getName();
        }
        self::assertSame(['ai:run', 'ai:purge-runs', 'ai:reap-stalled-runs'], $names);
        self::assertSame(
            [AiRunCommand::class, AiPurgeRunsCommand::class, AiReapStalledRunsCommand::class],
            array_keys($provider->getBindings()),
        );
    }
}
