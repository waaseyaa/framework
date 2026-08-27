<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\Tests\Integration\GraphQL\Policy\UpdateWithoutViewPolicy;

/**
 * Framework #2557: identity resolution is access-neutral, so addressing an
 * entity by UUID and by numeric id reach the same authorization decision.
 *
 * {@see EntityResolver::loadEntity()} used to bind the acting account to its
 * UUID lookup, which filters on `view`. The numeric branch
 * ({@see \Waaseyaa\Entity\Repository\EntityRepositoryInterface::find()}) never
 * did. A caller entitled to update an entity it may not view therefore
 * succeeded via `/{id}` and got "Entity not found" via `/{uuid}` — an
 * authorization outcome decided by the shape of the identifier in the request.
 *
 * Runs against real SQLite and the real access-checked SqlEntityQuery: the
 * in-memory query fixture ignores setAccount(), so it cannot observe this.
 */
final class GraphQlUuidIdentityResolutionTest extends GraphQlIntegrationTestBase
{
    private AccountInterface $editor;

    protected function setUp(): void
    {
        parent::setUp();

        // May update, may NOT view — see UpdateWithoutViewPolicy.
        $this->accessHandler = new EntityAccessHandler([new UpdateWithoutViewPolicy()]);
        $this->editor = $this->createAccount(7, ['authenticated', 'editor']);
    }

    public function testUpdateByUuidSucceedsForAnAccountThatMayUpdateButNotView(): void
    {
        $result = $this->resolver()->resolveUpdate('article', $this->articleUuid(), [
            'title' => 'Renamed by uuid',
            'mutationToken' => $this->mutationToken('article', 1),
        ]);

        self::assertSame('Renamed by uuid', $result['title']);
    }

    public function testUpdateByNumericIdIsTheControlAndBehavesIdentically(): void
    {
        $result = $this->resolver()->resolveUpdate('article', '1', [
            'title' => 'Renamed by id',
            'mutationToken' => $this->mutationToken('article', 1),
        ]);

        self::assertSame('Renamed by id', $result['title']);
    }

    public function testResolutionStillLeavesTheViewDecisionToTheGuard(): void
    {
        // Resolution no longer filters on `view`, so the guard is now the only
        // thing standing between a UUID read and the entity. It must still deny.
        self::assertNull($this->resolver()->resolveSingle('article', $this->articleUuid()));
    }

    private function resolver(): EntityResolver
    {
        return new EntityResolver(
            $this->entityTypeManager,
            new GraphQlAccessGuard($this->accessHandler, $this->editor),
            $this->editor,
        );
    }

    private function articleUuid(): string
    {
        $article = $this->storages['article']->find('1');
        self::assertInstanceOf(EntityBase::class, $article);
        $uuid = $article->get('uuid');
        self::assertIsString($uuid);

        return $uuid;
    }
}
