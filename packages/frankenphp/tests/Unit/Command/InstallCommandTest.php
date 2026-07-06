<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\Command\InstallCommand;

/**
 * CLI-surface coverage for the checksum-unavailable fail-closed change: the
 * Installer-level behavior (packages/frankenphp/tests/Unit/Binary/InstallerTest.php)
 * is exercised offline with injected closures; here we only pin that
 * `frankenphp:install` actually wires up the `--allow-unverified` opt-out flag it
 * threads into `Installer`, since `performInstall()` constructs a real network-backed
 * `Installer` internally and so cannot be exercised offline via `execute()`.
 */
#[CoversClass(InstallCommand::class)]
final class InstallCommandTest extends TestCase
{
    #[Test]
    public function the_allow_unverified_option_is_registered_as_a_no_value_flag_defaulting_to_false(): void
    {
        $command = new InstallCommand('/tmp/does-not-matter');
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('allow-unverified'));

        $option = $definition->getOption('allow-unverified');
        self::assertFalse($option->acceptValue(), '--allow-unverified must be a bare flag (InputOption::VALUE_NONE)');
        self::assertFalse($option->getDefault(), '--allow-unverified must default to false (fail-closed by default)');
    }

    #[Test]
    public function the_force_and_version_options_are_unaffected_by_the_new_flag(): void
    {
        $command = new InstallCommand('/tmp/does-not-matter');
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('force'));
        self::assertTrue($definition->hasOption('frankenphp-version'));
    }
}
