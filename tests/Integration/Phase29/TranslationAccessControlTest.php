<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase29;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\TranslationController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TranslatableTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Integration test: TranslationController access gate (WP01, closes #1445).
 *
 * Boots the controller with a real EntityTypeManager, InMemoryEntityStorage,
 * and an EntityAccessHandler backed by a configurable policy. Verifies that:
 *
 *  SC-001: PATCH denied returns 403, entity left unmodified.
 *  SC-002: Anonymous (no _account) denied returns 403.
 *  SC-003: Editor (allowed) can PATCH and entity is updated.
 */
#[CoversNothing]
final class TranslationAccessControlTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn(\Waaseyaa\Entity\EntityTypeInterface $definition) => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TranslatableTestEntity::class,
            keys: TranslatableTestEntity::definitionKeys(),
            translatable: true,
        ));
    }

    /**
     * SC-001: A viewer-only account is denied update → 403, entity unmodified.
     */
    #[Test]
    public function patchDeniedReturnsForbiddenAndEntityUnmodified(): void
    {
        $entity = $this->createEntityWithFrTranslation('Hello', 'Bonjour');
        $entityId = $entity->id();

        // Policy: allows 'view', denies 'update' for the viewer account.
        $viewerAccount = $this->makeAccount(id: 10, roles: ['viewer']);
        $controller = $this->makeController(
            policy: $this->makePolicyDenyUpdateForAccount($viewerAccount->id()),
        );

        $data = ['data' => ['attributes' => ['title' => 'Hacked']]];
        $request = $this->makeRequest($viewerAccount);

        $doc = $controller->update($request, 'article', $entityId, 'fr', $data);
        $array = $doc->toArray();

        // Assert 403 with FORBIDDEN code.
        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('403', $array['errors'][0]['status']);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);

        // Assert entity unmodified: reload and check the fr translation title.
        /** @var TranslatableTestEntity $reloaded */
        $reloaded = $this->storage->load($entityId);
        $this->assertNotNull($reloaded);
        $frTranslation = $reloaded->getTranslation('fr');
        $this->assertSame('Bonjour', $frTranslation->get('title'), 'fr translation must be unmodified after denied PATCH');
    }

    /**
     * SC-002: No _account attribute on request → 403 (anonymous denial).
     */
    #[Test]
    public function indexDeniedForAnonymousReturns403(): void
    {
        $entity = $this->createEntityWithFrTranslation('Hello', 'Bonjour');

        // Policy that denies view for any account — but absence of _account triggers
        // the null-account guard in checkAccess() before the policy is consulted.
        $controller = $this->makeController(
            policy: $this->makeAlwaysAllowPolicy(),
        );

        // No _account set on request — simulates missing SessionMiddleware.
        $request = new Request();

        $doc = $controller->index($request, 'article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
    }

    /**
     * SC-003: Editor account (allowed) can PATCH and entity is updated.
     */
    #[Test]
    public function patchAllowedForEditorProceedsNormally(): void
    {
        $entity = $this->createEntityWithFrTranslation('Hello', 'Bonjour');
        $entityId = $entity->id();

        $editorAccount = $this->makeAccount(id: 20, roles: ['editor']);
        $controller = $this->makeController(
            policy: $this->makeAlwaysAllowPolicy(),
        );

        $data = ['data' => ['attributes' => ['title' => 'Au revoir']]];
        $request = $this->makeRequest($editorAccount);

        $doc = $controller->update($request, 'article', $entityId, 'fr', $data);
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('data', $array);

        // Reload and verify the mutation persisted.
        /** @var TranslatableTestEntity $reloaded */
        $reloaded = $this->storage->load($entityId);
        $this->assertNotNull($reloaded);
        $frTranslation = $reloaded->getTranslation('fr');
        $this->assertSame('Au revoir', $frTranslation->get('title'));
    }

    // --- Helpers ---

    private function createEntityWithFrTranslation(string $enTitle, string $frTitle): TranslatableTestEntity
    {
        $entity = new TranslatableTestEntity(
            values: ['title' => $enTitle, 'langcode' => 'en'],
            entityTypeId: 'article',
        );
        $fr = $entity->addTranslation('fr');
        $fr->set('title', $frTitle);
        $this->storage->save($entity);

        return $entity;
    }

    private function makeController(AccessPolicyInterface $policy): TranslationController
    {
        $handler = new EntityAccessHandler([$policy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        return new TranslationController(
            $this->entityTypeManager,
            $handler,
            $serializer,
        );
    }

    private function makeRequest(AccountInterface $account): Request
    {
        $request = new Request();
        $request->attributes->set('_account', $account);

        return $request;
    }

    /**
     * @param string[] $roles
     */
    private function makeAccount(int $id, array $roles = []): AccountInterface
    {
        return new AuthorizationPrincipal($id, $id !== 0, $roles, [], 'test-' . $id);
    }

    /**
     * Policy that allows everything.
     */
    private function makeAlwaysAllowPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };
    }

    /**
     * Policy that allows view for all, but denies update for a specific account ID.
     *
     * @param int|string $deniedAccountId
     */
    private function makePolicyDenyUpdateForAccount(int|string $deniedAccountId): AccessPolicyInterface
    {
        return new class ($deniedAccountId) implements AccessPolicyInterface {
            public function __construct(private int|string $deniedAccountId) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'update' && $account->id() === $this->deniedAccountId) {
                    return AccessResult::forbidden('Viewer accounts cannot update entities.');
                }

                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };
    }
}
