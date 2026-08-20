<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminRevisionPreviewAuthorityInterface;
use Waaseyaa\AdminSurface\Host\AdminRevisionPreviewGrantData;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\Concurrency\EntityMutationConflictException;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostRevisionRecoveryTest extends TestCase
{
    #[Test]
    public function exact_revision_read_reuses_record_and_field_view_access(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'current-secret'));
        $repository->expects(self::once())->method('loadRevision')->with('1', 1)->willReturn($this->revision(1, 'Historical', 'historical-secret'));

        $result = $this->host($repository)->action('article', 'revision', ['id' => '1', 'revision_id' => 1]);

        self::assertTrue($result->ok, json_encode($result->error));
        self::assertSame(1, $result->data['revisionId']);
        self::assertSame('Historical', $result->data['entity']['attributes']['title']);
        self::assertArrayNotHasKey('secret', $result->data['entity']['attributes']);
        self::assertArrayNotHasKey('mutation_token', $result->data['entity']);
    }

    #[Test]
    public function denied_record_read_exposes_no_revision_oracle(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->expects(self::never())->method('loadRevision');

        $result = $this->host($repository)->action('article', 'revision', ['id' => '1', 'revision_id' => 404], 1);

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
        self::assertNull($result->data);
    }

    #[Test]
    public function restore_delegates_copy_forward_with_both_observed_fences(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $restored = $this->revision(4, 'Historical', 'old-secret', EntityMutationToken::issue('test', 'default', 'article', '1', 8));
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->expects(self::once())->method('loadWorkingCopy')->with('1')->willReturn($current);
        $repository->expects(self::once())->method('loadRevision')->with('1', 1)->willReturn($this->revision(1, 'Historical', 'old-secret'));
        $repository->expects(self::once())->method('rollback')->with('1', 1, $token)->willReturn($restored);

        $result = $this->host($repository)->action('article', 'restore-revision', [
            'id' => '1', 'revision_id' => 1, 'expected_latest_revision_id' => 3,
            'mutation_token' => $token->toOpaqueString(),
        ]);

        self::assertTrue($result->ok, json_encode($result->error));
        self::assertSame(1, $result->data['sourceRevisionId']);
        self::assertSame(4, $result->data['resultingRevisionId']);
        self::assertSame('Historical', $result->data['entity']['attributes']['title']);
        self::assertNotSame($token->toOpaqueString(), $result->data['entity']['mutation_token']);
    }

    #[Test]
    public function stale_latest_revision_refuses_before_rollback(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(4, 'Changed concurrently', 'secret', $token);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->expects(self::never())->method('rollback');

        $result = $this->host($repository)->action('article', 'restore-revision', [
            'id' => '1', 'revision_id' => 1, 'expected_latest_revision_id' => 3,
            'mutation_token' => $token->toOpaqueString(),
        ]);

        self::assertFalse($result->ok);
        self::assertSame(409, $result->error['status']);
    }

    #[Test]
    public function stale_mutation_token_maps_to_comprehensible_conflict(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->method('loadRevision')->willReturn($this->revision(1, 'Historical', 'secret'));
        $repository->method('rollback')->willThrowException(new EntityMutationConflictException('default', 'article', '1'));

        $result = $this->host($repository)->action('article', 'restore-revision', [
            'id' => '1', 'revision_id' => 1, 'expected_latest_revision_id' => 3,
            'mutation_token' => $token->toOpaqueString(),
        ]);

        self::assertFalse($result->ok);
        self::assertSame(409, $result->error['status']);
        self::assertSame('Revision conflict', $result->error['title']);
    }

    #[Test]
    public function restore_cannot_reapply_a_field_the_actor_may_not_edit(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $current->set('roles', ['viewer']);
        $source = $this->revision(1, 'Old', 'secret');
        $source->set('roles', ['administrator']);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->method('loadRevision')->willReturn($source);
        $repository->expects(self::never())->method('rollback');

        $result = $this->host($repository)->action('article', 'restore-revision', [
            'id' => '1', 'revision_id' => 1, 'expected_latest_revision_id' => 3,
            'mutation_token' => $token->toOpaqueString(),
        ]);

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
        self::assertStringContainsString('roles', (string) $result->error['detail']);
    }

    #[Test]
    public function preview_is_omitted_without_an_application_authority(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->expects(self::never())->method('loadRevision');

        $result = $this->host($repository)->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 2]);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
    }

    #[Test]
    public function preview_grant_is_bound_to_the_exact_selected_saved_revision(): void
    {
        $selected = $this->revision(2, 'Selected', 'secret');
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willReturn($selected);
        $authority = $this->createMock(AdminRevisionPreviewAuthorityInterface::class);
        $authority->expects(self::once())->method('issue')
            ->with(self::isInstanceOf(AccountInterface::class), $selected, 2)
            ->willReturn(new AdminRevisionPreviewGrantData(2, '/preview/article/1?revision=2&signature=test'));

        $result = $this->host($repository, $authority)->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 2]);

        self::assertTrue($result->ok, json_encode($result->error));
        self::assertSame(2, $result->data['revisionId']);
        self::assertStringContainsString('revision=2', $result->data['previewUrl']);
    }

    private function host(
        EntityRepositoryInterface $repository,
        ?AdminRevisionPreviewAuthorityInterface $previewAuthority = null,
    ): RevisionRecoveryHarness
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn(new EntityType(id: 'article', label: 'Article', class: TestEntity::class, keys: TestEntity::definitionKeys()));
        $manager->method('resolveFieldDefinitions')->willReturn([]);
        $manager->method('getRepository')->willReturn($repository);

        return new RevisionRecoveryHarness(new GenericAdminSurfaceHost(
            $manager,
            new EntityAccessHandler([$this->policy(99)]),
            revisionPreviewAuthority: $previewAuthority,
        ));
    }

    private function revision(int $revisionId, string $title, string $secret, ?EntityMutationToken $token = null): TestEntity
    {
        $entity = new TestEntity(['id' => 1, 'uuid' => 'u-1', 'title' => $title, 'secret' => $secret], 'article');
        $entity->enforceIsNew(false);
        $entity->_hydrateStructuralRevision($revisionId, tip: true, default: false);
        if ($token !== null) {
            $entity->_hydrateMutationToken($token);
        }

        return $entity;
    }

    private function policy(int $allowedAccountId): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($allowedAccountId) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly int $allowedAccountId) {}
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $account->id() === $this->allowedAccountId ? AccessResult::allowed('record access') : AccessResult::forbidden('record denied');
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::forbidden(); }
            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view' && $fieldName === 'secret') {
                    return AccessResult::forbidden('secret hidden');
                }
                if ($operation === 'edit' && $fieldName === 'roles') {
                    return AccessResult::forbidden('roles protected');
                }

                return AccessResult::neutral();
            }
        };
    }
}

final class RevisionRecoveryHarness
{
    public function __construct(private readonly GenericAdminSurfaceHost $host) {}

    /** @param array<string, mixed> $payload */
    public function action(string $type, string $action, array $payload, int $accountId = 99): AdminSurfaceResultData
    {
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', new AuthorizationPrincipal($accountId, true, ['administrator'], [], 'test'));
        $this->host->resolveSession($request);

        return $this->host->action($type, $action, $payload);
    }
}
