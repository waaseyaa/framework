<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase28;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Config\ConfigCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\Config\Exception\ConfigCommandCollisionException;

/**
 * Integration: kernel-boot enforcement of the `config:*` namespace reservation.
 *
 * Simulates Symfony CLI boot-time command registration iterating over
 * a `(verb → FQCN)` map and invoking {@see ConfigCommand::assertNoCollision()}
 * for every entry. Verifies:
 *
 *  - **FR-047**: the framework's six handler FQCNs register their reserved
 *    verbs without incident.
 *  - **FR-048**: any foreign FQCN registering a reserved verb causes boot
 *    to fail with {@see ConfigCommandCollisionException} carrying the
 *    stable `config.cli.collision` error code, the colliding verb, and the
 *    offending FQCN.
 *  - **FR-049**: apps may register `config:<custom>` verbs (non-reserved)
 *    from any FQCN without collision.
 *
 * The boot loop here mirrors the contract documented at
 * `kitty-specs/config-management-v1-01KRCDEC/contracts/cli-namespace.md`
 * §"Reservation enforcement". The Symfony Console application factory calls
 * `assertNoCollision()` inside its command-registration entry point and
 * propagates the exception, refusing to boot.
 */
#[CoversNothing]
final class ConfigCommandCollisionBootTest extends TestCase
{
    #[Test]
    public function framework_command_registrations_boot_cleanly(): void
    {
        // Map mirrors the canonical (verb → FQCN) bindings from the
        // RESERVED_FQCNS allowlist. A clean boot must process every entry
        // without throwing.
        $registrations = [
            'config:export' => ConfigExportCommand::class,
            'config:import' => ConfigImportCommand::class,
            'config:diff' => \Waaseyaa\CLI\Command\Config\ConfigDiffCommand::class,
            'config:status' => \Waaseyaa\CLI\Command\Config\ConfigStatusCommand::class,
            'config:validate' => \Waaseyaa\CLI\Command\Config\ConfigValidateCommand::class,
            'config:reset' => \Waaseyaa\CLI\Command\Config\ConfigResetCommand::class,
        ];

        $this->simulateBoot($registrations);

        self::assertTrue(true, 'Framework verb/FQCN pairs must boot without collision.');
    }

    #[Test]
    public function app_custom_config_verbs_boot_cleanly(): void
    {
        // FR-049: non-reserved `config:<custom>` verbs from app classes are
        // permitted. The framework reserves the six verb names, not the
        // broader `config:` prefix.
        $registrations = [
            'config:audit-export' => 'App\\Console\\AuditExportCommand',
            'config:lint' => 'App\\Console\\ConfigLintCommand',
            'config:rebuild-cache' => 'App\\Console\\RebuildCacheCommand',
            'cache:clear' => 'App\\Console\\ClearCacheCommand',
        ];

        $this->simulateBoot($registrations);

        self::assertTrue(true, 'Non-reserved verbs must boot without collision regardless of FQCN.');
    }

    #[Test]
    public function foreign_class_claiming_reserved_verb_refuses_boot(): void
    {
        // FR-048: an app/extension class registering `config:export` fails
        // at the registration hook with the typed collision exception.
        $registrations = [
            // Innocuous registration that boots fine on its own.
            'cache:clear' => 'App\\Console\\ClearCacheCommand',
            // Reserved verb claimed by a foreign FQCN — must fail.
            'config:export' => 'App\\Console\\MyExportCommand',
        ];

        $caught = null;
        try {
            $this->simulateBoot($registrations);
        } catch (ConfigCommandCollisionException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($caught, 'Kernel boot must refuse foreign reserved-verb registrations.');
        self::assertSame('config:export', $caught->reservedVerb);
        self::assertSame('App\\Console\\MyExportCommand', $caught->offendingFqcn);
        self::assertSame('config.cli.collision', $caught->errorCode);
        self::assertInstanceOf(\LogicException::class, $caught);
    }

    #[Test]
    public function every_reserved_verb_is_individually_protected_at_boot(): void
    {
        // Iterate the full reserved-verb set and confirm each one
        // independently triggers a collision when claimed by a foreign FQCN.
        // Guards against accidental allowlisting of any single verb.
        foreach (ConfigCommand::RESERVED_FULL_VERBS as $reservedVerb) {
            $caught = null;
            try {
                $this->simulateBoot([$reservedVerb => 'App\\Console\\Hostile']);
            } catch (ConfigCommandCollisionException $exception) {
                $caught = $exception;
            }

            self::assertNotNull(
                $caught,
                sprintf('Reserved verb "%s" must refuse foreign registration at boot.', $reservedVerb),
            );
            self::assertSame($reservedVerb, $caught->reservedVerb);
            self::assertSame('config.cli.collision', $caught->errorCode);
        }
    }

    #[Test]
    public function mixed_registration_set_fails_fast_on_first_collision(): void
    {
        // Real kernels register commands in declaration order. The hook
        // fails fast on the FIRST collision; later legitimate registrations
        // never execute. This is the canonical fail-fast boot behaviour.
        $registrations = [
            'cache:clear' => 'App\\Console\\ClearCacheCommand', // ok
            'config:audit-export' => 'App\\Console\\AuditExportCommand', // ok
            'config:diff' => 'App\\Console\\BadDiff', // BOOM
            // The following entries must never be reached.
            'config:status' => \Waaseyaa\CLI\Command\Config\ConfigStatusCommand::class,
        ];

        $this->expectException(ConfigCommandCollisionException::class);
        $this->expectExceptionMessage('config:diff');

        $this->simulateBoot($registrations);
    }

    /**
     * Drive the Symfony CLI boot-time command-registration hook.
     *
     * This mirrors what the Symfony Console application factory does for every
     * provider command: call {@see ConfigCommand::assertNoCollision()} with the
     * registered verb and source FQCN. The first collision throws and aborts
     * the boot.
     *
     * @param array<string, class-string|string> $registrations  verb → FQCN map.
     */
    private function simulateBoot(array $registrations): void
    {
        foreach ($registrations as $verb => $fqcn) {
            ConfigCommand::assertNoCollision($verb, $fqcn);
        }
    }
}
