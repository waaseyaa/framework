<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\OidcServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Oidc\Key\SigningKeyRepository;

/**
 * `oidc:*` commands are an optional contribution gated on `waaseyaa/oidc`
 * (#2828, the OIDC sibling of #2826). The monorepo always has the package,
 * so this test pins the declaration and the present-side behaviour; the
 * absent side is proved by `tests/PackagedForm/check-cli-oidc-commands-optional`
 * on a real consumer.
 */
#[CoversClass(OidcServiceProvider::class)]
final class OidcServiceProviderOptionalPackageTest extends TestCase
{
    #[Test]
    public function the_provider_declares_oidc_as_its_optional_package(): void
    {
        self::assertInstanceOf(RequiresOptionalPackagesInterface::class, new OidcServiceProvider());

        $requirements = iterator_to_array(OidcServiceProvider::optionalPackageRequirements(), false);
        self::assertCount(1, $requirements);
        self::assertSame('waaseyaa/oidc', $requirements[0]->package);
        self::assertSame(SigningKeyRepository::class, $requirements[0]->sentinelClass);

        $cliManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waaseyaa/oidc', $cliManifest['require'], 'cli must not require oidc unconditionally.');
        self::assertArrayHasKey('waaseyaa/oidc', $cliManifest['suggest'], 'The optional package stays declared in suggest.');

        $oidcManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/oidc/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $owned = false;
        foreach (array_keys($oidcManifest['autoload']['psr-4']) as $prefix) {
            $owned = $owned || str_starts_with($requirements[0]->sentinelClass, $prefix);
        }
        self::assertTrue($owned, 'The sentinel must be a class the optional package itself autoloads.');
    }

    #[Test]
    public function with_oidc_present_every_command_is_advertised(): void
    {
        self::assertTrue(OptionalPackageGate::satisfied(OidcServiceProvider::class), 'The monorepo installs oidc.');

        $provider = new OidcServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), ['oidc' => []], []);
        $provider->register();

        $names = [];
        foreach ($provider->consoleCommands() as $command) {
            self::assertInstanceOf(HandlerCommand::class, $command);
            $names[] = $command->getName();
        }
        self::assertSame([
            'oidc:init-signing-key',
            'oidc:stage-signing-key',
            'oidc:record-signing-key-propagation',
            'oidc:activate-signing-key',
            'oidc:cleanup-signing-keys',
            'oidc:emergency-revoke-signing-key',
            'oidc:migrate-secrets',
        ], $names);
    }
}
