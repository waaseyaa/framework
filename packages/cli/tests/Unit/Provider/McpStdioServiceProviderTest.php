<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\McpStdioServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;

/**
 * `mcp:serve` is an optional contribution gated on `waaseyaa/ai-agent`
 * (ADR-022 D-9.2, following the `ai:*` precedent of #2826). The monorepo
 * always has the package, so this test pins the declaration and the
 * present-side behaviour; the absent side is proved by the SAME
 * `OptionalPackageGate` mechanism {@see \Waaseyaa\CLI\Tests\Unit\OptionalPackageConsoleCommandsTest}
 * already proves generically, and by
 * `OptionalPackageImportDeclarationTest` (#2826), which scans every provider
 * in `extra.waaseyaa.providers` — including this one — for undeclared imports
 * from a package cli does not require.
 */
#[CoversClass(McpStdioServiceProvider::class)]
final class McpStdioServiceProviderTest extends TestCase
{
    #[Test]
    public function the_provider_declares_ai_agent_as_its_optional_package(): void
    {
        self::assertInstanceOf(RequiresOptionalPackagesInterface::class, new McpStdioServiceProvider());

        $requirements = iterator_to_array(McpStdioServiceProvider::optionalPackageRequirements(), false);
        self::assertCount(1, $requirements);
        self::assertSame('waaseyaa/ai-agent', $requirements[0]->package);
        self::assertSame(LocalOperatorPrincipal::class, $requirements[0]->sentinelClass);

        $cliManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waaseyaa/ai-agent', $cliManifest['require'], 'cli must not require ai-agent unconditionally (CP009 / ADR-022 D-3.0).');
        self::assertArrayHasKey('waaseyaa/ai-agent', $cliManifest['suggest'], 'The optional package stays declared in suggest.');

        $agentManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/ai-agent/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $owned = false;
        foreach (array_keys($agentManifest['autoload']['psr-4']) as $prefix) {
            $owned = $owned || str_starts_with($requirements[0]->sentinelClass, $prefix);
        }
        self::assertTrue($owned, 'The sentinel must be a class the optional package itself autoloads.');
    }

    #[Test]
    public function ai_tools_is_a_hard_require_not_an_optional_one(): void
    {
        // Unlike ai-agent, ai-tools is already production-present in
        // waaseyaa/framework and waaseyaa/full — gating it here would buy
        // nothing (ADR-022 D-9.3's transport-neutral dispatch contracts live
        // there), so cli requires it directly.
        $cliManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('waaseyaa/ai-tools', $cliManifest['require']);
    }

    #[Test]
    public function with_ai_agent_present_the_command_is_advertised_with_a_developer_default_profile_option(): void
    {
        self::assertTrue(OptionalPackageGate::satisfied(McpStdioServiceProvider::class), 'The monorepo installs ai-agent.');

        $provider = new McpStdioServiceProvider();
        $commands = iterator_to_array($provider->consoleCommands(), false);

        self::assertCount(1, $commands);
        self::assertInstanceOf(HandlerCommand::class, $commands[0]);
        self::assertSame('mcp:serve', $commands[0]->getName());

        $options = $commands[0]->handlerOptions();
        self::assertCount(1, $options);
        self::assertSame('profile', $options[0]->name);
        self::assertSame('developer', $options[0]->default);
    }

    #[Test]
    public function register_binds_the_command_only_behind_the_gate_check(): void
    {
        // McpStdioServiceProvider is `final` (repo style), so the absent-package
        // branch cannot be exercised by subclassing it the way the generic
        // OptionalCommandsAbsentFixtureProvider fixture does in
        // OptionalPackageConsoleCommandsTest. What IS provable here, directly
        // against this provider's own register(), is that both its gated
        // methods open with the identical guard — so the generic mechanism
        // test (which proves the console runtime registers zero commands for
        // ANY provider whose gate is unsatisfied) applies to this one too.
        $source = (string) file_get_contents((string) new \ReflectionClass(McpStdioServiceProvider::class)->getFileName());
        self::assertSame(
            2,
            substr_count($source, 'if (!OptionalPackageGate::satisfied($this)) {'),
            'register() and consoleCommands() must both open with the same gate check.',
        );
    }
}
