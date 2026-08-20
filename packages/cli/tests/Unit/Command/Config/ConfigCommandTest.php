<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Config\ConfigCommand;
use Waaseyaa\CLI\Command\Config\ConfigDiffCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\Config\ConfigManifestSignCommand;
use Waaseyaa\CLI\Command\Config\ConfigResetCommand;
use Waaseyaa\CLI\Command\Config\ConfigStatusCommand;
use Waaseyaa\CLI\Command\Config\ConfigValidateCommand;
use Waaseyaa\Config\Exception\ConfigCommandCollisionException;

#[CoversClass(ConfigCommand::class)]
final class ConfigCommandTest extends TestCase
{
    #[Test]
    public function reserved_verbs_match_contract(): void
    {
        // contracts/cli-namespace.md §"Reserved verb namespace" — seven verbs
        // in canonical order. Iteration order is part of the published
        // contract, so we assert the exact list (not just membership).
        // `manifest:sign` was added in #2430 when CFG-03 gained an authoring
        // command; it is reserved so an app cannot shadow the one command that
        // mints signing evidence.
        self::assertSame(
            ['export', 'import', 'manifest:sign', 'diff', 'status', 'validate', 'reset'],
            ConfigCommand::RESERVED_VERBS,
        );

        self::assertSame(
            [
                'config:export',
                'config:import',
                'config:manifest:sign',
                'config:diff',
                'config:status',
                'config:validate',
                'config:reset',
            ],
            ConfigCommand::RESERVED_FULL_VERBS,
        );
    }

    #[Test]
    public function reserved_fqcns_cover_every_reserved_verb(): void
    {
        // Each reserved verb has exactly one allowlisted handler FQCN.
        self::assertCount(
            count(ConfigCommand::RESERVED_VERBS),
            ConfigCommand::RESERVED_FQCNS,
            'RESERVED_FQCNS must enumerate one class per reserved verb.',
        );

        self::assertSame(
            [
                ConfigExportCommand::class,
                ConfigImportCommand::class,
                ConfigManifestSignCommand::class,
                ConfigDiffCommand::class,
                ConfigStatusCommand::class,
                ConfigValidateCommand::class,
                ConfigResetCommand::class,
            ],
            ConfigCommand::RESERVED_FQCNS,
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reservedVerbCases(): iterable
    {
        // Fully-qualified reserved verbs.
        yield 'fq export' => ['config:export', true];
        yield 'fq import' => ['config:import', true];
        yield 'fq diff' => ['config:diff', true];
        yield 'fq status' => ['config:status', true];
        yield 'fq validate' => ['config:validate', true];
        yield 'fq reset' => ['config:reset', true];

        // Bare sub-verbs (with no prefix).
        yield 'bare export' => ['export', true];
        yield 'bare reset' => ['reset', true];

        // Custom config verbs are NOT reserved (FR-049).
        yield 'config:audit-export' => ['config:audit-export', false];
        yield 'config:lint' => ['config:lint', false];
        yield 'config:rebuild-cache' => ['config:rebuild-cache', false];

        // Verbs in unrelated namespaces are NOT reserved.
        yield 'cache:clear' => ['cache:clear', false];
        yield 'queue:work' => ['queue:work', false];
        yield 'plain' => ['plain', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('reservedVerbCases')]
    public function is_reserved_verb_table(string $verb, bool $expected): void
    {
        self::assertSame($expected, ConfigCommand::isReservedVerb($verb));
    }

    #[Test]
    public function framework_fqcns_are_recognised_as_allowlisted(): void
    {
        foreach (ConfigCommand::RESERVED_FQCNS as $allowed) {
            self::assertTrue(
                ConfigCommand::isReservedFqcn($allowed),
                sprintf('Framework FQCN "%s" must be allowlisted.', $allowed),
            );
        }
    }

    #[Test]
    public function arbitrary_app_fqcn_is_not_allowlisted(): void
    {
        self::assertFalse(ConfigCommand::isReservedFqcn('App\\Console\\MyExportCommand'));
        self::assertFalse(ConfigCommand::isReservedFqcn(self::class));
    }

    #[Test]
    public function assert_no_collision_passes_for_non_reserved_verb_in_any_fqcn(): void
    {
        // FR-049: apps may register `config:<custom>` verbs freely.
        ConfigCommand::assertNoCollision('config:audit-export', 'App\\AuditExportCommand');
        ConfigCommand::assertNoCollision('config:lint', self::class);
        ConfigCommand::assertNoCollision('cache:clear', 'App\\Console\\ClearCacheCommand');

        // Reaching here means no throw occurred.
        self::assertTrue(true);
    }

    #[Test]
    public function assert_no_collision_passes_for_reserved_verb_in_framework_fqcn(): void
    {
        // FR-047: the framework registers its own reserved verbs.
        ConfigCommand::assertNoCollision('config:export', ConfigExportCommand::class);
        ConfigCommand::assertNoCollision('config:import', ConfigImportCommand::class);
        ConfigCommand::assertNoCollision('config:diff', ConfigDiffCommand::class);
        ConfigCommand::assertNoCollision('config:status', ConfigStatusCommand::class);
        ConfigCommand::assertNoCollision('config:validate', ConfigValidateCommand::class);
        ConfigCommand::assertNoCollision('config:reset', ConfigResetCommand::class);

        self::assertTrue(true);
    }

    #[Test]
    public function assert_no_collision_throws_for_reserved_verb_in_foreign_fqcn(): void
    {
        // FR-048: registering a reserved verb from an app class fails at boot.
        $this->expectException(ConfigCommandCollisionException::class);
        $this->expectExceptionMessage('config:export');

        ConfigCommand::assertNoCollision('config:export', 'App\\Console\\MyExportCommand');
    }

    #[Test]
    public function assert_no_collision_throws_for_every_reserved_verb_against_foreign_fqcn(): void
    {
        foreach (ConfigCommand::RESERVED_FULL_VERBS as $verb) {
            $thrown = false;
            try {
                ConfigCommand::assertNoCollision($verb, 'App\\Console\\Hostile');
            } catch (ConfigCommandCollisionException $exception) {
                $thrown = true;
                self::assertSame($verb, $exception->reservedVerb);
                self::assertSame('App\\Console\\Hostile', $exception->offendingFqcn);
                self::assertSame('config.cli.collision', $exception->errorCode);
            }

            self::assertTrue(
                $thrown,
                sprintf('Reserved verb "%s" must reject foreign FQCNs.', $verb),
            );
        }
    }
}
