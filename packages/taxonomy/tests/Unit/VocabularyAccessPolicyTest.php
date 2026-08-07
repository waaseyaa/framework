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
}
