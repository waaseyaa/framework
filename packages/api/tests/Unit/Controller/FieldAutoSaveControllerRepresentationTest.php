<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * #2553 on the per-field auto-save surface: the echo is sanitized by default,
 * lossless only on the explicit opt-in, and always says which it is.
 *
 * The sanitized echo is a real output boundary (a stored `<script>` must not
 * reach a caller that will render it), but it also makes the NEXT auto-save
 * destructive for a client that keeps the response as its editor state. The
 * opt-in resolves that without weakening the default.
 */
#[CoversClass(FieldAutoSaveController::class)]
final class FieldAutoSaveControllerRepresentationTest extends TestCase
{
    /** Markup the sanitizer normalizes: `class` and `data-*` are both dropped. */
    private const string STORED_HTML = '<div class="callout" data-kind="note"><p>hi</p></div>';

    private const string SANITIZED_HTML = '<div><p>hi</p></div>';

    #[Test]
    public function the_default_echo_is_sanitized_and_states_the_rendered_projection(): void
    {
        $body = $this->autoSave(self::STORED_HTML, query: []);

        self::assertSame(self::SANITIZED_HTML, $body['data']['attributes']['body']);
        self::assertSame('rendered', $body['data']['meta']['representation']);
    }

    #[Test]
    public function the_editing_opt_in_echoes_the_stored_bytes_and_states_the_projection(): void
    {
        $body = $this->autoSave(self::STORED_HTML, query: ['representation' => 'editing']);

        self::assertSame(self::STORED_HTML, $body['data']['attributes']['body']);
        self::assertSame('editing', $body['data']['meta']['representation']);
    }

    /**
     * Unlike JSON:API's controller this surface does NOT 400 on an unknown
     * value: it serves one field the caller just wrote under the field-`edit`
     * gate, and refusing a completed write over a query-string typo would be
     * worse than ignoring the typo. An unrecognized value is simply not the
     * opt-in — and, critically, never a silent lossless echo.
     */
    #[Test]
    public function an_unrecognized_representation_is_not_the_opt_in(): void
    {
        $body = $this->autoSave(self::STORED_HTML, query: ['representation' => 'raw']);

        self::assertSame(self::SANITIZED_HTML, $body['data']['attributes']['body']);
        self::assertSame('rendered', $body['data']['meta']['representation']);
    }

    /**
     * The opt-in selects a projection, never an authorization outcome: a
     * caller the access handler denies gets nothing at all.
     */
    #[Test]
    public function the_opt_in_cannot_reach_a_field_the_caller_may_not_edit(): void
    {
        $denyAll = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('denied');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('denied');
            }
        };

        $response = $this->respond(self::STORED_HTML, ['representation' => 'editing'], new EntityAccessHandler([$denyAll]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('callout', (string) $response->getContent());
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function autoSave(string $value, array $query): array
    {
        $response = $this->respond($value, $query, $this->allowAllHandler());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $query */
    private function respond(
        string $value,
        array $query,
        EntityAccessHandler $accessHandler,
    ): \Symfony\Component\HttpFoundation\Response {
        $entity = new TestEntity(['id' => 1, 'uuid' => 'u-1', 'body' => ''], 'article');
        $entity->enforceIsNew(false);
        $entity->_hydrateMutationToken(EntityMutationToken::issue('autosave-test', 'default', 'article', '1', 1));

        $controller = new FieldAutoSaveController(
            $this->entityTypeManager($entity),
            $accessHandler,
            $this->fieldRegistry(),
        );

        // The query string belongs in the URI: Request::create() routes its
        // $parameters argument to the REQUEST bag for a PUT, so a query param
        // passed there would never reach ->query.
        $request = Request::create(
            '/api/article/1/field/body' . ($query === [] ? '' : '?' . http_build_query($query)),
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['value' => $value], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', new AuthorizationPrincipal(1, true, ['administrator'], [], 'test'));
        $request->headers->set('If-Match', $entity->mutationToken()?->toStrongEtag() ?? '');

        return $controller->update($request, 'article', '1', 'body');
    }

    private function allowAllHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        }]);
    }

    private function fieldRegistry(): FieldDefinitionRegistry
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('article', 'article', [
            // text_long is the HTML-bearing "richtext" type RichTextSanitizer gates.
            new FieldDefinition(
                name: 'body',
                type: 'text_long',
                targetEntityTypeId: 'article',
                targetBundle: 'article',
                label: 'Body',
            ),
        ]);

        return $registry;
    }

    private function entityTypeManager(EntityInterface $entity): EntityTypeManagerInterface
    {
        // A mock, not a hand-rolled double: EntityRepositoryInterface is wide
        // and still moving, and a stale hand-rolled signature fails as a fatal
        // rather than a test failure.
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $repository->method('loadWorkingCopy')->willReturn($entity);

        return new class ($repository) implements EntityTypeManagerInterface {
            public function __construct(private readonly EntityRepositoryInterface $repository) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id']);
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
            {
                return [];
            }

            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

            public function getDefinitions(): array
            {
                return [];
            }

            public function hasDefinition(string $entityTypeId): bool
            {
                return true;
            }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \LogicException('not needed');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                return $this->repository;
            }
        };
    }
}
