<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\MutationAuthorityBackfillHandler;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Exception\MutationAuthorityBackfillException;
use Waaseyaa\EntityStorage\LegacyMutationAuthorityBackfillRepositoryInterface;

#[CoversClass(MutationAuthorityBackfillHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class MutationAuthorityBackfillHandlerTest extends TestCase
{
    #[Test]
    public function it_requires_a_non_empty_audit_reason_before_resolving_repositories(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::never())->method('getDefinitions');
        [$io, , $stderr] = $this->io(['--reason' => '   ']);

        $exit = new MutationAuthorityBackfillHandler($manager)->execute($io);

        self::assertSame(2, $exit);
        self::assertStringContainsString('non-empty --reason', $stderr->fetch());
    }

    #[Test]
    public function it_reports_sorted_per_type_counts_without_token_material_and_is_idempotent(): void
    {
        $node = $this->repository(2, 0);
        $workflow = $this->repository(1, 0);
        $manager = $this->manager(['workflow' => $workflow, 'node' => $node]);

        [$firstIo, $firstOutput] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);
        self::assertSame(0, new MutationAuthorityBackfillHandler($manager)->execute($firstIo));
        $firstJson = $firstOutput->fetch();
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => 3,
            'entity_types' => ['node' => 2, 'workflow' => 1],
            'skipped_entity_types' => [],
            'failed_entity_types' => [],
        ], json_decode(trim($firstJson), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('mutation_tag', $firstJson);
        self::assertStringNotContainsString('Upgrade rehearsal', $firstJson);

        [$retryIo, $retryOutput] = $this->io(['--reason' => 'Upgrade rehearsal retry', '--json' => true]);
        self::assertSame(0, new MutationAuthorityBackfillHandler($manager)->execute($retryIo));
        $retryJson = $retryOutput->fetch();
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal retry'),
            'created' => 0,
            'entity_types' => ['node' => 0, 'workflow' => 0],
            'skipped_entity_types' => [],
            'failed_entity_types' => [],
        ], json_decode(trim($retryJson), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('mutation_tag', $retryJson);
    }

    #[Test]
    public function it_repairs_supported_repositories_and_reports_unsupported_types(): void
    {
        $supported = $this->repository(1);
        $unsupported = $this->createMock(EntityRepositoryInterface::class);
        $manager = $this->manager(['node' => $supported, 'external' => $unsupported]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        $exit = new MutationAuthorityBackfillHandler($manager)->execute($io);

        self::assertSame(0, $exit);
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => 1,
            'entity_types' => ['node' => 1],
            'skipped_entity_types' => ['external'],
            'failed_entity_types' => [],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_a_failed_type_and_continues_repairing_other_types(): void
    {
        $failed = $this->repository();
        $failed->expects(self::once())
            ->method('backfillMutationAuthorities')
            ->willThrowException(new \LogicException('No database authority boundary.'));
        $supported = $this->repository(2);
        $manager = $this->manager(['workflow' => $failed, 'node' => $supported]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        $exit = new MutationAuthorityBackfillHandler($manager)->execute($io);

        self::assertSame(1, $exit);
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => null,
            'entity_types' => ['node' => 2, 'workflow' => null],
            'skipped_entity_types' => [],
            'failed_entity_types' => ['workflow'],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_the_exact_committed_count_from_a_typed_failure(): void
    {
        $failed = $this->repository();
        $failed->expects(self::once())
            ->method('backfillMutationAuthorities')
            ->willThrowException(new MutationAuthorityBackfillException(3, new \RuntimeException('Listener failed.')));
        $manager = $this->manager(['node' => $failed]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        self::assertSame(1, new MutationAuthorityBackfillHandler($manager)->execute($io));
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => 3,
            'entity_types' => ['node' => 3],
            'skipped_entity_types' => [],
            'failed_entity_types' => ['node'],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_renders_an_unknown_total_when_any_type_count_is_unknown(): void
    {
        $failed = $this->repository();
        $failed->method('backfillMutationAuthorities')
            ->willThrowException(new \RuntimeException('Foreign repository failed.'));
        $manager = $this->manager(['node' => $failed]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal']);

        self::assertSame(1, new MutationAuthorityBackfillHandler($manager)->execute($io));
        self::assertStringContainsString('Mutation-authority backfill: created=unknown', $stdout->fetch());
    }

    #[Test]
    public function it_skips_a_framework_repository_without_the_authority_boundary(): void
    {
        $repository = $this->createMockForIntersectionOfInterfaces([
            EntityRepositoryInterface::class,
            LegacyMutationAuthorityBackfillRepositoryInterface::class,
        ]);
        $repository->method('supportsMutationAuthorityBackfill')->willReturn(false);
        $repository->expects(self::never())->method('backfillMutationAuthorities');
        $manager = $this->manager(['legacy' => $repository]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        self::assertSame(0, new MutationAuthorityBackfillHandler($manager)->execute($io));
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => 0,
            'entity_types' => [],
            'skipped_entity_types' => ['legacy'],
            'failed_entity_types' => [],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_a_failed_capability_probe_and_continues_with_later_types(): void
    {
        $broken = $this->createMockForIntersectionOfInterfaces([
            EntityRepositoryInterface::class,
            LegacyMutationAuthorityBackfillRepositoryInterface::class,
        ]);
        $broken->method('supportsMutationAuthorityBackfill')
            ->willThrowException(new \RuntimeException('Capability probe failed.'));
        $broken->expects(self::never())->method('backfillMutationAuthorities');
        $supported = $this->repository(1);
        $manager = $this->manager(['broken' => $broken, 'node' => $supported]);
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        self::assertSame(1, new MutationAuthorityBackfillHandler($manager)->execute($io));
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => null,
            'entity_types' => ['broken' => null, 'node' => 1],
            'skipped_entity_types' => [],
            'failed_entity_types' => ['broken'],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_repository_construction_failure_and_continues_with_later_types(): void
    {
        $supported = $this->repository(1);
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'broken' => $this->createMock(EntityTypeInterface::class),
            'node' => $this->createMock(EntityTypeInterface::class),
        ]);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $entityTypeId): EntityRepositoryInterface => $entityTypeId === 'broken'
                ? throw new \RuntimeException('Missing legacy table.')
                : $supported,
        );
        [$io, $stdout] = $this->io(['--reason' => 'Upgrade rehearsal', '--json' => true]);

        $exit = new MutationAuthorityBackfillHandler($manager)->execute($io);

        self::assertSame(1, $exit);
        self::assertSame([
            'reason_sha256' => hash('sha256', 'Upgrade rehearsal'),
            'created' => 1,
            'entity_types' => ['broken' => 0, 'node' => 1],
            'skipped_entity_types' => [],
            'failed_entity_types' => ['broken'],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    private function repository(int ...$results): EntityRepositoryInterface&LegacyMutationAuthorityBackfillRepositoryInterface
    {
        $repository = $this->createMockForIntersectionOfInterfaces([
            EntityRepositoryInterface::class,
            LegacyMutationAuthorityBackfillRepositoryInterface::class,
        ]);
        $repository->method('supportsMutationAuthorityBackfill')->willReturn(true);
        if ($results !== []) {
            $repository->expects(self::exactly(count($results)))
                ->method('backfillMutationAuthorities')
                ->willReturnOnConsecutiveCalls(...$results);
        }

        return $repository;
    }

    /** @param array<string, EntityRepositoryInterface> $repositories */
    private function manager(array $repositories): EntityTypeManagerInterface
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $definitions = [];
        foreach ($repositories as $entityTypeId => $repository) {
            $definitions[$entityTypeId] = $this->createMock(EntityTypeInterface::class);
        }
        $manager->method('getDefinitions')->willReturn($definitions);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $entityTypeId): EntityRepositoryInterface => $repositories[$entityTypeId],
        );

        return $manager;
    }

    /** @param array<string, mixed> $arguments */
    private function io(array $arguments): array
    {
        $definition = new InputDefinition([
            new InputOption('reason', null, InputOption::VALUE_REQUIRED, '', ''),
            new InputOption('json', null, InputOption::VALUE_NONE),
        ]);
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        return [new SymfonyCommandIO(new ArrayInput($arguments, $definition), $stdout, $stderr), $stdout, $stderr];
    }
}
