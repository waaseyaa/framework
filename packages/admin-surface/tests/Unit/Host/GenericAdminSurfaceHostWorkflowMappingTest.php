<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): `GenericAdminSurfaceHost::action()`
 * ('create'/'update') delegates entirely to `JsonApiController::store()`/
 * `update()`, which already catches `TransitionDeniedException` internally
 * (WP2 rework, review finding #8) and returns it as a `JsonApiDocument` error
 * — never an uncaught throw. `jsonApiDocumentToSurfaceError()` then forwards
 * ANY JsonApiError's status/title/detail generically. Verified here: unlike
 * `FieldAutoSaveController`/GraphQL `EntityResolver`, which called
 * `$repository->save()` directly with no denial handling of their own, this
 * surface was ALREADY safe before PR-2 by construction — this test PINS
 * that finding (not a behavior change) so a future refactor that bypasses
 * `JsonApiController` cannot silently reopen finding #8 here.
 *
 * @covers \Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostWorkflowMappingTest extends TestCase
{
    #[Test]
    public function create_maps_a_permission_denial_to_a_403_result_never_an_uncaught_exception(): void
    {
        $result = $this->runActionWithDeniedSave(
            'create',
            new TransitionDeniedException(TransitionDeniedException::REASON_PERMISSION, 'Account lacks the required permission.'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame(403, $result->error['status']);
    }

    #[Test]
    public function update_maps_an_illegal_edge_denial_to_a_422_result_never_an_uncaught_exception(): void
    {
        $result = $this->runActionWithDeniedSave(
            'update',
            new TransitionDeniedException(TransitionDeniedException::REASON_ILLEGAL_EDGE, 'No transition for that edge.'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame(422, $result->error['status']);
    }

    private function runActionWithDeniedSave(string $action, TransitionDeniedException $denial): \Waaseyaa\AdminSurface\Host\AdminSurfaceResultData
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('id')->willReturn(1);
        $entity->method('uuid')->willReturn('u-1');
        $entity->method('getEntityTypeId')->willReturn('article');
        $entity->method('bundle')->willReturn('article');

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $repository->method('create')->willReturn($entity);
        $repository->method('save')->willThrowException($denial);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        // 'title' must be a writable entity key (the `label` kind) so
        // CW-v1 option-1 PR-4's EntityWritePayloadGuard passes it through —
        // otherwise the write-allowlist's own "not writable" refusal fires
        // before save() is ever reached, masking the TransitionDeniedException
        // mapping this test actually pins.
        $etm->method('getDefinition')->willReturn(new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id', 'label' => 'title']));
        $etm->method('getRepository')->willReturn($repository);

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn(AccessResult::allowed('ok'));
        $accessHandler->method('checkCreateAccess')->willReturn(AccessResult::allowed('ok'));
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral('ok'));

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        return $host->action('article', $action, ['id' => '1', 'attributes' => ['title' => 'New value']]);
    }
}
