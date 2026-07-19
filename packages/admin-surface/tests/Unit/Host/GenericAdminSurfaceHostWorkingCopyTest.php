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
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4 item 3): `GenericAdminSurfaceHost::get()`
 * serves the WORKING COPY to accounts with entity UPDATE access —
 * "unconditional for editors" (documented choice, see the method's own
 * docblock): the admin SPA's single edit-surface page reuses this one GET
 * for both its view and edit sub-modes, so there is no per-request signal to
 * gate a query-param toggle on (unlike JSON:API's `?workingCopy=1`).
 * `handleUpdate()`/`action('update', ...)` delegates entirely to
 * `JsonApiController::update()` (already covered by
 * `WorkingCopyPointerAwarenessFlowTest`), so this file only pins that the
 * delegation carries the new target through automatically.
 *
 * @covers \Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostWorkingCopyTest extends TestCase
{
    #[Test]
    public function get_serves_the_working_copy_to_an_account_with_update_access(): void
    {
        $result = $this->runGet(accountId: 99, gateTitle: 'Published title', workingCopyTitle: 'Draft title');

        $this->assertTrue($result->ok);
        $this->assertSame('Draft title', $result->data['attributes']['title']);
    }

    #[Test]
    public function get_serves_the_published_entity_to_a_view_only_account(): void
    {
        $result = $this->runGet(accountId: 1, gateTitle: 'Published title', workingCopyTitle: 'Draft title');

        $this->assertTrue($result->ok);
        $this->assertSame('Published title', $result->data['attributes']['title']);
    }

    #[Test]
    public function handle_update_lands_on_the_working_copy_via_json_api_controller_delegation(): void
    {
        $gateEntity = $this->entity(1, 'Published title');
        $workingCopy = $this->entity(1, 'Draft title');

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($gateEntity);
        $repository->method('loadWorkingCopy')->willReturn($workingCopy);
        $repository->expects($this->once())->method('save')->with($workingCopy);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn(new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id', 'label' => 'title']));
        $etm->method('getRepository')->willReturn($repository);

        $accessHandler = new EntityAccessHandler([$this->updateGatedPolicy(editorId: 99)]);
        $host = new GenericAdminSurfaceHost($etm, $accessHandler);
        $this->resolveSessionAs($host, 99);

        $result = $host->action('article', 'update', ['id' => '1', 'attributes' => ['title' => 'Edited via admin surface']]);

        $this->assertTrue($result->ok, json_encode($result->error));
        $this->assertSame('Edited via admin surface', $result->data['attributes']['title']);
    }

    private function runGet(int $accountId, string $gateTitle, string $workingCopyTitle): \Waaseyaa\AdminSurface\Host\AdminSurfaceResultData
    {
        $gateEntity = $this->entity(1, $gateTitle);
        $workingCopy = $this->entity(1, $workingCopyTitle);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($gateEntity);
        $repository->method('loadWorkingCopy')->willReturn($workingCopy);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);

        $accessHandler = new EntityAccessHandler([$this->updateGatedPolicy(editorId: 99)]);
        $host = new GenericAdminSurfaceHost($etm, $accessHandler);
        $this->resolveSessionAs($host, $accountId);

        return $host->get('article', '1');
    }

    private function resolveSessionAs(GenericAdminSurfaceHost $host, int $accountId): void
    {
        $account = new AuthorizationPrincipal($accountId, true, ['administrator'], [], 'test');
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);
    }

    /**
     * View always allowed; UPDATE allowed only for $editorId — the
     * "editor vs. view-only" distinction the working-copy swap decision
     * gates on.
     */
    private function updateGatedPolicy(int $editorId): AccessPolicyInterface
    {
        return new class ($editorId) implements AccessPolicyInterface {
            public function __construct(private readonly int $editorId) {}
            public function appliesTo(string $entityTypeId): bool { return true; }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return AccessResult::allowed('view always granted for this fixture');
                }
                if ($operation === 'update' && $account->id() === $this->editorId) {
                    return AccessResult::allowed('editor');
                }

                return AccessResult::neutral('no opinion');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };
    }

    private function entity(int $id, string $title): EntityInterface&FieldableInterface
    {
        return new class ($id, $title) implements EntityInterface, FieldableInterface {
            private array $values;
            public function __construct(int $id, string $title) { $this->values = ['id' => $id, 'title' => $title]; }
            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'u-' . (string) $this->values['id']; }
            public function label(): string { return $this->values['title']; }
            public function getEntityTypeId(): string { return 'article'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { $this->values[$name] = $value; return $this; }
            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
            public function hasField(string $name): bool { return \array_key_exists($name, $this->values); }
            public function getFieldDefinitions(): array { return []; }
        };
    }
}
