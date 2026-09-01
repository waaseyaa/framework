<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Taxonomy\VocabularyAccessPolicy;

#[CoversClass(VocabularyAccessPolicy::class)]
final class VocabularyAccessPolicyTest extends TestCase
{
    #[Test]
    public function deleteIsForbiddenWhenATermReferencesTheVocabulary(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['vid' => 'topics'], null, 1)
            ->willReturn([$this->createStub(\Waaseyaa\Entity\EntityInterface::class)]);
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('getRepository')->with('taxonomy_term')->willReturn($repository);

        $result = (new VocabularyAccessPolicy($manager))->access(
            new Vocabulary(['vid' => 'topics', 'name' => 'Topics']),
            'delete',
            new AuthorizationPrincipal(1, true, ['administrator'], [], 'test'),
        );

        self::assertTrue($result->isForbidden());
        self::assertStringContainsString('contains terms', $result->reason);
    }

    #[Test]
    public function emptyVocabularyLeavesTheAdministratorGrantUnchanged(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findBy')->willReturn([]);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);

        $result = (new VocabularyAccessPolicy($manager))->access(
            new Vocabulary(['vid' => 'empty', 'name' => 'Empty']),
            'delete',
            new AuthorizationPrincipal(1, true, ['administrator'], [], 'test'),
        );

        self::assertTrue($result->isNeutral());
    }

    /**
     * #2761: `access()` used to call `VocabularyReferenceConstraint::ensure()`
     * (schema DDL) on every delete-access check whenever a database was
     * wired — i.e. on ordinary production request traffic, not just at
     * kernel boot. The foreign key is now installed exclusively by
     * coordinated schema sync (db:init / schema:sync); this access policy no
     * longer accepts a database at all, so it structurally cannot reach any
     * DDL surface.
     */
    #[Test]
    public function constructorNoLongerAcceptsADatabaseDdlSeam(): void
    {
        $parameters = new \ReflectionMethod(VocabularyAccessPolicy::class, '__construct')->getParameters();

        self::assertCount(1, $parameters, 'VocabularyAccessPolicy must only depend on the entity type manager.');
        self::assertSame('entityTypeManager', $parameters[0]->getName());
    }
}
