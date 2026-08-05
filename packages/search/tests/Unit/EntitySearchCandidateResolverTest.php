<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Search\Access\EntitySearchCandidateResolver;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;

#[CoversClass(EntitySearchCandidateResolver::class)]
final class EntitySearchCandidateResolverTest extends TestCase
{
    #[Test]
    public function canonical_projection_is_generated_inside_the_supplied_principal_scope(): void
    {
        $scope = new AccountFieldReadScope();
        $principal = SearchTestPrincipal::create(42);
        $resolver = $this->resolver($this->entity($scope), $scope, true);

        $projection = $resolver->resolve(new SearchCandidateReference('node:1', 'node'), $principal);

        self::assertNotNull($projection);
        self::assertSame('Canonical public title', $projection->title);
        self::assertSame('Canonical public body', $projection->body);
        self::assertNull($scope->current(), 'Principal scope must be restored after projection.');
    }

    #[Test]
    public function a_protected_field_denial_drops_the_entire_candidate(): void
    {
        $scope = new AccountFieldReadScope();
        $resolver = $this->resolver($this->entity($scope, denyProjection: true), $scope, true);

        self::assertNull($resolver->resolve(
            new SearchCandidateReference('node:1', 'node'),
            SearchTestPrincipal::create(),
        ));
    }

    #[Test]
    public function entity_view_denial_happens_before_projection(): void
    {
        $scope = new AccountFieldReadScope();
        $entity = $this->entity($scope);
        $resolver = $this->resolver($entity, $scope, false);

        self::assertNull($resolver->resolve(
            new SearchCandidateReference('node:1', 'node'),
            SearchTestPrincipal::create(),
        ));
        self::assertSame(0, $entity->projectionCalls);
    }

    #[Test]
    public function malformed_or_mismatched_entity_references_fail_closed(): void
    {
        $scope = new AccountFieldReadScope();
        $entity = $this->entity($scope);
        $resolver = $this->resolver($entity, $scope, true);
        $principal = SearchTestPrincipal::create();

        self::assertNull($resolver->resolve(new SearchCandidateReference('wrong:1', 'node'), $principal));
        self::assertNull($resolver->resolve(new SearchCandidateReference('node:', 'node'), $principal));
        self::assertNull($resolver->resolve(new SearchCandidateReference('node:1', 'unknown'), $principal));
        self::assertNull($resolver->resolve(new SearchCandidateReference('node:2', 'node'), $principal));
    }

    #[Test]
    public function search_uses_the_served_repository_revision_and_never_the_working_copy(): void
    {
        $scope = new AccountFieldReadScope();
        $entity = $this->entity($scope);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())->method('find')->with('1')->willReturn($entity);
        $repository->expects($this->never())->method('loadWorkingCopy');
        $resolver = $this->resolver($entity, $scope, true, $repository);

        self::assertNotNull($resolver->resolve(
            new SearchCandidateReference('node:1', 'node'),
            SearchTestPrincipal::create(),
        ));
    }

    #[Test]
    public function long_safe_content_is_bounded_without_dropping_the_candidate(): void
    {
        $scope = new AccountFieldReadScope();
        $resolver = $this->resolver(
            $this->entity($scope, body: str_repeat('safe ', 20_000)),
            $scope,
            true,
        );

        $projection = $resolver->resolve(
            new SearchCandidateReference('node:1', 'node'),
            SearchTestPrincipal::create(),
        );

        self::assertNotNull($projection);
        self::assertSame(\Waaseyaa\Search\SearchCandidateProjection::MAX_BODY_LENGTH, mb_strlen($projection->body));
    }

    #[Test]
    public function benign_metadata_variance_is_normalized_without_dropping_authorized_content(): void
    {
        $scope = new AccountFieldReadScope();
        $topics = array_fill(0, 70, str_repeat('topic', 40));
        $resolver = $this->resolver(
            $this->entity(
                $scope,
                body: "safe body \xC3\x28",
                metadata: [
                    'url' => str_repeat('u', 3_000),
                    'quality_score' => 82.6,
                    'topics' => $topics,
                ],
            ),
            $scope,
            true,
        );

        $projection = $resolver->resolve(
            new SearchCandidateReference('node:1', 'node'),
            SearchTestPrincipal::create(),
        );

        self::assertNotNull($projection);
        self::assertTrue(mb_check_encoding($projection->body, 'UTF-8'));
        self::assertSame(\Waaseyaa\Search\SearchCandidateProjection::MAX_METADATA_LENGTH, mb_strlen($projection->url));
        self::assertSame(83, $projection->qualityScore);
        self::assertCount(1, $projection->topics, 'Normalized duplicate topics are deduplicated.');
        self::assertSame(\Waaseyaa\Search\SearchCandidateProjection::MAX_TOPIC_LENGTH, mb_strlen($projection->topics[0]));
    }

    private function resolver(
        SearchIndexableInterface&EntityInterface $entity,
        AccountFieldReadScope $scope,
        bool $allowView,
        ?EntityRepositoryInterface $repository = null,
    ): EntitySearchCandidateResolver {
        if ($repository === null) {
            $repository = $this->createStub(EntityRepositoryInterface::class);
            $repository->method('find')->willReturn($entity);
        }
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $type): bool => $type === 'node');
        $manager->method('getRepository')->willReturn($repository);
        $policy = new class ($allowView) implements AccessPolicyInterface {
            public function __construct(private readonly bool $allowView) {}
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $this->allowView ? AccessResult::allowed() : AccessResult::forbidden('denied');
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden();
            }
            public function appliesTo(string $entityTypeId): bool { return $entityTypeId === 'node'; }
        };

        return new EntitySearchCandidateResolver($manager, new EntityAccessHandler([$policy]), $scope);
    }

    private function entity(
        AccountFieldReadScope $scope,
        bool $denyProjection = false,
        string $body = 'Canonical public body',
        array $metadata = ['content_type' => 'article'],
    ): SearchIndexableInterface&EntityInterface
    {
        return new class ($scope, $denyProjection, $body, $metadata) implements SearchIndexableInterface, EntityInterface {
            public int $projectionCalls = 0;
            public function __construct(
                private readonly AccountFieldReadScope $scope,
                private readonly bool $denyProjection,
                private readonly string $body,
                private readonly array $metadata,
            ) {}
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'uuid-1'; }
            public function label(): string { return 'Raw label must not be used'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
            public function getSearchDocumentId(): string { return 'node:1'; }
            public function toSearchDocument(): array
            {
                ++$this->projectionCalls;
                if ($this->scope->current()?->id() !== 42 && $this->scope->current()?->id() !== 1) {
                    throw new \LogicException('Projection ran outside the supplied principal scope.');
                }
                if ($this->denyProjection) {
                    throw new FieldReadDenied('protected body');
                }

                return ['title' => 'Canonical public title', 'body' => $this->body];
            }
            public function toSearchMetadata(): array { return $this->metadata; }
        };
    }
}
