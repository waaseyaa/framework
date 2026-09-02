<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture;

use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Declares a requirement on a package whose sentinel never exists. */
final class OptionalPackageAbsentProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public const string SENTINEL = 'Waaseyaa\\Tests\\NeverInstalled\\Sentinel';

    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement('waaseyaa/never-installed', self::SENTINEL, 'fixture commands');
    }

    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield 'fixture:absent';
    }
}
