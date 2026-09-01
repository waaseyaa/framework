<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathAliasUniquenessListener;

/**
 * Unit-level companion to {@see \Waaseyaa\Tests\Integration\PathAliasDomainInvariantTest}:
 * exercises {@see PathAliasUniquenessListener}'s public `__invoke()`
 * dispatcher directly (issue #2754) rather than only through the full
 * SQL-backed integration composition.
 */
#[CoversClass(PathAliasUniquenessListener::class)]
final class PathAliasUniquenessListenerTest extends TestCase
{
    #[Test]
    public function rejectsANonEmptyAliasWithoutALeadingSlashBeforeConsultingTheRepository(): void
    {
        // The domain check must short-circuit before the listener ever asks
        // for the path_alias repository, so an unconfigured stub (which
        // would error if called) proves the rejection happens first.
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $listener = new PathAliasUniquenessListener($entityTypeManager);

        // Generic construction (no setAlias() call), mirroring the JSON:API
        // POST shape that bypasses PathAlias::setAlias()'s own guard.
        $entity = new PathAlias(['path' => '/node/1', 'alias' => 'missing-leading-slash', 'langcode' => 'en']);
        $event = new BeforeSaveEvent($entity, SaveContext::default(), true);

        try {
            ($listener)($event);
            self::fail('Expected AbortOperationException was not thrown.');
        } catch (AbortOperationException $e) {
            self::assertStringContainsString('must start with a forward slash', $e->reason);
        }
    }

    #[Test]
    public function allowsAnEmptyAliasThroughToTheUniquenessCheck(): void
    {
        // An empty alias is in the canonical domain (unresolvable, not
        // malformed) — it must fall through to the uniqueness lookup rather
        // than being rejected by the domain check itself.
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $repository = $this->createStub(\Waaseyaa\Entity\Repository\EntityRepositoryInterface::class);
        $entityTypeManager->method('getRepository')->willReturn($repository);

        $query = $this->createStub(\Waaseyaa\Entity\Storage\EntityQueryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $listener = new PathAliasUniquenessListener($entityTypeManager);

        $entity = new PathAlias(['path' => '/node/1', 'alias' => '', 'langcode' => 'en']);
        $event = new BeforeSaveEvent($entity, SaveContext::default(), true);

        // Must NOT throw.
        ($listener)($event);

        self::assertSame('', $entity->getAlias());
    }
}
