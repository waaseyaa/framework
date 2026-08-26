<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Support\AccountScopedJsonApiController;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\User\AnonymousUser;

/**
 * #2552: the editor projection must be LOSSLESS, so a routine authenticated
 * GET -> modify -> PATCH cannot silently destroy stored markup.
 *
 * The reported defect: an authenticated `GET /api/node/{id}?workingCopy=1`
 * returned `<div><span>Program contacts</span>...</div>` for a body stored as
 * `<div class="sfn-program-contact"><span class="sfn-program-contact-label">
 * ...`, so writing that response back would have stripped the site's
 * component hooks and broken public styling. This file is the HTTP-level
 * regression oracle for the whole contract, over REAL SQLite storage with a
 * real {@see EntityAccessHandler} — the stored-bytes assertions read the
 * `_data` blob with raw SQL, not through the repository accessor, so nothing
 * the read or write path might get wrong can satisfy them.
 *
 * The controller is driven directly (through {@see AccountScopedJsonApiController},
 * which installs the same account/field-read scope request middleware does).
 * That IS the HTTP surface for query parameters: `JsonApiRouter` passes
 * `$ctx->query` to `show()` verbatim (`packages/foundation/src/Http/Router/JsonApiRouter.php:84`),
 * so a query array here is byte-equal to what an HTTP request produces.
 *
 * #[CoversNothing]: a cross-class boundary flow (controller, serializer,
 * sanitizer, access handler, SQL storage), not a single-unit test.
 */
#[CoversNothing]
final class EditorProjectionLosslessFlowTest extends TestCase
{
    /**
     * The issue's own payload (safe nested `class` attributes), plus markup
     * that is legitimate-but-normalized so this constant also proves the
     * sanitized projection is NOT byte-lossless even once `class` survives:
     * `<table><tr>` gains an injected `<tbody>`, and `data-*` is dropped.
     * That is why the lossless projection is a separate representation rather
     * than a wider allowlist.
     */
    private const STORED_BODY =
        '<div class="sfn-program-contact">'
        . '<span class="sfn-program-contact-label">Program contacts</span>'
        . '<a class="sfn-icon" href="/contact-us">Contact us</a>'
        . '</div>'
        . '<table data-sfn-grid="programs"><tr><td>Hours</td></tr></table>';

    /**
     * Public/default JSON:API projection of {@see STORED_BODY}. Pinned to the
     * origin/main {@see \Waaseyaa\Api\Sanitizer\RichTextSanitizer} bytes so a
     * widened allowlist cannot ship as a "better editor" side effect.
     */
    private const PUBLIC_BODY =
        '<div><span>Program contacts</span><a>Contact us</a></div>'
        . '<table><tbody><tr><td>Hours</td></tr></tbody></table>';

    /** Stored markup that must never survive to a public reader. */
    private const UNSAFE_BODY =
        '<p>BEGIN</p><script>alert(document.cookie)</script>'
        . '<img src=x onerror=alert(1)><a href="javascript:alert(2)">x</a>'
        . '<iframe src="https://evil.example/x"></iframe><p>END</p>';

    /** A view-forbidden HTML value that must never reach any wire projection. */
    private const HIDDEN_BODY = '<div data-hidden-marker="never-on-wire">Classified notes</div>';

    // --- The default projection is deliberately unchanged by #2552. ---

    /**
     * The remedy is a gated editor projection, NOT a wider baseline. Loosening
     * the shared allowlist would have widened anonymous JSON:API, GraphQL, the
     * admin surface and the markdown presenter at once — and `class` cannot be
     * admitted without `allowRelativeLinks()`/`allowRelativeMedias()` also
     * admitting protocol-relative `//host/...` URLs, which carry no scheme and
     * so slip past `forceHttpsUrls()`. That would hand any author a tracking
     * pixel on every anonymous reader. The default projection therefore still
     * strips exactly what it stripped before.
     */
    #[Test]
    public function the_default_projection_still_strips_component_classes(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $body = $this->bodyOf($controllers['editor']->show('article', $id));

        self::assertSame(self::PUBLIC_BODY, $body, 'Default show() must stay the origin/main sanitized projection.');
        self::assertSame(
            self::PUBLIC_BODY,
            $this->bodyOf($controllers['anonymous']->show('article', $id)),
            'Anonymous public output must be byte-identical to the shared sanitizer projection.',
        );
        self::assertStringNotContainsString('class="sfn-program-contact"', $body);
        self::assertStringNotContainsString('class="sfn-icon"', $body);
    }

    #[Test]
    public function the_working_copy_projection_is_sanitized_and_therefore_still_not_byte_lossless(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $body = $this->bodyOf($controllers['editor']->show('article', $id, ['workingCopy' => '1']));

        // Widening the allowlist to `class` fixes the reported symptom but
        // cannot deliver a round trip: this is the evidence that the lossless
        // projection had to be a separate representation.
        self::assertNotSame(self::STORED_BODY, $body);
        self::assertStringContainsString('<tbody>', $body, 'The sanitizer injects <tbody>; the sanitized projection is normalizing.');
        self::assertStringNotContainsString('data-sfn-grid', $body, 'The sanitizer drops data-* attributes.');
    }

    // --- The opt-in editor projection. ---

    #[Test]
    public function the_editing_representation_serves_the_stored_body_byte_for_byte(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);

        self::assertSame([], $doc->errors);
        self::assertSame(self::STORED_BODY, $this->bodyOf($doc), 'The editing representation must be the stored value byte-for-byte.');
        self::assertSame(['representation' => 'editing'], $doc->meta);
    }

    /**
     * THE issue's core acceptance criterion: seed the reported body, do an
     * authenticated working-copy GET, PATCH back with ONE unrelated permitted
     * change, and prove the stored body is byte-unchanged — read straight out
     * of SQLite, not through the repository.
     */
    #[Test]
    public function get_editing_then_patch_an_unrelated_field_leaves_the_stored_body_byte_identical(): void
    {
        [$controllers, $db, $id] = $this->seed(self::STORED_BODY);
        $storedBefore = $this->rawStoredBody($db, $id);
        self::assertSame(self::STORED_BODY, $storedBefore, 'sanity: the seed is stored verbatim');

        // 1. The editor reads the mutation-safe representation.
        $getDoc = $controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);
        self::assertSame([], $getDoc->errors);
        \assert($getDoc->data instanceof JsonApiResource);
        $attributes = $getDoc->data->attributes;

        // 2. It changes ONE unrelated permitted field and writes the whole
        //    attribute object back, exactly as a read-modify-write client
        //    (the admin SPA's SchemaForm.vue) does.
        $attributes['title'] = 'Corrected title';
        $patchDoc = $controllers['editor']->update('article', $id, [
            'data' => ['type' => 'article', 'attributes' => $attributes],
        ]);
        self::assertSame(200, $patchDoc->statusCode, 'PATCH must succeed: ' . json_encode($patchDoc->toArray(), JSON_THROW_ON_ERROR));
        \assert($patchDoc->data instanceof JsonApiResource);

        // 3. The unrelated change landed; the body did not move a byte.
        self::assertSame('Corrected title', $patchDoc->data->attributes['title']);
        self::assertSame(
            self::STORED_BODY,
            $this->rawStoredBody($db, $id),
            'A GET(editing) -> PATCH round trip with an unrelated change must not alter the stored body by one byte (#2552).',
        );
        // #2553 is a separate contract: the mutation echo stays rendered.
        self::assertSame(
            self::PUBLIC_BODY,
            $patchDoc->data->attributes['body'],
            'PATCH must not absorb #2553: the mutation response stays the sanitized projection.',
        );
        self::assertSame([], $patchDoc->meta, 'PATCH must not grow a representation meta key in this PR.');
    }

    /**
     * The counterfactual that makes the opt-in worth having: the SAME round
     * trip through the sanitized projection rewrites the stored bytes. This
     * is the destructive behaviour #2552 reports, pinned so a future change
     * cannot quietly make `representation=editing` redundant-looking.
     */
    #[Test]
    public function the_same_round_trip_through_the_sanitized_projection_still_rewrites_stored_bytes(): void
    {
        [$controllers, $db, $id] = $this->seed(self::STORED_BODY);

        $getDoc = $controllers['editor']->show('article', $id, ['workingCopy' => '1']);
        \assert($getDoc->data instanceof JsonApiResource);
        $attributes = $getDoc->data->attributes;
        $attributes['title'] = 'Corrected title';
        $controllers['editor']->update('article', $id, [
            'data' => ['type' => 'article', 'attributes' => $attributes],
        ]);

        self::assertNotSame(
            self::STORED_BODY,
            $this->rawStoredBody($db, $id),
            'Read-modify-write through the SANITIZED projection is lossy — which is why the editor must opt into representation=editing.',
        );
    }

    // --- The gate. Failure is loud; there is never a silent downgrade. ---

    #[Test]
    public function anonymous_cannot_reach_the_editing_representation(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['anonymous']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);

        $this->assertEditingDeniedClosed($doc);
    }

    #[Test]
    public function an_update_denied_account_cannot_reach_the_editing_representation(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        // Sanity: this account CAN read the entity, so the 403 below is about
        // the projection, not about visibility.
        self::assertSame([], $controllers['viewer']->show('article', $id)->errors);

        $doc = $controllers['viewer']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);

        $this->assertEditingDeniedClosed($doc);
    }

    #[Test]
    public function missing_authorization_context_cannot_reach_the_editing_representation(): void
    {
        [, , $id, $unwired] = $this->seed(self::STORED_BODY);

        self::assertSame(
            self::PUBLIC_BODY,
            $this->bodyOf($unwired->show('article', $id)),
            'An unwired controller may still serve the public sanitized projection.',
        );

        $doc = $unwired->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);

        $this->assertEditingDeniedClosed($doc);
    }

    #[Test]
    public function the_editing_representation_without_working_copy_is_a_loud_400(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['editor']->show('article', $id, ['representation' => 'editing']);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data, 'It must NOT silently fall back to the sanitized projection — that is the destructive round trip.');
        self::assertStringContainsString('workingCopy', (string) $doc->errors[0]->detail);
    }

    #[Test]
    public function an_unknown_representation_is_a_loud_400_and_never_a_fallback(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'raw']);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data);
    }

    #[Test]
    public function the_editing_representation_is_refused_on_a_collection(): void
    {
        [$controllers, , ] = $this->seed(self::STORED_BODY);

        // A collection read has no per-entity update gate to hang the
        // projection on, so it must refuse rather than serve `rendered` under
        // a name that promises stored bytes.
        $doc = $controllers['editor']->index('article', ['representation' => 'editing']);

        self::assertSame(400, $doc->statusCode);
    }

    #[Test]
    public function every_single_entity_read_states_which_projection_it_is(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        self::assertSame(['representation' => 'rendered'], $controllers['anonymous']->show('article', $id)->meta);
        self::assertSame(['representation' => 'rendered'], $controllers['editor']->show('article', $id, ['workingCopy' => '1'])->meta);
        self::assertSame(['representation' => 'rendered'], $controllers['editor']->show('article', $id, ['representation' => 'rendered'])->meta);
        self::assertSame(
            ['representation' => 'editing'],
            $controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing'])->meta,
        );
    }

    // --- Fail-closed: the public path is untouched by any of this. ---

    #[Test]
    public function unsafe_stored_markup_is_still_neutralised_on_the_public_path(): void
    {
        [$controllers, , $id] = $this->seed(self::UNSAFE_BODY);

        foreach (['anonymous', 'viewer', 'editor'] as $who) {
            $body = $this->bodyOf($controllers[$who]->show('article', $id));

            self::assertStringNotContainsString('<script', $body, "{$who}: script tag");
            self::assertStringNotContainsString('alert(document.cookie)', $body, "{$who}: script body");
            self::assertStringNotContainsString('onerror', $body, "{$who}: event handler");
            self::assertStringNotContainsString('javascript:', $body, "{$who}: javascript: URL");
            self::assertStringNotContainsString('<iframe', $body, "{$who}: iframe");
            self::assertStringContainsString('<p>BEGIN</p>', $body, "{$who}: safe markup around the payload must survive");
            self::assertStringContainsString('<p>END</p>', $body, "{$who}: safe markup around the payload must survive");
        }
    }

    #[Test]
    public function the_working_copy_projection_is_also_still_fail_closed_without_the_opt_in(): void
    {
        [$controllers, , $id] = $this->seed(self::UNSAFE_BODY);

        $body = $this->bodyOf($controllers['editor']->show('article', $id, ['workingCopy' => '1']));

        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('onerror', $body);
        self::assertStringNotContainsString('javascript:', $body);
        self::assertStringNotContainsString('<iframe', $body);
    }

    /**
     * The named, accepted exposure (#2552 security argument): the editing
     * projection serves stored bytes, unsafe markup included, to a caller
     * that holds both entity `update` and field `edit` for the outgoing HTML
     * — i.e. the authority to write those same bytes. It is a new READ
     * channel, not a new write power, and it is reachable only behind the
     * gates the tests above pin.
     * Pinned deliberately so this stays a decision, not an accident.
     */
    #[Test]
    public function the_editing_representation_deliberately_returns_stored_bytes_including_unsafe_markup(): void
    {
        [$controllers, , $id] = $this->seed(self::UNSAFE_BODY);

        $body = $this->bodyOf($controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']));

        self::assertSame(self::UNSAFE_BODY, $body);
    }

    // --- The projection is not a field-access bypass. ---

    #[Test]
    public function a_viewable_but_noneditable_html_field_blocks_the_editing_projection(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        self::assertSame(
            self::PUBLIC_BODY,
            $this->bodyOf($controllers['limited_editor']->show('article', $id)),
            'Field edit denial must not change the ordinary rendered view projection.',
        );

        $doc = $controllers['limited_editor']->show('article', $id, [
            'workingCopy' => '1',
            'representation' => 'editing',
        ]);

        $this->assertEditingDeniedClosed($doc);

        $patch = $controllers['limited_editor']->update('article', $id, [
            'data' => ['type' => 'article', 'attributes' => ['body' => 'changed']],
        ]);
        self::assertSame(403, $patch->statusCode, 'GET(editing) must enforce the same body-edit boundary as PATCH.');
    }

    #[Test]
    public function an_unrequested_noneditable_html_field_does_not_block_a_sparse_editing_projection(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['limited_editor']->show('article', $id, [
            'workingCopy' => '1',
            'representation' => 'editing',
            'fields' => ['article' => 'title'],
        ]);

        self::assertSame([], $doc->errors);
        \assert($doc->data instanceof JsonApiResource);
        self::assertSame(['title' => 'Program contacts'], $doc->data->attributes);
        self::assertSame(['representation' => 'editing'], $doc->meta);
    }

    #[Test]
    public function a_non_html_edit_denial_does_not_block_the_lossless_html_projection(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['title_limited_editor']->show('article', $id, [
            'workingCopy' => '1',
            'representation' => 'editing',
        ]);

        self::assertSame([], $doc->errors);
        self::assertSame(self::STORED_BODY, $this->bodyOf($doc));
        \assert($doc->data instanceof JsonApiResource);
        self::assertSame('Program contacts', $doc->data->attributes['title']);

        $patch = $controllers['title_limited_editor']->update('article', $id, [
            'data' => ['type' => 'article', 'attributes' => ['title' => 'changed']],
        ]);
        self::assertSame(403, $patch->statusCode, 'The control must genuinely lack field-edit access to title.');
    }

    #[Test]
    public function the_editing_representation_does_not_widen_field_access(): void
    {
        [$controllers, , $id] = $this->seed(self::STORED_BODY);

        $doc = $controllers['editor']->show('article', $id, ['workingCopy' => '1', 'representation' => 'editing']);
        \assert($doc->data instanceof JsonApiResource);

        self::assertArrayNotHasKey(
            'secret',
            $doc->data->attributes,
            'A field the field-access policy forbids must stay omitted in the editing projection too.',
        );
        self::assertArrayNotHasKey(
            'hidden_body',
            $doc->data->attributes,
            'A view-forbidden HTML field is outside the effective outgoing projection and must stay omitted.',
        );
        self::assertStringNotContainsString(
            'never-on-wire',
            json_encode($doc->toArray(), JSON_THROW_ON_ERROR),
            'Lossless serialization must not reintroduce a view-forbidden HTML value.',
        );
        self::assertArrayHasKey('body', $doc->data->attributes);
    }

    // --- Harness. ---

    /**
     * @return array{0: array<string, AccountScopedJsonApiController>, 1: DBALDatabase, 2: string, 3: JsonApiController}
     */
    private function seed(string $body): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            new SqlSchemaHandler($definition, $db)->ensureTable();
            $resolver = new SingleConnectionResolver($db);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            // text_long is the HTML-bearing "richtext" type — the only type
            // RichTextSanitizer gates, and the one the defect targets.
            _fieldDefinitions: [
                'body' => new FieldDefinition(name: 'body', type: 'text_long'),
                'hidden_body' => new FieldDefinition(name: 'hidden_body', type: 'text_long'),
            ],
        ));

        $entity = new TestEntity([
            'title' => 'Program contacts',
            'type' => 'article',
            'body' => $body,
            'hidden_body' => self::HIDDEN_BODY,
            'secret' => 'not for the wire',
        ]);
        $entity->enforceIsNew();
        $entityTypeManager->getRepository('article')->save($entity);
        $id = (string) $entity->id();

        $accessHandler = new EntityAccessHandler([$this->articlePolicy()]);
        $controllers = [];
        foreach ([
            'anonymous' => new AnonymousUser(),
            'viewer' => $this->account(21, []),
            'editor' => $this->account(22, ['edit article']),
            'limited_editor' => $this->account(23, ['edit article']),
            'title_limited_editor' => $this->account(24, ['edit article']),
        ] as $name => $account) {
            $controllers[$name] = new AccountScopedJsonApiController(
                new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $account),
                $accessHandler,
                $account,
            );
        }

        $unwired = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
        );

        return [$controllers, $db, $id, $unwired];
    }

    /**
     * Read the stored body straight out of the `_data` blob with raw SQL, so
     * a stored-bytes assertion cannot be satisfied by anything the read path
     * itself might normalize.
     */
    private function rawStoredBody(DBALDatabase $db, string $id): string
    {
        $row = $db->getConnection()->fetchAssociative('SELECT _data FROM article WHERE id = ?', [$id]);
        self::assertIsArray($row, 'Stored row must exist.');
        $data = json_decode((string) $row['_data'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['body'] ?? null);

        return $data['body'];
    }

    private function assertEditingDeniedClosed(\Waaseyaa\Api\JsonApiDocument $document): void
    {
        self::assertSame(403, $document->statusCode, 'Editing projection must fail closed without its required access.');
        self::assertNull($document->data, 'A denied editing request must carry no resource at all, sanitized or otherwise.');
        $wire = json_encode($document->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'sfn-program-contact',
            $wire,
            'A 403 must never leak stored HTML, including in error details.',
        );
        self::assertStringNotContainsString(self::STORED_BODY, $wire);
        self::assertStringNotContainsString(
            'body',
            $wire,
            'The denial must not identify which field caused the whole-request refusal.',
        );
    }

    private function bodyOf(\Waaseyaa\Api\JsonApiDocument $document): string
    {
        self::assertSame([], $document->errors, 'Expected a successful read: ' . json_encode($document->toArray(), JSON_THROW_ON_ERROR));
        \assert($document->data instanceof JsonApiResource);
        self::assertIsString($document->data->attributes['body'] ?? null);

        return $document->data->attributes['body'];
    }

    /**
     * View is open (published-content shaped); `update` requires the
     * `edit article` permission. The editing projection additionally requires
     * field edit access for its outgoing HTML fields. `secret` and
     * `hidden_body` are field-view Forbidden for everyone, pinning that the
     * lossless projection is not a field-access bypass.
     */
    private function articlePolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        // Anonymous classes, not createMock(): PHPUnit cannot mock an
        // intersection type (CLAUDE.md, Testing gotchas).
        return new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return match ($operation) {
                    'view' => AccessResult::allowed(),
                    'update' => $account->hasPermission('edit article') ? AccessResult::allowed() : AccessResult::forbidden(),
                    default => AccessResult::neutral(),
                };
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'body' && $operation === 'edit' && (string) $account->id() === '23') {
                    return AccessResult::forbidden('This editor may update the entity but not its body.');
                }

                if ($fieldName === 'title' && $operation === 'edit' && (string) $account->id() === '24') {
                    return AccessResult::forbidden('This editor may update the entity but not its title.');
                }

                return \in_array($fieldName, ['secret', 'hidden_body'], true)
                    ? AccessResult::forbidden()
                    : AccessResult::neutral();
            }
        };
    }

    /** @param list<string> $permissions */
    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements \Waaseyaa\Access\AuthorizationPrincipalInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string
            {
                return $this->accountId;
            }
            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, $this->permissions, true);
            }
            public function getRoles(): array
            {
                return [];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
            public function claimsGeneration(): string
            {
                return 'editor-projection-test';
            }
            public function tenantId(): ?string
            {
                return null;
            }
            public function communityId(): ?string
            {
                return null;
            }
        };
    }
}
