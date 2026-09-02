<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture;

use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Declares a requirement whose sentinel is this package's own base class, so it is always satisfied. */
final class OptionalPackagePresentProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement('waaseyaa/foundation', ServiceProvider::class, 'fixture commands');
    }

    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield 'fixture:present';
    }
}
