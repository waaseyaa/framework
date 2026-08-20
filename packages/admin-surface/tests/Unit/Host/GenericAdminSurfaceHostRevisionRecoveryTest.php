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

    #[Test]
    public function revision_read_reports_a_type_that_keeps_no_history(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willThrowException(new \LogicException('article has no revision table'));

        $result = $this->host($repository)->action('article', 'revision', ['id' => '1', 'revision_id' => 1]);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('No history', $result->error['title']);
        self::assertStringNotContainsString('revision table', (string) $result->error['detail']);
    }

    #[Test]
    public function revision_read_reports_a_revision_that_does_not_exist(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willReturn(null);

        $result = $this->host($repository)->action('article', 'revision', ['id' => '1', 'revision_id' => 99]);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('Revision not found', $result->error['title']);
    }

    #[Test]
    public function a_revision_request_without_a_record_id_is_refused(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('loadRevision');

        $result = $this->host($repository)->action('article', 'revision', ['revision_id' => 1]);

        self::assertFalse($result->ok);
        self::assertSame(400, $result->error['status']);
        self::assertSame('Missing id', $result->error['title']);
    }

    #[Test]
    public function a_preview_request_without_a_positive_revision_id_is_refused(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('loadRevision');

        $result = $this->host($repository)->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 0]);

        self::assertFalse($result->ok);
        self::assertSame(400, $result->error['status']);
        self::assertSame('Invalid revision', $result->error['title']);
    }

    #[Test]
    public function restoring_an_unknown_record_is_refused_before_storage_is_consulted(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn(null);
        $repository->expects(self::never())->method('loadWorkingCopy');

        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $result = $this->host($repository)->action('article', 'restore-revision', $this->restorePayload($token));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('Not found', $result->error['title']);
    }

    #[Test]
    public function restore_refuses_an_actor_who_may_read_but_not_edit_the_record(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret', $token));
        $repository->expects(self::never())->method('loadWorkingCopy');

        $result = $this->host($repository, deniedOperation: 'update')
            ->action('article', 'restore-revision', $this->restorePayload($token));

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
        self::assertStringContainsString('edit', (string) $result->error['detail']);
    }

    #[Test]
    public function restore_requires_an_observed_latest_revision_id(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret', $token));
        $repository->expects(self::never())->method('rollback');
        $payload = $this->restorePayload($token);
        unset($payload['expected_latest_revision_id']);

        $result = $this->host($repository)->action('article', 'restore-revision', $payload);

        self::assertFalse($result->ok);
        self::assertSame(400, $result->error['status']);
        self::assertSame('Invalid revision fence', $result->error['title']);
    }

    #[Test]
    public function restore_requires_a_mutation_token(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret', $token));
        $repository->expects(self::never())->method('rollback');
        $payload = $this->restorePayload($token);
        unset($payload['mutation_token']);

        $result = $this->host($repository)->action('article', 'restore-revision', $payload);

        self::assertFalse($result->ok);
        self::assertSame(428, $result->error['status']);
    }

    #[Test]
    public function restore_reports_a_working_copy_lookup_without_history(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret', $token));
        $repository->method('loadWorkingCopy')->willThrowException(new \LogicException('article has no revision table'));

        $result = $this->host($repository)->action('article', 'restore-revision', $this->restorePayload($token));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('No history', $result->error['title']);
        self::assertStringNotContainsString('revision table', (string) $result->error['detail']);
    }

    #[Test]
    public function restore_reports_a_source_revision_lookup_without_history(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->method('loadRevision')->willThrowException(new \LogicException('article has no revision table'));

        $result = $this->host($repository)->action('article', 'restore-revision', $this->restorePayload($token));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('No history', $result->error['title']);
    }

    #[Test]
    public function restore_reports_a_missing_source_revision(): void
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->method('loadRevision')->willReturn(null);

        $result = $this->host($repository)->action('article', 'restore-revision', $this->restorePayload($token));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('Revision not found', $result->error['title']);
    }

    #[Test]
    public function a_rollback_rejecting_its_target_is_reported_as_a_missing_revision(): void
    {
        $result = $this->restoreWithFailingRollback(new \InvalidArgumentException('revision 1 is not stored'));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('Revision not found', $result->error['title']);
        self::assertStringNotContainsString('not stored', (string) $result->error['detail']);
    }

    #[Test]
    public function a_rollback_on_a_type_without_history_is_reported_as_no_history(): void
    {
        $result = $this->restoreWithFailingRollback(new \LogicException('article has no revision table'));

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('No history', $result->error['title']);
    }

    #[Test]
    public function preview_reports_a_type_that_keeps_no_history(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willThrowException(new \LogicException('article has no revision table'));

        $result = $this->host($repository, $this->createStub(AdminRevisionPreviewAuthorityInterface::class))
            ->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 2]);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('No history', $result->error['title']);
    }

    #[Test]
    public function preview_reports_a_revision_that_does_not_exist(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willReturn(null);

        $result = $this->host($repository, $this->createStub(AdminRevisionPreviewAuthorityInterface::class))
            ->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 2]);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
        self::assertSame('Revision not found', $result->error['title']);
    }

    #[Test]
    public function a_preview_the_authority_declines_to_grant_is_refused(): void
    {
        $authority = $this->createStub(AdminRevisionPreviewAuthorityInterface::class);
        $authority->method('issue')->willReturn(null);

        $result = $this->previewWithAuthority($authority);

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
        self::assertSame('Preview denied', $result->error['title']);
    }

    /**
     * A grant bound to another revision would show the operator content they
     * did not select, so the host refuses rather than forwarding it.
     */
    #[Test]
    public function a_preview_grant_bound_to_another_revision_is_refused(): void
    {
        $authority = $this->createStub(AdminRevisionPreviewAuthorityInterface::class);
        $authority->method('issue')->willReturn(new AdminRevisionPreviewGrantData(5, '/preview/article/1?revision=5'));

        $result = $this->previewWithAuthority($authority);

        self::assertFalse($result->ok);
        self::assertSame(500, $result->error['status']);
        self::assertSame('Invalid preview grant', $result->error['title']);
    }

    private function restoreWithFailingRollback(\Throwable $failure): AdminSurfaceResultData
    {
        $token = EntityMutationToken::issue('test', 'default', 'article', '1', 7);
        $current = $this->revision(3, 'Current', 'secret', $token);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('loadWorkingCopy')->willReturn($current);
        $repository->method('loadRevision')->willReturn($this->revision(1, 'Historical', 'secret'));
        $repository->method('rollback')->willThrowException($failure);

        return $this->host($repository)->action('article', 'restore-revision', $this->restorePayload($token));
    }

    private function previewWithAuthority(AdminRevisionPreviewAuthorityInterface $authority): AdminSurfaceResultData
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($this->revision(3, 'Current', 'secret'));
        $repository->method('loadRevision')->willReturn($this->revision(2, 'Selected', 'secret'));

        return $this->host($repository, $authority)->action('article', 'revision-preview', ['id' => '1', 'revision_id' => 2]);
    }

    /** @return array<string, mixed> */
    private function restorePayload(EntityMutationToken $token): array
    {
        return [
            'id' => '1',
            'revision_id' => 1,
            'expected_latest_revision_id' => 3,
            'mutation_token' => $token->toOpaqueString(),
        ];
    }

    private function host(
        EntityRepositoryInterface $repository,
        ?AdminRevisionPreviewAuthorityInterface $previewAuthority = null,
        ?string $deniedOperation = null,
    ): RevisionRecoveryHarness
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn(new EntityType(id: 'article', label: 'Article', class: TestEntity::class, keys: TestEntity::definitionKeys()));
        $manager->method('resolveFieldDefinitions')->willReturn([]);
        $manager->method('getRepository')->willReturn($repository);

        return new RevisionRecoveryHarness(new GenericAdminSurfaceHost(
            $manager,
            new EntityAccessHandler([$this->policy(99, $deniedOperation)]),
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

    private function policy(int $allowedAccountId, ?string $deniedOperation = null): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($allowedAccountId, $deniedOperation) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly int $allowedAccountId,
                private readonly ?string $deniedOperation,
            ) {}
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === $this->deniedOperation) {
                    return AccessResult::forbidden('operation denied');
                }

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
