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
            'created' => 3,
            'entity_types' => ['node' => 2, 'workflow' => 1],
            'skipped_entity_types' => [],
            'failed_entity_types' => [],
        ], json_decode(trim($firstJson), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('mutation_tag', $firstJson);

        [$retryIo, $retryOutput] = $this->io(['--reason' => 'Upgrade rehearsal retry', '--json' => true]);
        self::assertSame(0, new MutationAuthorityBackfillHandler($manager)->execute($retryIo));
        $retryJson = $retryOutput->fetch();
        self::assertSame([
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
            'created' => 2,
            'entity_types' => ['node' => 2, 'workflow' => 0],
            'skipped_entity_types' => [],
            'failed_entity_types' => ['workflow'],
        ], json_decode(trim($stdout->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    private function repository(int ...$results): EntityRepositoryInterface&LegacyMutationAuthorityBackfillRepositoryInterface
    {
        $repository = $this->createMockForIntersectionOfInterfaces([
            EntityRepositoryInterface::class,
            LegacyMutationAuthorityBackfillRepositoryInterface::class,
        ]);
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
