<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchCandidateResolverRegistry;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;

#[CoversClass(SearchCandidateResolverRegistry::class)]
final class SearchCandidateResolverRegistryTest extends TestCase
{
    #[Test]
    public function unknown_non_entity_namespace_is_denied(): void
    {
        $registry = new SearchCandidateResolverRegistry(
            $this->managerWithoutEntityTypes(),
            $this->denyResolver(),
        );

        self::assertNull($registry->resolve(
            new SearchCandidateReference('unknown:1', 'document'),
            SearchTestPrincipal::create(),
        ));
    }

    #[Test]
    public function explicitly_registered_namespace_must_return_the_same_canonical_id(): void
    {
        $source = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return new SearchCandidateProjection(
                    id: $reference->documentId,
                    entityType: 'document',
                    title: 'Canonical source title',
                    body: 'Canonical source body',
                );
            }
        };
        $registry = new SearchCandidateResolverRegistry(
            $this->managerWithoutEntityTypes(),
            $this->denyResolver(),
            ['spec' => $source],
        );

        $projection = $registry->resolve(
            new SearchCandidateReference('spec:overview', 'document'),
            SearchTestPrincipal::create(),
        );

        self::assertSame('Canonical source title', $projection?->title);
    }

    #[Test]
    public function a_registered_source_cannot_substitute_another_document(): void
    {
        $source = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return new SearchCandidateProjection('spec:other', 'document', 'Wrong document', '');
            }
        };
        $registry = new SearchCandidateResolverRegistry(
            $this->managerWithoutEntityTypes(),
            $this->denyResolver(),
            ['spec' => $source],
        );

        self::assertNull($registry->resolve(
            new SearchCandidateReference('spec:overview', 'document'),
            SearchTestPrincipal::create(),
        ));
    }

    #[Test]
    public function source_namespaces_cannot_collide_with_registered_entity_types(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $type): bool => $type === 'user');

        $this->expectException(\InvalidArgumentException::class);
        new SearchCandidateResolverRegistry(
            $manager,
            $this->denyResolver(),
            ['user' => $this->denyResolver()],
        );
    }

    #[Test]
    public function an_entity_namespace_cannot_fall_through_to_a_source_resolver(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $type): bool => $type === 'user');
        $registry = new SearchCandidateResolverRegistry($manager, $this->denyResolver());

        self::assertNull($registry->resolve(
            new SearchCandidateReference('user:1', 'document'),
            SearchTestPrincipal::create(),
        ));
    }

    private function managerWithoutEntityTypes(): EntityTypeManagerInterface
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(false);

        return $manager;
    }

    private function denyResolver(): SearchCandidateResolverInterface
    {
        return new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return null;
            }
        };
    }
}
