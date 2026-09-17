<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AdminSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceUiPayload;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\PageBuilder\GenericPageBuilderSurfaceHost;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionMetadata;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;
use Waaseyaa\Testing\Factory\EntityTypeFactory;

/**
 * Cross-boundary verification: backend-emitted admin-surface payloads
 * conform to the TypeScript contract at
 * `packages/admin-surface/contract/types.ts`.
 *
 * The audit (#842) flagged that backend DTOs and frontend behaviour were
 * tested in isolation, leaving the published host-to-SPA boundary itself
 * uncovered. This test parses the canonical TypeScript interfaces and
 * cross-validates every emitted PHP payload key against them, so drift
 * on either side breaks the test:
 *
 * - **Gap (PHP omits required field).** Every non-optional TS interface
 *   key must appear in the PHP payload.
 * - **Drift (PHP emits unknown field).** Every PHP payload key must have
 *   a corresponding TS interface field.
 *
 * Closes #842 (and protects #839 + #840).
 */
#[CoversNothing]
final class AdminSurfaceContractConformanceTest extends TestCase
{
    private const CONTRACT_DIRECTORY = __DIR__ . '/../../../packages/admin-surface/contract';

    /** @var array<string, array<string, bool>>|null */
    private static ?array $contractCache = null;

    #[Test]
    public function sessionPayloadConformsToAdminSurfaceSessionInterface(): void
    {
        $payload = $this->buildFullSession()->toArray();

        $this->assertConformsToInterface('AdminSurfaceSession', $payload);
        $this->assertConformsToInterface('AdminSurfaceAccount', $payload['account']);
        $this->assertConformsToInterface('AdminSurfaceTenant', $payload['tenant']);
        $this->assertConformsToInterface('AdminSurfaceUiCustomization', $payload['ui']);
        $this->assertConformsToInterface('AdminSurfaceHeaderLink', $payload['ui']['headerLinks'][0]);
        $this->assertConformsToInterface('AdminSurfaceSidebarItem', $payload['ui']['sidebarItems'][0]);
    }

    #[Test]
    public function emailVerifiedFlowsThroughSessionPayloadAsContractField(): void
    {
        $verified = $this->buildFullSession()->toArray();
        self::assertArrayHasKey('emailVerified', $verified['account']);
        self::assertTrue($verified['account']['emailVerified']);

        $unverified = $this->buildSessionWithoutEmailVerification()->toArray();
        self::assertArrayHasKey('emailVerified', $unverified['account']);
        self::assertNull($unverified['account']['emailVerified']);
    }

    #[Test]
    public function catalogEntryConformsToAdminSurfaceCatalogEntryInterface(): void
    {
        $builder = new CatalogBuilder();
        $entity = $builder->defineEntity('node', 'Content')
            ->group('content')
            ->description('Long-form articles, blog posts, and pages.');
        $entity->field('title', 'Title', 'string')->required()->widget('text')->weight(2);
        $entity->action('publish', 'Publish')->confirm('Publish this content?')->dangerous();

        $entries = $builder->build();
        self::assertCount(1, $entries);

        $this->assertConformsToInterface('AdminSurfaceCatalogEntry', $entries[0]);
        $this->assertConformsToInterface('AdminSurfaceCapabilities', $entries[0]['capabilities']);
        $this->assertConformsToInterface('AdminSurfaceField', $entries[0]['fields'][0]);
        $this->assertConformsToInterface('AdminSurfaceAction', $entries[0]['actions'][0]);
        $this->assertConformsToInterface(
            'AdminSurfaceCatalog',
            AdminSurfaceResultData::success(['entities' => $entries])->toArray()['data'],
        );
    }

    #[Test]
    public function descriptionFlowsThroughCatalogPayloadAsContractField(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('node', 'Content')->description('Editorial content.');

        $entries = $builder->build();
        self::assertSame('Editorial content.', $entries[0]['description']);
    }

    #[Test]
    public function result_error_and_advisory_metadata_conform_to_the_canonical_contract(): void
    {
        $success = AdminSurfaceResultData::success(['deleted' => true])->toArray();
        $this->assertConformsToInterface('AdminSurfaceResult', $success);
        $this->assertConformsToInterface('AdminSurfaceDeleteData', $success['data']);

        $failure = AdminSurfaceResultData::fromJsonApiError(new JsonApiError(
            status: '428',
            title: 'Precondition Required',
            detail: 'Review the advisory.',
            code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            source: ['pointer' => '/data/attributes/title'],
            meta: ['save_advisories' => [[
                'code' => 'RESERVED_ROUTE_VALUE',
                'field' => 'title',
                'severity' => 'warning',
                'message' => 'Choose another route.',
                'acknowledgement' => str_repeat('a', 64),
                'private_reason' => 'must-not-cross',
            ]], 'private_key' => 'must-not-cross'],
        ), 500)->toArray();

        $this->assertConformsToInterface('AdminSurfaceResult', $failure);
        $this->assertConformsToInterface('AdminSurfaceError', $failure['error']);
        $this->assertConformsToInterface('AdminSurfaceErrorMeta', $failure['error']['meta']);
        $this->assertConformsToInterface('AdminSurfaceSaveAdvisory', $failure['error']['meta']['save_advisories'][0]);
        self::assertArrayNotHasKey('private_key', $failure['error']['meta']);
        self::assertArrayNotHasKey('private_reason', $failure['error']['meta']['save_advisories'][0]);
    }

    #[Test]
    public function generic_host_entity_list_and_schema_outputs_conform_to_canonical_interfaces(): void
    {
        $entity = new TestEntity(['id' => 1, 'uuid' => 'uuid-1', 'title' => 'A title'], 'article');
        $entity->_hydrateMutationToken(EntityMutationToken::issue('admin-test', 'default', 'article', '1', 1));
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn(new EntityType(
            id: 'article', label: 'Article', class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: ['title' => ['type' => 'string', 'label' => 'Title']],
        ));
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('resolveFieldDefinitions')->willReturn([]);

        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('check')->willReturn(AccessResult::allowed('fixture'));
        $access->method('filterFields')->willReturnCallback(static fn(EntityInterface $subject, array $fields): array => $fields);
        $access->method('checkFieldAccess')->willReturn(AccessResult::neutral('fixture'));
        $host = new GenericAdminSurfaceHost($manager, $access);
        $this->authenticate($host);

        $entityEnvelope = $host->get('article', '1')->toArray();
        $this->assertConformsToInterface('AdminSurfaceResult', $entityEnvelope);
        $this->assertConformsToInterface('AdminSurfaceEntity', $entityEnvelope['data']);
        self::assertArrayHasKey('mutation_token', $entityEnvelope['data']);
        self::assertIsString($entityEnvelope['data']['mutation_token']);

        // The fail-closed empty-list path still proves the producer's complete
        // pagination envelope without requiring a second authorization fixture.
        $listEnvelope = (new GenericAdminSurfaceHost($manager))->list('article')->toArray();
        $this->assertConformsToInterface('AdminSurfaceResult', $listEnvelope);
        $this->assertConformsToInterface('AdminSurfaceListResult', $listEnvelope['data']);

        $schemaManager = $this->createStub(EntityTypeManagerInterface::class);
        $schemaManager->method('hasDefinition')->willReturn(true);
        $schemaManager->method('getDefinition')->willReturn(EntityTypeFactory::create(
            id: 'article', class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            label: 'Article', fieldDefinitions: ['title' => ['type' => 'string', 'label' => 'Title']],
        ));
        $schemaManager->method('getDefinitions')->willReturn([]);
        $schemaManager->method('resolveFieldDefinitions')->willReturn([]);
        $schemaEnvelope = (new GenericAdminSurfaceHost($schemaManager))->action('article', 'schema')->toArray();
        $this->assertConformsToInterface('AdminSurfaceResult', $schemaEnvelope);
        $this->assertConformsToInterface('AdminSurfaceEntitySchema', $schemaEnvelope['data']);
        $this->assertConformsToInterface('AdminSurfaceSchemaProperty', $schemaEnvelope['data']['properties']['title']);
        self::assertSame('article', $schemaEnvelope['data']['x-entity-type']);
    }

    private function authenticate(GenericAdminSurfaceHost $host): void
    {
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', new AuthorizationPrincipal(1, true, ['administrator'], [], 'test'));
        $host->resolveSession($request);
    }

    #[Test]
    public function generic_host_history_output_conforms_to_revision_contract(): void
    {
        $current = $this->revisionEntity(2, true, true);
        $prior = $this->revisionEntity(1, false, false);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($current);
        $repository->method('listRevisions')->willReturn([$current, $prior]);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn(new EntityType(
            id: 'article', label: 'Article', class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $manager->method('getRepository')->willReturn($repository);
        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('check')->willReturn(AccessResult::allowed('fixture'));
        $host = new GenericAdminSurfaceHost($manager, $access);
        $this->authenticate($host);

        $envelope = $host->action('article', 'history', ['id' => '1'])->toArray();
        $this->assertConformsToInterface('AdminSurfaceResult', $envelope);
        $this->assertConformsToInterface('AdminSurfaceHistoryData', $envelope['data']);
        $this->assertConformsToInterface('AdminSurfaceRevisionEntry', $envelope['data']['revisions'][0]);
        self::assertSame('article', $envelope['data']['entityType']);
        self::assertSame('1', $envelope['data']['entityId']);
    }

    #[Test]
    public function page_builder_success_and_refusal_outputs_match_the_canonical_contract(): void
    {
        $definitions = new DefinitionRegistry();
        $definitions->registerBlock(new BlockDefinition('rich_text', 1, 'Rich text', 'content.rich_text', [
            'type' => 'object',
            'required' => ['html'],
            'additionalProperties' => false,
            'properties' => ['html' => ['type' => 'string']],
        ]));
        $definitions->registerLayout(new LayoutDefinition('one_column', 1, ['main'], ['main'], ['rich_text']));
        $definitions->registerTemplate(new TemplateDefinition('standard', 1, ['one_column'], ['rich_text']));

        $codec = new CanonicalLayoutCodec();
        $document = LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_body',
                'layout' => ['id' => 'one_column', 'version' => 1],
                'regions' => ['main' => [[
                    'id' => 'blk_body', 'type' => 'rich_text', 'version' => 1, 'config' => ['html' => '<p>Body</p>'],
                ]]],
            ]],
        ]);
        $snapshot = new LayoutDraftSnapshot('42', 7, $codec->encode($document));
        $draftGateway = $this->createStub(LayoutDraftGatewayInterface::class);
        $draftGateway->method('read')->willReturn($snapshot);
        $validator = new LayoutValidator($definitions);
        $editor = new LayoutEditor($codec, $validator, $definitions);
        $surface = new PageBuilderSurface(
            'edit pages',
            $definitions,
            new LayoutDraftManager($draftGateway, $codec, $validator, $editor),
            $this->createStub(RevisionPreviewGatewayInterface::class),
        );
        $registry = new PageBuilderSurfaceRegistry();
        $registry->register('pages', $surface);
        $host = new GenericPageBuilderSurfaceHost($registry);
        $actor = new AuthorizationPrincipal(5, true, ['administrator'], ['edit pages'], 'test');
        $deniedActor = new AuthorizationPrincipal(6, true, ['reader'], [], 'test');

        $definitionsEnvelope = $host->handleDefinitions(new PageBuilderSurfaceRequest($actor, ''), 'pages');
        $this->assertConformsToInterface('PageBuilderSurfaceResult', $definitionsEnvelope);
        $this->assertConformsToInterface('PageBuilderDefinitionsData', $definitionsEnvelope['data']);
        $this->assertConformsToInterface('PageBuilderDefinitions', $definitionsEnvelope['data']['definitions']);
        $this->assertConformsToInterface('PageBuilderBlockDefinition', $definitionsEnvelope['data']['definitions']['blocks'][0]);

        $draftEnvelope = $host->handleDraft(new PageBuilderSurfaceRequest($actor, ''), 'pages', '42');
        self::assertTrue($draftEnvelope['ok'], json_encode($draftEnvelope['error'] ?? null));
        $this->assertConformsToInterface('PageBuilderSurfaceResult', $draftEnvelope);
        $this->assertConformsToInterface('PageBuilderDraft', $draftEnvelope['data']);
        $this->assertConformsToInterface('PageBuilderDocument', $draftEnvelope['data']['document']);

        $denied = $host->handleDraft(new PageBuilderSurfaceRequest($deniedActor, ''), 'pages', '42');
        $this->assertConformsToInterface('PageBuilderSurfaceResult', $denied);
        $this->assertConformsToInterface('PageBuilderSurfaceError', $denied['error']);
        self::assertSame(403, $denied['error']['status']);
    }

    private function revisionEntity(int $revisionId, bool $current, bool $latest): EntityInterface
    {
        $entity = new TestEntity(['id' => 1, 'uuid' => 'uuid-1', 'title' => 'Private title'], 'article');
        $entity->enforceIsNew(false);
        $entity->_hydrateStructuralRevision($revisionId, tip: $latest, default: $current);
        $entity->setRevisionMetadata(new RevisionMetadata(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 9, 'Saved'));

        return $entity;
    }

    #[Test]
    public function contractParserExtractsExpectedRoster(): void
    {
        // Sanity check on the contract parser — without this guard, a parser
        // bug could silently disable conformance checking.
        $session = $this->loadInterface('AdminSurfaceSession');
        self::assertContains('account', array_keys($session));
        self::assertContains('tenant', array_keys($session));
        self::assertContains('policies', array_keys($session));
        self::assertFalse($session['features']);      // required
        self::assertFalse($session['capabilities']);  // required
        self::assertTrue($session['ui']);             // optional
        self::assertFalse($session['account']);       // required

        $account = $this->loadInterface('AdminSurfaceAccount');
        self::assertFalse($account['id']);             // required
        self::assertFalse($account['email']);          // required, nullable
        self::assertFalse($account['emailVerified']);  // required, nullable

        $entry = $this->loadInterface('AdminSurfaceCatalogEntry');
        self::assertTrue($entry['description']);  // optional
        self::assertFalse($entry['fields']);      // required
        self::assertFalse($this->loadInterface('AdminSurfaceEntity')['mutation_token']);
        self::assertFalse($this->loadInterface('AdminSurfaceListResult')['entities']);
        self::assertFalse($this->loadInterface('AdminSurfaceEntitySchema')['properties']);
        self::assertFalse($this->loadInterface('PageBuilderDraft')['document']);
    }

    private function buildFullSession(): AdminSurfaceSessionData
    {
        return new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin', 'editor'],
            policies: ['administer content'],
            email: 'admin@example.com',
            emailVerified: true,
            tenantId: 'org-1',
            tenantName: 'Test Org',
            features: ['ai_assist' => true],
            ui: AdminSurfaceUiPayload::fromArrays(
                headerLinks: [['label' => 'Help', 'href' => 'https://example.test/help', 'external' => true]],
                sidebarItems: [['id' => 'reports', 'label' => 'Reports', 'href' => '/admin/reports', 'group' => 'tools', 'weight' => 5]],
                navigationMode: 'catalog-only',
            ),
        );
    }

    private function buildSessionWithoutEmailVerification(): AdminSurfaceSessionData
    {
        return new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin'],
            policies: [],
            email: 'admin@example.com',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertConformsToInterface(string $interfaceName, array $payload): void
    {
        $contract = $this->loadInterface($interfaceName);

        $payloadKeys = array_keys($payload);
        $contractKeys = array_keys($contract);

        // No drift: every emitted key must be declared in the contract.
        $unknown = array_diff($payloadKeys, $contractKeys);
        self::assertSame(
            [],
            array_values($unknown),
            sprintf(
                'PHP payload emits keys not declared in TS interface %s: %s',
                $interfaceName,
                implode(', ', $unknown),
            ),
        );

        // No gap: every required (non-optional) contract key must be emitted.
        $required = array_keys(array_filter($contract, static fn(bool $optional): bool => !$optional));
        $missing = array_diff($required, $payloadKeys);
        self::assertSame(
            [],
            array_values($missing),
            sprintf(
                'PHP payload omits required keys from TS interface %s: %s',
                $interfaceName,
                implode(', ', $missing),
            ),
        );
    }

    /**
     * @return array<string, bool>  field name → optional?
     */
    private function loadInterface(string $name): array
    {
        if (self::$contractCache === null) {
            self::$contractCache = $this->parseContractFiles();
        }

        self::assertArrayHasKey(
            $name,
            self::$contractCache,
            sprintf('TS contract type %s not found in canonical contract modules', $name),
        );

        return self::$contractCache[$name];
    }

    /**
     * Parse direct fields from canonical interfaces and object aliases across
     * the concern-separated modules. TypeScript assignability remains the job
     * of the dedicated SPA compatibility gate.
     *
     * @return array<string, array<string, bool>>
     */
    private function parseContractFiles(): array
    {
        $interfaces = [];
        foreach (glob(self::CONTRACT_DIRECTORY . '/*.ts') ?: [] as $path) {
            $source = file_get_contents($path);
            self::assertNotFalse($source, 'Failed to read canonical contract module: ' . $path);
            $pattern = '/export\s+(?:interface|type)\s+(\w+)(?:<[^>{}]+>)?\s*(?:=\s*)?\{/';
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
                self::fail('Regex failure parsing canonical Admin Surface contract.');
            }

            foreach ($matches[0] as $index => [$declaration, $offset]) {
                $name = $matches[1][$index][0];
                $open = $offset + strlen($declaration) - 1;
                $close = $this->matchingBrace($source, $open);
                if ($close === null) {
                    self::fail(sprintf('Unclosed contract declaration %s in %s.', $name, basename($path)));
                }
                $interfaces[$name] = $this->directFields(substr($source, $open + 1, $close - $open - 1));
            }
        }

        return $interfaces;
    }

    private function matchingBrace(string $source, int $open): ?int
    {
        $depth = 0;
        for ($index = $open, $length = strlen($source); $index < $length; ++$index) {
            if ($source[$index] === '{') {
                ++$depth;
            } elseif ($source[$index] === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @return array<string, bool> */
    private function directFields(string $body): array
    {
        $fields = [];
        $depth = 0;
        foreach (explode("\n", $body) as $line) {
            $stripped = trim($line);
            if ($depth === 0 && preg_match('/^(?:\'([^\']+)\'|([\w$-]+))(\?)?:/', $stripped, $match) === 1) {
                $fields[$match[1] !== '' ? $match[1] : $match[2]] = ($match[3] ?? '') === '?';
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $fields;
    }
}
