<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Semantic inventory gates for the active field-read boundary. */
#[CoversNothing]
final class FieldReadBoundaryArchitectureTest extends TestCase
{
    #[Test]
    public function entity_access_decision_entry_points_reject_mutable_entity_accounts(): void
    {
        foreach (['check', 'checkCreateAccess', 'checkFieldAccess', 'filterFields', 'viewableLabel'] as $methodName) {
            $method = new \ReflectionMethod(\Waaseyaa\Access\EntityAccessHandler::class, $methodName);
            self::assertStringContainsString(
                'AuthorizationPrincipalInterface $account',
                $method->getDocComment() ?: '',
                "{$methodName} must be statically narrowed to an immutable principal for PHPStan callers.",
            );
            $source = file(\dirname(__DIR__, 2) . '/packages/access/src/EntityAccessHandler.php');
            self::assertIsArray($source);
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            self::assertStringContainsString(
                '$this->assertImmutableDecisionAccount($account);',
                $body,
                "{$methodName} must reject a live User/entity before dispatching any access policy.",
            );
        }

        $guard = new \ReflectionMethod(\Waaseyaa\Access\EntityAccessHandler::class, 'assertImmutableDecisionAccount');
        self::assertTrue($guard->isPrivate());
        self::assertSame(\Waaseyaa\Access\AccountInterface::class, (string) $guard->getParameters()[0]->getType());

        $handler = new \Waaseyaa\Access\EntityAccessHandler();
        $liveUser = new \Waaseyaa\User\User(['uid' => 77, 'name' => 'Mutable account']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Access decisions require an immutable AuthorizationPrincipal');
        $handler->checkCreateAccess('node', 'article', $liveUser);
    }

    #[Test]
    public function ast_inventory_resists_lexical_and_type_evasion(): void
    {
        $inventory = $this->astInventoryForSources([
            'fixture/Base.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\Entity\EntityBase as FrameworkEntity;
                abstract class Intermediate extends FrameworkEntity {}
                final class Subject extends Intermediate
                {
                    public function get(string $name): mixed { return $this->values[$name] ?? null; }
                    public function label(): string { [$first] = $this->values; return (string) $first; }
                    public function toArray(): array { return $this->values; }
                }
                PHP,
            'fixture/Reader.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\Entity\EntityInterface as DomainObject;
                final class Reader
                {
                    public function read(DomainObject $subject): array
                    {
                        $copy = $subject;
                        return [$copy->get('mail'), $copy->label(), $copy->toArray()];
                    }
                }
                PHP,
            'fixture/Driver.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface as OpaqueDriver;
                use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
                final class Driver implements OpaqueDriver
                {
                    public function read(string $type, string $id, ?string $langcode = null): ?array { return []; }
                    public function readMultiple(string $type, array $ids, ?string $langcode = null): array { return []; }
                    public function write(string $type, string $id, array $snapshot): string { return $id; }
                }
                PHP,
            'fixture/OpaqueSnapshot.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\EntityStorage\Driver;
                final class StorageSnapshot
                {
                    /** @var array<string, mixed> */
                    private array $values = [];
                    public function exportRaw(): array { return $this->values; }
                }
                PHP,
            'fixture/InheritedDriver.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface as OpaqueDriver;
                abstract class RawParent
                {
                    public function read(string $type, string $id, ?string $langcode = null): array { return []; }
                    public function write(string $type, string $id, array $snapshot): string { return $id; }
                }
                abstract class InheritedDriver extends RawParent implements OpaqueDriver {}
                PHP,
            'fixture/NestedTraitDriver.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface as OpaqueDriver;
                trait RawNested
                {
                    public function badRead(string $type, string $id, ?string $langcode = null): array { return []; }
                    public function badWrite(string $type, string $id, array $snapshot): string { return $id; }
                }
                trait AliasedNested
                {
                    use RawNested { badRead as read; badWrite as write; }
                }
                abstract class NestedDriver implements OpaqueDriver { use AliasedNested; }
                PHP,
            'fixture/PrecedenceTraitDriver.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface as OpaqueDriver;
                use Waaseyaa\EntityStorage\Driver\StorageRow;
                use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
                trait GoodMethods
                {
                    public function read(string $type, string $id, ?string $langcode = null): ?StorageRow { return null; }
                    public function write(string $type, string $id, StorageSnapshot $snapshot): string { return $id; }
                }
                trait BadMethods
                {
                    public function read(string $type, string $id, ?string $langcode = null): array { return []; }
                    public function write(string $type, string $id, array $snapshot): string { return $id; }
                }
                abstract class PrecedenceDriver implements OpaqueDriver
                {
                    use GoodMethods, BadMethods {
                        BadMethods::read insteadof GoodMethods;
                        BadMethods::write insteadof GoodMethods;
                    }
                }
                PHP,
        ]);

        self::assertSame(['fixture/Base.php'], array_keys($inventory['entity_overrides']));
        self::assertSame(['fixture/Reader.php'], array_keys($inventory['entity_calls']));
        self::assertSame(['fixture/Base.php'], array_keys($inventory['direct_values']));
        self::assertSame(['fixture/OpaqueSnapshot.php'], array_keys($inventory['snapshot_raw_methods']));
        self::assertContains('array-return:exportRaw', $inventory['snapshot_raw_methods']['fixture/OpaqueSnapshot.php']);
        self::assertSame([
            'fixture/Driver.php',
            'fixture/InheritedDriver.php',
            'fixture/NestedTraitDriver.php',
            'fixture/PrecedenceTraitDriver.php',
        ], array_keys($inventory['v2_signature_violations']));
        self::assertContains('read', $inventory['v2_signature_violations']['fixture/InheritedDriver.php']);
        self::assertContains('write', $inventory['v2_signature_violations']['fixture/InheritedDriver.php']);
        self::assertContains('read', $inventory['v2_signature_violations']['fixture/NestedTraitDriver.php']);
        self::assertContains('write', $inventory['v2_signature_violations']['fixture/NestedTraitDriver.php']);
        self::assertContains('read', $inventory['v2_signature_violations']['fixture/PrecedenceTraitDriver.php']);
        self::assertContains('write', $inventory['v2_signature_violations']['fixture/PrecedenceTraitDriver.php']);
    }

    /**
     * Every existing direct value-bag reader is named with a disposition.
     * Adding a new reader requires an explicit architectural review.
     *
     * @var array<string, non-empty-string>
     */
    private const array DIRECT_VALUE_ALLOWLIST = [];

    /** @var array<string, non-empty-string> */
    private const array ENTITY_TO_ARRAY_ALLOWLIST = [
        'packages/ai-tools/src/Entity/EntityReadTool.php' => 'Third-party EntityInterface compatibility fallback only; framework values are selected by fieldNames before guarded get().',
        'packages/ai-tools/src/Entity/EntitySearchTool.php' => 'Third-party EntityInterface compatibility fallback only; framework search selects names before guarded get().',
        'packages/ai-tools/src/Relationship/RelationshipTraverseTool.php' => 'Third-party EntityInterface compatibility fallback only; framework edge projection uses selected guarded reads.',
        'packages/entity-storage/src/CoordinatorLifecycleDispatcher.php' => 'Third-party EntityInterface persistence fallback only; framework fan-out uses the private closed persistence authority.',
        'packages/entity-storage/src/EntityRepository.php' => 'Private diagnosed legacy third-party persistence fallback only.',
        'packages/entity/src/ConfigEntityBase.php' => 'Explicit public config export; sealed Internal fields intentionally make the whole export fail atomically.',
        'packages/entity/src/DateTime/TimestampFieldConvention.php' => 'Third-party EntityInterface compatibility fallback only; framework entities enumerate and use guarded get().',
        'packages/entity/src/EntityValues.php' => 'Third-party EntityInterface compatibility fallback only; framework entities use non-value fieldNames() enumeration.',
        'packages/entity/src/RevisionRestoreChangedFields.php' => 'Third-party EntityInterface compatibility fallback only; framework revisions use the closed name-only comparator.',
        'packages/entity/src/Snapshot/EntityValuesSnapshot.php' => 'Public-only snapshot boundary; framework entities are classification-preflighted before export and third-party implementations retain their declared array contract.',
        'packages/entity/src/Write/EntityWritePayloadGuard.php' => 'Third-party EntityInterface bookkeeping-echo compatibility only; framework echoes use the closed name-only comparator.',
    ];

    /** @var array<string, non-empty-string> */
    private const array ENTITY_ACCESSOR_ALLOWLIST = [
        'packages/access/src/EntityAccessHandler.php' => 'Reviewed sealed label decision input; activation-compatible through the canonical guarded label accessor.',
        'packages/access/src/Policy/PublishedContentStatusReader.php' => 'Closed publication-status reader retains only a third-party EntityInterface guarded-accessor fallback.',
        'packages/ai-agent/src/AgentExecutor.php' => 'AI agent entity input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-agent/src/Entity/AgentAuditLog.php' => 'Entity domain helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-agent/src/Entity/AgentRun.php' => 'Entity domain helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-agent/src/Message/RunAgentHandler.php' => 'Queue handler entity input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-agent/src/Security/AccountScopedAgentRunProjectionReader.php' => 'Closed account-scoped agent-run projection retains only a third-party EntityInterface guarded-accessor fallback.',
        'packages/ai-agent/src/Service/AgentRunService.php' => 'AI agent service input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-tools/src/AbstractAgentTool.php' => 'AI tool label projection is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-tools/src/Entity/EntityFieldRedaction.php' => 'Anonymous entity.read/search project named fields through the guarded accessor and omit Protected denials without observing the value (JSON:API parity).',
        'packages/ai-vector/src/EntityEmbedder.php' => 'Embedding label projection is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/ai-vector/src/EntityEmbeddingListener.php' => 'Embedding listener label projection is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/api/src/Audit/ApiAuditQueryAdapter.php' => 'Audit query adapter input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/api/src/ResourceSerializer.php' => 'JSON:API reads each outward field through the guarded accessor and omits Protected denials without observing the value.',
        'packages/api/src/Workflow/WorkflowDefinitionsController.php' => 'Workflow label projection is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/audit/src/AuditedFieldRead.php' => 'Reserved strict-audit fallback for third-party entity implementations.',
        'packages/audit/src/Entity/AuditCheckpoint.php' => 'Audit-checkpoint domain helpers use the canonical guarded accessor for explicitly classified fields.',
        'packages/audit/src/Entity/AuditEvent.php' => 'Audit-event domain helpers use the canonical guarded accessor for explicitly classified fields.',
        'packages/audit/src/Entity/AuditRetentionPolicy.php' => 'Audit-retention domain helpers use the canonical guarded accessor for explicitly classified fields.',
        'packages/cli/src/Command/Ai/AiRunCommand.php' => 'CLI entity input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/engagement/src/EngagementAccessPolicy.php' => 'Engagement policy input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/entity-storage/src/Advisory/SaveAdvisory.php' => 'Candidate-bound advisory authority reads exactly one app-declared field through the guarded accessor to bind a warning token; it never exposes or compares the entity value bag (#2467).',
        'packages/entity/src/EntityBase.php' => 'Canonical guarded accessor and structural helper implementation.',
        'packages/entity/src/DateTime/TimestampFieldConvention.php' => 'Framework timestamp inspection uses fieldNames() plus the canonical guarded accessor.',
        'packages/entity/src/EntityValues.php' => 'Selected cast-aware projections enumerate field names without first exporting a value bag.',
        'packages/entity/src/RevisionableEntityTrait.php' => 'Revision helpers route through the canonical guarded accessor when structural metadata is unavailable.',
        'packages/entity/src/Validation/EntityValidator.php' => 'Reviewed validation input; activation uses the one-shot closed validation reader for non-Public fields.',
        'packages/entity/testing/StorageBackedStubRepository.php' => 'Testing-package entity repository helper retained for consumer fixtures.',
        'packages/field/src/Classification/ClassificationSubjectReader.php' => 'Closed classification policy/lifecycle subject retains only a third-party EntityInterface guarded-accessor fallback.',
        'packages/field/src/Entity/ClassificationLabelDefinition.php' => 'Classification entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/field/src/Entity/RetentionPolicy.php' => 'Retention entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/field/src/Form/FormDescriptorBuilder.php' => 'Form projection input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/genealogy/src/Access/GenealogyRelationshipAccessPolicy.php' => 'Genealogy policy input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/genealogy/src/GenealogyLivingSemantics.php' => 'Genealogy semantic input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/groups/src/Group.php' => 'Group label helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/groups/src/GroupType.php' => 'Group-type label helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/listing/src/ListingResolver.php' => 'Listing projection input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/media/src/Media.php' => 'Media entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/media/src/Version/MediaVersion.php' => 'Media-version domain helpers route through the canonical guarded accessor.',
        'packages/menu/src/Menu.php' => 'Menu::isLocked() reads through the canonical guarded accessor because sealed V2 hydration bypasses the constructor; the field is declared FieldReadLevel::Public so the delete-boundary invariant (#2755) is observable without a caller-specific read grant.',
        'packages/menu/src/MenuLink.php' => 'Menu-link entity input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/messaging/src/MessagingAccessPolicy.php' => 'Messaging policy input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/node/src/Node.php' => 'Node entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/node/src/NodeType.php' => 'Node-type label helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/note/src/Note.php' => 'Note entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/oidc/src/Entity/OidcClient.php' => 'OIDC entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/path/src/PathAlias.php' => 'Path entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/publishing/src/ContentMutationSnapshotReader.php' => 'Closed publisher mutation results project only the descriptor status, slug, and writable fields after publish-capability and entity-gate authorization; callers cannot select another field (#2141).',
        'packages/relationship/src/RelationshipAccessPolicy.php' => 'Relationship policy input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/search/src/Projection/NodeSearchProjector.php' => 'Search-owned node projection reads only explicitly selected fields through the canonical guarded accessor: index-time reads without an account can expose Public fields only, each denied field is omitted, and query-time projection reruns inside the already entity-authorized principal scope (#2270).',
        'packages/seo/src/SchemaOrg/EntitySchemaOrgMapper.php' => 'SEO label projection is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/taxonomy/src/Term.php' => 'Taxonomy entity domain helper is reviewed activation-compatible through its classified canonical accessor.',
        'packages/taxonomy/src/TermAccessPolicy.php' => 'Taxonomy policy input is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/taxonomy/src/Vocabulary.php' => 'Vocabulary label helper is reviewed activation-compatible through the canonical guarded accessor.',
        'packages/user/src/User.php' => 'Entity helpers use the canonical guard: Protected reads require an immutable principal context, Internal reads deny, and first-party identity/PII consumers use exact audited snapshots.',
        'packages/user/src/UserBlockAccessPolicy.php' => 'Framework UserBlock records use the compiled V2 blocker subject; the direct accessor is restricted to third-party EntityInterface compatibility.',
        'packages/workflows/src/Read/EditorialPreviewSubjectReader.php' => 'Closed preview subject reader retains only a third-party EntityInterface guarded-accessor fallback.',
        'packages/workflows/src/Read/WorkflowEntitySnapshotReader.php' => 'Closed workflow snapshot reader retains only a third-party EntityInterface guarded-accessor fallback.',
    ];

    /** @var array<string, array{methods: non-empty-list<string>, rationale: non-empty-string}> */
    private const array ENTITY_OVERRIDE_ALLOWLIST = [
        'packages/entity/src/TranslatableEntityTrait.php' => ['methods' => ['get'], 'rationale' => 'Translation and fallback dispatch use sealed related-view containers and converge into EntityBase get().'],
    ];

    /** @var array<string, array{methods: non-empty-list<string>, rationale: non-empty-string}> */
    private const array SNAPSHOT_RAW_METHOD_ALLOWLIST = [
        'packages/entity-storage/src/Driver/StorageRow.php' => [
            'methods' => ['array-return:__serialize', 'array-return:valuesForBoundary'],
            'rationale' => 'Identity-bound row unwrap is restricted to the paired repository reader role.',
        ],
        'packages/entity-storage/src/Driver/StorageRowSet.php' => [
            'methods' => ['array-return:__serialize', 'array-return:rowsForBoundary', 'reads:count', 'reads:row'],
            'rationale' => 'The set exposes rows only to its internal reader seam; count and keyed row access never expose value arrays.',
        ],
        'packages/entity-storage/src/Driver/StorageSnapshot.php' => [
            'methods' => ['array-return:__serialize', 'array-return:valuesForBoundary'],
            'rationale' => 'Identity-bound snapshot unwrap is restricted to the paired driver reader role.',
        ],
    ];

    #[Test]
    public function direct_value_bag_access_is_a_closed_reviewed_inventory(): void
    {
        $actual = array_keys($this->astInventoryForSources($this->phpSources())['direct_values']);
        sort($actual);
        $expected = array_keys(self::DIRECT_VALUE_ALLOWLIST);
        sort($expected);

        self::assertSame($expected, $actual);
        foreach (self::DIRECT_VALUE_ALLOWLIST as $rationale) {
            self::assertNotSame('', trim($rationale));
        }
    }

    #[Test]
    public function entity_storage_public_projection_fallback_is_confined_to_the_private_repository_authority(): void
    {
        $actual = [];
        foreach ($this->phpSources('packages/entity-storage/src') as $relative => $source) {
            if ($this->hasMethodCall($source, 'toArray')) {
                $actual[] = $relative;
            }
        }

        self::assertSame(
            [
                'packages/entity-storage/src/CoordinatorLifecycleDispatcher.php',
                'packages/entity-storage/src/EntityRepository.php',
            ],
            $actual,
            'Diagnosed third-party persistence fallbacks must remain confined to reviewed private authorities.',
        );
    }

    #[Test]
    public function closed_persistence_source_has_exactly_one_framework_caller(): void
    {
        $actual = [];
        foreach ($this->phpSources() as $relative => $source) {
            if ($this->hasMethodCall($source, '_storageValuesForPersistence')) {
                $actual[] = $relative;
            }
        }

        self::assertSame([], $actual);
    }

    #[Test]
    public function no_public_companion_or_extractor_method_releases_a_raw_entity_bag(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/packages/entity/src/PersistenceValueSourceInterface.php',
            'A public companion interface turns persistence authority into an application-callable raw-bag bypass.',
        );
        self::assertFalse(method_exists(\Waaseyaa\Entity\EntityBase::class, '_storageValuesForPersistence'));
        self::assertFalse(class_exists('Waaseyaa\\EntityStorage\\PersistenceValueExtractor'));
    }

    #[Test]
    public function whole_bag_comparisons_are_confined_to_the_name_only_authority(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringNotContainsString('->toArray()', (string) file_get_contents($root . '/packages/api/src/JsonApiController.php'));
        self::assertStringNotContainsString('->toArray()', (string) file_get_contents($root . '/packages/graphql/src/Resolver/EntityResolver.php'));

        $callers = [];
        foreach ($this->phpSources() as $relative => $source) {
            if (str_contains($source, 'new EntityValueComparator')) {
                $callers[] = $relative;
            }
        }
        self::assertSame([
            'packages/entity/src/RevisionRestoreChangedFields.php',
            'packages/entity/src/Write/EntityWritePayloadGuard.php',
        ], $callers);
    }

    #[Test]
    public function legacy_post_construction_structural_bootstrap_is_absent(): void
    {
        self::assertFalse(method_exists(
            \Waaseyaa\EntityStorage\EntityRepository::class,
            'attachStructureForLegacyConstruction',
        ));
    }

    #[Test]
    public function full_persistence_extraction_is_ordered_after_every_pre_save_callback(): void
    {
        $method = new \ReflectionMethod(\Waaseyaa\EntityStorage\EntityRepository::class, 'doSave');
        $node = $this->methodAst($method);
        $finder = new NodeFinder();
        $extraction = $finder->findFirst($node, static fn(Node $candidate): bool => $candidate instanceof Expr\MethodCall
            && $candidate->name instanceof Node\Identifier && 'extractPersistenceValues' === $candidate->name->toString());
        self::assertInstanceOf(Expr\MethodCall::class, $extraction);

        $callbacks = [
            'entity preSave' => $finder->findFirst($node, static fn(Node $candidate): bool => $candidate instanceof Expr\MethodCall
                && $candidate->name instanceof Node\Identifier && 'preSave' === $candidate->name->toString()),
            'PRE_SAVE dispatch' => $finder->findFirst($node, static fn(Node $candidate): bool => $candidate instanceof Expr\ClassConstFetch
                && $candidate->name instanceof Node\Identifier && 'PRE_SAVE' === $candidate->name->toString()
                && $candidate->class instanceof Name && 'Waaseyaa\\Entity\\Event\\EntityEvents' === self::resolvedName($candidate->class)),
            'BeforeSaveEvent construction' => $finder->findFirst($node, static fn(Node $candidate): bool => $candidate instanceof Expr\New_
                && $candidate->class instanceof Name && 'Waaseyaa\\EntityStorage\\Event\\BeforeSaveEvent' === self::resolvedName($candidate->class)),
        ];
        foreach ($callbacks as $label => $callback) {
            self::assertInstanceOf(Node::class, $callback);
            self::assertLessThan($extraction->getStartLine(), $callback->getStartLine(), 'Persistence extraction must follow ' . $label);
        }
    }

    #[Test]
    public function entity_array_projection_calls_and_overrides_are_a_closed_reviewed_inventory(): void
    {
        $inventory = $this->astInventoryForSources($this->phpSources());
        $actual = [];
        foreach ($inventory['entity_calls'] as $relative => $calls) {
            if (in_array('toArray', $calls, true)) {
                $actual[] = $relative;
            }
        }
        $overrides = $inventory['entity_overrides'];
        sort($actual);
        $expected = array_keys(self::ENTITY_TO_ARRAY_ALLOWLIST);
        sort($expected);

        self::assertSame($expected, $actual);
        $expectedOverrides = array_keys(self::ENTITY_OVERRIDE_ALLOWLIST);
        sort($expectedOverrides);
        self::assertSame($expectedOverrides, array_keys($overrides), 'EntityBase accessor overrides require an explicit reviewed disposition.');
        foreach ($overrides as $relative => $methods) {
            self::assertSame(self::ENTITY_OVERRIDE_ALLOWLIST[$relative]['methods'], $methods);
        }
        foreach (self::ENTITY_TO_ARRAY_ALLOWLIST as $rationale) {
            self::assertNotSame('', trim($rationale));
        }
        foreach (self::ENTITY_OVERRIDE_ALLOWLIST as $disposition) {
            self::assertNotSame('', trim($disposition['rationale']));
        }
    }

    #[Test]
    public function entity_get_and_label_calls_are_a_closed_reviewed_inventory(): void
    {
        $calls = $this->astInventoryForSources($this->phpSources())['entity_calls'];
        $actual = [];
        foreach ($calls as $relative => $methods) {
            if ([] !== array_intersect(['get', 'label'], $methods)) {
                $actual[] = $relative;
            }
        }
        sort($actual);
        $expected = array_keys(self::ENTITY_ACCESSOR_ALLOWLIST);
        sort($expected);

        self::assertSame($expected, $actual);
        foreach (self::ENTITY_ACCESSOR_ALLOWLIST as $rationale) {
            self::assertNotSame('', trim($rationale));
        }
    }

    #[Test]
    public function opaque_snapshot_raw_methods_are_a_closed_reviewed_inventory(): void
    {
        $actual = array_keys($this->astInventoryForSources($this->phpSources())['snapshot_raw_methods']);
        $expected = array_keys(self::SNAPSHOT_RAW_METHOD_ALLOWLIST);
        sort($actual);
        sort($expected);

        self::assertSame($expected, $actual);
        $inventory = $this->astInventoryForSources($this->phpSources())['snapshot_raw_methods'];
        foreach ($inventory as $relative => $methods) {
            self::assertSame(self::SNAPSHOT_RAW_METHOD_ALLOWLIST[$relative]['methods'], $methods);
        }
        foreach (self::SNAPSHOT_RAW_METHOD_ALLOWLIST as $disposition) {
            self::assertNotSame('', trim($disposition['rationale']));
        }
    }

    #[Test]
    public function v2_driver_implementations_keep_opaque_signatures(): void
    {
        self::assertSame([], $this->astInventoryForSources($this->phpSources())['v2_signature_violations']);
    }

    #[Test]
    public function first_party_field_definitions_are_semantically_classified(): void
    {
        self::assertSame([], $this->astInventoryForSources($this->phpSources())['unclassified_field_definitions']);
    }

    #[Test]
    public function classification_inventory_resolves_aliases_fqcn_attributes_and_metadata_arrays(): void
    {
        $inventory = $this->astInventoryForSources([
            'fixture/Definitions.php' => <<<'PHP'
                <?php
                namespace Fixture;
                use Waaseyaa\Field\FieldDefinition as FD;
                use Waaseyaa\Entity\Attribute\Field as EntityField;
                use Waaseyaa\Field\Attribute\FieldTemplate as Template;
                final class Definitions {
                    #[EntityField(type: 'string')]
                    public string $attribute = '';
                    #[Template(key: 'dynamic', type: 'string')]
                    public string $dynamic = '';
                    public function definitions(): array {
                        return [
                            new FD(name: 'alias', type: 'string'),
                            new \Waaseyaa\Field\FieldDefinition(name: 'fqcn', type: 'string'),
                            new FD(name: 'named_null', type: 'string', read: null),
                            new \Waaseyaa\Entity\EntityType(
                                id: 'fixture', label: 'Fixture', class: self::class,
                                _fieldDefinitions: [
                                    'metadata' => ['type' => 'string'],
                                    'metadata_null' => ['type' => 'string', 'read' => null],
                                ],
                            ),
                        ];
                    }
                }
                PHP,
        ])['unclassified_field_definitions'];

        self::assertSame([
            'attribute:7',
            'constructor:13',
            'constructor:14',
            'constructor:15',
            'field_template:9',
            'metadata:19',
            'metadata:20',
        ], $inventory['fixture/Definitions.php']);
    }

    #[Test]
    public function closed_first_party_raw_authorities_are_exact_and_non_exported(): void
    {
        $actual = [];
        foreach ($this->phpSources() as $relative => $source) {
            if ($this->hasBoundEntityBaseClosure($source)) {
                $actual[] = $relative;
            }
        }

        self::assertSame([
            'packages/access/src/EntityAccessHandler.php',
            'packages/access/src/FieldReadGuard.php',
            'packages/access/src/Policy/PublishedContentStatusReader.php',
            'packages/ai-agent/src/Access/AgentRunAccessPolicy.php',
            'packages/attachment/src/Maintenance/AttachmentMaintenanceFieldReader.php',
            'packages/attachment/src/Policy/ParentDelegatedAccessPolicy.php',
            'packages/audit/src/AuditedFieldRead.php',
            'packages/engagement/src/EngagementAccessPolicy.php',
            'packages/entity-storage/src/CoordinatorLifecycleDispatcher.php',
            'packages/entity-storage/src/EntityRepository.php',
            'packages/entity/src/Audit/EntityWriteAuditSubjectReader.php',
            'packages/entity/src/EntityValueComparator.php',
            'packages/entity/src/Validation/ValidationFieldReader.php',
            'packages/field/src/Classification/ClassificationSubjectReader.php',
            'packages/field/src/Entity/RetentionPolicyMaintenanceReader.php',
            'packages/genealogy/src/Access/GenealogyContentAccessPolicy.php',
            'packages/media/src/MediaAccessPolicy.php',
            'packages/messaging/src/MessagingAccessPolicy.php',
            'packages/node/src/NodeAuthorizationSnapshotReader.php',
            'packages/note/src/Ingestion/NoteIngestionMetadataReader.php',
            'packages/note/src/NoteAccessPolicy.php',
            'packages/oidc/src/ClientRegistry/OidcClientSystemReader.php',
            'packages/publishing/src/ContentMutationSnapshotReader.php',
            'packages/relationship/src/AuthorizedRelationshipTraversal.php',
            'packages/relationship/src/RelationshipMaintenanceReader.php',
            'packages/relationship/src/RelationshipTopologyReader.php',
            'packages/wayfinding/src/Access/TrailAccessPolicy.php',
            'packages/wayfinding/src/Trail/TrailStore.php',
            'packages/workflows/src/Read/EditorialPreviewSubjectReader.php',
            'packages/workflows/src/Read/EditorialWorkflowLegacySubjectReader.php',
            'packages/workflows/src/Read/WorkflowEntitySnapshotReader.php',
            'packages/workflows/src/Workflow.php',
        ], $actual);
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Access\EntityAccessHandler::class, 'entityPolicySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Access\FieldReadGuard::class, 'policySubject')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Access\Policy\PublishedContentStatusReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\AI\Agent\Access\AgentRunOwnerEntityReadPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Attachment\Maintenance\AttachmentMaintenanceFieldReader::class, 'obtain')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionMethod(\Waaseyaa\Audit\AuditedFieldRead::class, 'obtainReservedValue')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Engagement\EngagementAccessPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\EntityStorage\CoordinatorLifecycleDispatcher::class, 'persistenceValueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionMethod(\Waaseyaa\EntityStorage\EntityRepository::class, 'extractPersistenceValues')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Entity\Audit\EntityWriteAuditSubjectReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Entity\EntityValueComparator::class, 'changedFields')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Entity\EntityValueComparator::class, 'matchingSubmittedFields')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Entity\Validation\ValidationFieldReader::class, 'obtain')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Field\Classification\ClassificationSubjectReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Field\Entity\RetentionPolicyMaintenanceReader::class, 'valueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Genealogy\Access\GenealogyContentAccessPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Media\MediaAccessPolicy::class, 'ownerSubject')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Messaging\MessagingAccessPolicy::class, 'policySubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Node\NodeAuthorizationSnapshotReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Note\Ingestion\NoteIngestionMetadataReader::class, 'obtain')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader::class, 'valueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Relationship\RelationshipMaintenanceReader::class, 'valueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Relationship\RelationshipTopologyReader::class, 'valueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Wayfinding\Access\TrailAccessPolicy::class, 'ownerSubjectAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Wayfinding\Trail\TrailStore::class, 'trailValuesAuthority')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Workflows\Read\EditorialPreviewSubjectReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Workflows\Read\EditorialWorkflowLegacySubjectReader::class, 'values')->isPrivate());
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader::class, 'valueAuthority')->isPrivate());
        self::assertTrue(new \ReflectionMethod(\Waaseyaa\Workflows\Workflow::class, 'ensureDefinitionHydrated')->isPrivate());
    }

    private function hasBoundEntityBaseClosure(string $source): bool
    {
        $nodes = $this->resolvedAst($source);

        return null !== new NodeFinder()->findFirst($nodes, static function (Node $node): bool {
            if (!$node instanceof Expr\StaticCall || !$node->class instanceof Name || !$node->name instanceof Node\Identifier
                || 'Closure' !== ltrim(self::resolvedName($node->class), '\\') || 'bind' !== $node->name->toString()) {
                return false;
            }

            return null !== new NodeFinder()->findFirst($node->args, static fn(Node $argument): bool =>
                $argument instanceof Expr\ClassConstFetch && $argument->class instanceof Name
                && $argument->name instanceof Node\Identifier && 'class' === strtolower($argument->name->toString())
                && 'Waaseyaa\\Entity\\EntityBase' === self::resolvedName($argument->class));
        });
    }

    private function methodAst(\ReflectionMethod $method): Stmt\ClassMethod
    {
        $source = file_get_contents((string) $method->getFileName());
        self::assertIsString($source);
        $candidate = new NodeFinder()->findFirst(
            $this->resolvedAst($source),
            static fn(Node $node): bool => $node instanceof Stmt\ClassMethod && $method->getName() === $node->name->toString(),
        );
        self::assertInstanceOf(Stmt\ClassMethod::class, $candidate);

        return $candidate;
    }

    /** @return list<Node\Stmt> */
    private function resolvedAst(string $source): array
    {
        $nodes = new ParserFactory()->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($nodes);
    }

    private static function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /**
     * @param array<string, string> $sources
     * @return array{
     *   direct_values: array<string, list<string>>,
     *   entity_calls: array<string, list<string>>,
     *   entity_overrides: array<string, list<string>>,
     *   snapshot_raw_methods: array<string, list<string>>,
     *   v2_signature_violations: array<string, list<string>>,
     *   unclassified_field_definitions: array<string, list<string>>
     * }
     */
    private function astInventoryForSources(array $sources): array
    {
        return new FieldReadBoundaryAstInventory()->scan($sources);
    }

    /** @return array<string, string> */
    private function phpSources(string $subtree = 'packages'): array
    {
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $subtree));
        $sources = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($sources);

        return $sources;
    }

    private function hasMethodCall(string $source, string $method): bool
    {
        $nodes = new ParserFactory()->createForNewestSupportedVersion()->parse($source) ?? [];

        return null !== new NodeFinder()->findFirst(
            $nodes,
            static fn(Node $node): bool => $node instanceof Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $method === $node->name->toString(),
        );
    }

}

/** AST-backed inventory: names and types are resolved before boundary occurrences are classified. */
final class FieldReadBoundaryAstInventory
{
    private const string ENTITY_BASE = 'Waaseyaa\\Entity\\EntityBase';
    private const string ENTITY_INTERFACE = 'Waaseyaa\\Entity\\EntityInterface';
    private const string STORAGE_V2 = 'Waaseyaa\\EntityStorage\\Driver\\EntityStorageDriverV2Interface';
    private const string REVISION_V2 = 'Waaseyaa\\EntityStorage\\Driver\\RevisionableStorageDriverV2Interface';
    private const array OPAQUE_STORAGE_BAGS = [
        'Waaseyaa\\EntityStorage\\Driver\\StorageRow' => 'values',
        'Waaseyaa\\EntityStorage\\Driver\\StorageSnapshot' => 'values',
        'Waaseyaa\\EntityStorage\\Driver\\StorageRowSet' => 'rows',
    ];
    private const array STORAGE_V2_SIGNATURES = [
        'read' => [['string', 'string', '?string'], '?Waaseyaa\\EntityStorage\\Driver\\StorageRow'],
        'readMultiple' => [['string', 'array', '?string'], 'Waaseyaa\\EntityStorage\\Driver\\StorageRowSet'],
        'write' => [['string', 'string', 'Waaseyaa\\EntityStorage\\Driver\\StorageSnapshot'], 'string'],
        'remove' => [['string', 'string'], 'void'],
        'exists' => [['string', 'string'], 'bool'],
        'count' => [['string', 'array'], 'int'],
        'findBy' => [['string', 'array', '?array', '?int'], 'Waaseyaa\\EntityStorage\\Driver\\StorageRowSet'],
        'findTranslations' => [['string', 'string', '?string'], 'Waaseyaa\\EntityStorage\\Driver\\StorageRowSet'],
    ];
    private const array REVISION_V2_SIGNATURES = [
        'writeRevision' => [['string', 'Waaseyaa\\EntityStorage\\Driver\\StorageSnapshot', '?string', '?string', '?int'], 'int'],
        'updateRevision' => [['string', 'int', 'Waaseyaa\\EntityStorage\\Driver\\StorageSnapshot'], 'void'],
        'readRevision' => [['string', 'int'], '?Waaseyaa\\EntityStorage\\Driver\\StorageRow'],
        'readMultipleRevisions' => [['string', 'array'], 'Waaseyaa\\EntityStorage\\Driver\\StorageRowSet'],
        'getLatestRevisionId' => [['string'], '?int'],
        'getRevisionIds' => [['string'], 'array'],
        'deleteRevision' => [['string', 'int'], 'void'],
        'deleteAllRevisions' => [['string'], 'void'],
        'readLangcodeRevision' => [['string', 'string', 'int'], '?Waaseyaa\\EntityStorage\\Driver\\StorageRow'],
        'getLatestLangcodeRevisionId' => [['string', 'string'], '?int'],
        'getLangcodeRevisionIds' => [['string', 'string'], 'array'],
        'getLangcodesWithRevisions' => [['string'], 'array'],
        'currentLangcodeRevision' => [['string', 'string'], '?int'],
        'setCurrentLangcodeRevision' => [['string', 'string', 'int'], 'void'],
        'hasCurrentLangcodeRevision' => [['string', 'string'], 'bool'],
    ];

    /** @var array<string, array{file: string, node: Stmt\Class_}> */
    private array $classes = [];

    /** @var array<string, array{file: string, node: Stmt\Trait_}> */
    private array $traits = [];

    /** @var array<string, bool> */
    private array $entityTypes = [];

    /** @var array<string, bool> */
    private array $storageV2Types = [];

    /** @var array<string, bool> */
    private array $revisionV2Types = [];

    /** @var array<string, string> */
    private array $opaqueStorageTypes = self::OPAQUE_STORAGE_BAGS;

    /** @var array<string, array<string, string>> */
    private array $methodReturnTypes = [];

    /**
     * @param array<string, string> $sources
     * @return array{
     *   direct_values: array<string, list<string>>,
     *   entity_calls: array<string, list<string>>,
     *   entity_overrides: array<string, list<string>>,
     *   snapshot_raw_methods: array<string, list<string>>,
     *   v2_signature_violations: array<string, list<string>>
     * }
     */
    public function scan(array $sources): array
    {
        $asts = [];
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        foreach ($sources as $file => $source) {
            $ast = $parser->parse($source) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $asts[$file] = $traverser->traverse($ast);
            $this->collectClasses($asts[$file], $file);
        }
        $this->resolveTypeFamilies();
        foreach ($this->classes as $class => $metadata) {
            foreach ($metadata['node']->getMethods() as $method) {
                $type = $this->typeName($method->returnType);
                if (null !== $type) {
                    $this->methodReturnTypes[$class][$method->name->toString()] = $type;
                }
            }
        }

        $inventory = [
            'direct_values' => [],
            'entity_calls' => [],
            'entity_overrides' => [],
            'snapshot_raw_methods' => [],
            'v2_signature_violations' => [],
            'unclassified_field_definitions' => [],
        ];
        foreach ($asts as $file => $ast) {
            $this->scanFieldDefinitions($ast, $file, $inventory['unclassified_field_definitions']);
        }
        foreach ($this->classes as $class => $metadata) {
            $this->scanClass($class, $metadata['file'], $metadata['node'], $inventory);
        }
        foreach ($this->entityTraits() as $trait => $entityClass) {
            $metadata = $this->traits[$trait];
            $this->scanClassLike($entityClass, $metadata['file'], $metadata['node'], $inventory, true);
        }
        foreach ($inventory as &$occurrences) {
            ksort($occurrences);
            foreach ($occurrences as &$names) {
                $names = array_values(array_unique($names));
                sort($names);
            }
        }

        return $inventory;
    }

    /** @param list<Node> $nodes @param array<string, list<string>> $occurrences */
    private function scanFieldDefinitions(array $nodes, string $file, array &$occurrences): void
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($nodes, Node\Attribute::class) as $attribute) {
            assert($attribute instanceof Node\Attribute);
            $kind = match ($this->resolvedName($attribute->name)) {
                'Waaseyaa\Entity\Attribute\Field' => 'attribute',
                'Waaseyaa\Field\Attribute\FieldTemplate' => 'field_template',
                default => null,
            };
            if (null !== $kind && !$this->hasNonNullNamedArgument($attribute->args, 'read')) {
                $occurrences[$file][] = $kind . ':' . $attribute->getStartLine();
            }
        }
        foreach ($finder->findInstanceOf($nodes, Expr\New_::class) as $construction) {
            assert($construction instanceof Expr\New_);
            if (!$construction->class instanceof Name) {
                continue;
            }
            $class = $this->resolvedName($construction->class);
            if ('Waaseyaa\Field\FieldDefinition' === $class && !$this->hasNonNullNamedArgument($construction->args, 'read')) {
                $occurrences[$file][] = 'constructor:' . $construction->getStartLine();
            }
            if ('Waaseyaa\Entity\EntityType' !== $class) {
                continue;
            }
            foreach ($construction->args as $argument) {
                if ('_fieldDefinitions' !== $argument->name?->toString() || !$argument->value instanceof Expr\Array_) {
                    continue;
                }
                foreach ($argument->value->items as $item) {
                    if (null === $item || !$item->value instanceof Expr\Array_ || $this->arrayHasNonNullStringKey($item->value, 'read')) {
                        continue;
                    }
                    $occurrences[$file][] = 'metadata:' . $item->value->getStartLine();
                }
            }
        }
    }

    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /** @param list<Node\Arg> $arguments */
    private function hasNonNullNamedArgument(array $arguments, string $name): bool
    {
        foreach ($arguments as $argument) {
            if ($name === $argument->name?->toString()) {
                return !$argument->value instanceof Expr\ConstFetch
                    || 'null' !== strtolower($argument->value->name->toString());
            }
        }

        return false;
    }

    private function arrayHasNonNullStringKey(Expr\Array_ $array, string $name): bool
    {
        foreach ($array->items as $item) {
            if (null !== $item && $item->key instanceof Node\Scalar\String_ && $name === $item->key->value) {
                return !$item->value instanceof Expr\ConstFetch
                    || 'null' !== strtolower($item->value->name->toString());
            }
        }

        return false;
    }

    /** @param list<Node> $nodes */
    private function collectClasses(array $nodes, string $file): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_ && null !== $node->name) {
                $name = $node->namespacedName?->toString();
                if (null !== $name) {
                    $this->classes[$name] = ['file' => $file, 'node' => $node];
                }
            }
            if ($node instanceof Stmt\Trait_ && null !== $node->name) {
                $name = $node->namespacedName?->toString();
                if (null !== $name) {
                    $this->traits[$name] = ['file' => $file, 'node' => $node];
                }
            }
            foreach ($node->getSubNodeNames() as $subNodeName) {
                $child = $node->{$subNodeName};
                if (is_array($child)) {
                    $this->collectClasses(array_values(array_filter($child, static fn(mixed $item): bool => $item instanceof Node)), $file);
                }
            }
        }
    }

    private function resolveTypeFamilies(): void
    {
        $this->entityTypes = [self::ENTITY_BASE => true, self::ENTITY_INTERFACE => true];
        $this->storageV2Types = [self::STORAGE_V2 => true];
        $this->revisionV2Types = [self::REVISION_V2 => true];
        do {
            $changed = false;
            foreach ($this->classes as $name => $metadata) {
                $parents = array_filter([
                    $this->name($metadata['node']->extends),
                    ...array_map($this->name(...), $metadata['node']->implements),
                ]);
                foreach ([&$this->entityTypes, &$this->storageV2Types, &$this->revisionV2Types] as &$family) {
                    if (!isset($family[$name]) && array_intersect_key($family, array_fill_keys($parents, true))) {
                        $family[$name] = true;
                        $changed = true;
                    }
                }
                if (!isset($this->opaqueStorageTypes[$name])) {
                    foreach ($parents as $parent) {
                        if (isset($this->opaqueStorageTypes[$parent])) {
                            $this->opaqueStorageTypes[$name] = $this->opaqueStorageTypes[$parent];
                            $changed = true;
                            break;
                        }
                    }
                }
            }
        } while ($changed);
    }

    /** @param array<string, array<string, list<string>>> $inventory */
    private function scanClass(string $class, string $file, Stmt\Class_ $node, array &$inventory): void
    {
        $this->scanClassLike($class, $file, $node, $inventory, false);
    }

    /** @return array<string, string> trait => first entity class using it */
    private function entityTraits(): array
    {
        $result = [];
        foreach ($this->classes as $class => $metadata) {
            if (!isset($this->entityTypes[$class])) {
                continue;
            }
            foreach ($metadata['node']->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    $name = $this->name($trait);
                    if (null !== $name && isset($this->traits[$name])) {
                        $result[$name] = $class;
                    }
                }
            }
        }

        return $result;
    }

    /** @param array<string, array<string, list<string>>> $inventory */
    private function scanClassLike(string $class, string $file, Stmt\Class_|Stmt\Trait_ $node, array &$inventory, bool $trait): void
    {
        $propertyTypes = $trait ? [] : $this->propertyTypes($class);
        foreach ($node->getProperties() as $property) {
            $type = $this->typeName($property->type);
            foreach ($property->props as $prop) {
                if (null !== $type) {
                    $propertyTypes[$prop->name->toString()] = $type;
                }
            }
        }

        foreach ($node->getMethods() as $method) {
            $methodName = $method->name->toString();
            if (isset($this->entityTypes[$class]) && self::ENTITY_BASE !== $class && in_array($methodName, ['get', 'label', 'toArray'], true)) {
                $inventory['entity_overrides'][$file][] = $methodName;
            }
            if (isset($this->opaqueStorageTypes[$class])) {
                $readsBag = $this->readsOpaqueBag($method, $this->opaqueStorageTypes[$class]);
                if ('array' === $this->typeName($method->returnType)) {
                    $inventory['snapshot_raw_methods'][$file][] = 'array-return:' . $methodName;
                } elseif ($readsBag) {
                    $inventory['snapshot_raw_methods'][$file][] = 'reads:' . $methodName;
                }
            }
            $types = ['$this' => $class];
            foreach ($method->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $type = $this->typeName($param->type);
                    if (null !== $type) {
                        $types['$' . $param->var->name] = $type;
                    }
                }
            }
            $this->scanNode($method, $file, $class, $propertyTypes, $types, $inventory);
        }
        if (!$trait && $node instanceof Stmt\Class_) {
            $revision = isset($this->revisionV2Types[$class]);
            $required = isset($this->revisionV2Types[$class])
                ? array_keys(self::REVISION_V2_SIGNATURES)
                : (isset($this->storageV2Types[$class]) ? array_keys(self::STORAGE_V2_SIGNATURES) : []);
            foreach ($required as $methodName) {
                $effectiveMethod = $this->classMethod($class, $methodName);
                if (null === $effectiveMethod && !$node->isAbstract()) {
                    $inventory['v2_signature_violations'][$file][] = 'missing:' . $methodName;
                } elseif (null !== $effectiveMethod && $this->violatesOpaqueV2Signature($effectiveMethod, $revision, $methodName)) {
                    $inventory['v2_signature_violations'][$file][] = $methodName;
                }
            }
        }
    }

    /** @return array<string, string> */
    private function propertyTypes(string $class): array
    {
        if (!isset($this->classes[$class])) {
            return [];
        }
        $node = $this->classes[$class]['node'];
        $parent = $this->name($node->extends);
        $types = null === $parent ? [] : $this->propertyTypes($parent);
        foreach ($node->getProperties() as $property) {
            $type = $this->typeName($property->type);
            foreach ($property->props as $prop) {
                if (null !== $type) {
                    $types[$prop->name->toString()] = $type;
                }
            }
        }

        return $types;
    }

    private function classMethod(string $class, string $method): ?Stmt\ClassMethod
    {
        if (!isset($this->classes[$class])) {
            return null;
        }
        foreach ($this->classes[$class]['node']->getMethods() as $candidate) {
            if ($method === $candidate->name->toString()) {
                return $candidate;
            }
        }
        foreach ($this->classes[$class]['node']->getTraitUses() as $use) {
            $candidate = $this->traitUseMethod($use, $method);
            if (null !== $candidate) {
                return $candidate;
            }
        }
        $parent = $this->name($this->classes[$class]['node']->extends);

        return null === $parent ? null : $this->classMethod($parent, $method);
    }

    private function traitMethod(string $trait, string $method): ?Stmt\ClassMethod
    {
        if (!isset($this->traits[$trait])) {
            return null;
        }
        foreach ($this->traits[$trait]['node']->getMethods() as $candidate) {
            if ($method === $candidate->name->toString()) {
                return $candidate;
            }
        }
        foreach ($this->traits[$trait]['node']->getTraitUses() as $use) {
            $candidate = $this->traitUseMethod($use, $method);
            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function traitUseMethod(Stmt\TraitUse $use, string $method): ?Stmt\ClassMethod
    {
        foreach ($use->adaptations as $adaptation) {
            if (!$adaptation instanceof Stmt\TraitUseAdaptation\Alias || null === $adaptation->newName
                || $method !== $adaptation->newName->toString()) {
                continue;
            }
            $candidate = null;
            if (null !== $adaptation->trait) {
                $traitName = $this->name($adaptation->trait);
                $candidate = null === $traitName ? null : $this->traitMethod($traitName, $adaptation->method->toString());
            } else {
                $candidate = $this->unaliasedTraitUseMethod($use, $adaptation->method->toString());
            }

            return null === $candidate ? null : $this->applyTraitAliasVisibility($candidate, $adaptation);
        }

        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias && null === $adaptation->newName
                && $method === $adaptation->method->toString()) {
                $candidate = null !== $adaptation->trait
                    ? $this->traitMethod((string) $this->name($adaptation->trait), $method)
                    : $this->unaliasedTraitUseMethod($use, $method);

                return null === $candidate ? null : $this->applyTraitAliasVisibility($candidate, $adaptation);
            }
        }

        return $this->unaliasedTraitUseMethod($use, $method);
    }

    private function unaliasedTraitUseMethod(Stmt\TraitUse $use, string $method): ?Stmt\ClassMethod
    {
        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence && $method === $adaptation->method->toString()) {
                $traitName = $this->name($adaptation->trait);

                return null === $traitName ? null : $this->traitMethod($traitName, $method);
            }
        }
        foreach ($use->traits as $trait) {
            $traitName = $this->name($trait);
            $candidate = null === $traitName ? null : $this->traitMethod($traitName, $method);
            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function applyTraitAliasVisibility(
        Stmt\ClassMethod $method,
        Stmt\TraitUseAdaptation\Alias $adaptation,
    ): Stmt\ClassMethod {
        if (null === $adaptation->newModifier) {
            return $method;
        }
        $effective = clone $method;
        $effective->flags &= ~(Stmt\Class_::MODIFIER_PUBLIC | Stmt\Class_::MODIFIER_PROTECTED | Stmt\Class_::MODIFIER_PRIVATE);
        $effective->flags |= $adaptation->newModifier;

        return $effective;
    }

    /**
     * @param array<string, string> $propertyTypes
     * @param array<string, string> $types
     * @param array<string, array<string, list<string>>> $inventory
     */
    private function scanNode(Node $node, string $file, string $class, array $propertyTypes, array &$types, array &$inventory): void
    {
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $closureTypes = $types;
            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $type = $this->typeName($param->type);
                    if (null !== $type) {
                        $closureTypes['$' . $param->var->name] = $type;
                    }
                }
            }
            foreach ($node->getSubNodeNames() as $subNodeName) {
                if ('params' === $subNodeName) {
                    continue;
                }
                $child = $node->{$subNodeName};
                if ($child instanceof Node) {
                    $this->scanNode($child, $file, $class, $propertyTypes, $closureTypes, $inventory);
                } elseif (is_array($child)) {
                    foreach ($child as $nested) {
                        if ($nested instanceof Node) {
                            $this->scanNode($nested, $file, $class, $propertyTypes, $closureTypes, $inventory);
                        }
                    }
                }
            }

            return;
        }
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $type = $this->expressionType($node->expr, $types, $propertyTypes);
            if (null !== $type) {
                $types['$' . $node->var->name] = $type;
            }
        }
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier && 'values' === $node->name->toString()) {
            $receiverType = $this->expressionType($node->var, $types, $propertyTypes);
            if ((null !== $receiverType && $this->isEntityType($receiverType))
                || ($node->var instanceof Expr\Variable && 'this' === $node->var->name && isset($this->entityTypes[$class]))) {
                $inventory['direct_values'][$file][] = 'values';
            }
        }
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
            $receiverType = $this->expressionType($node->var, $types, $propertyTypes);
            if (in_array($method, ['get', 'label', 'toArray'], true) && null !== $receiverType && $this->isEntityType($receiverType)) {
                $inventory['entity_calls'][$file][] = $method;
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            if ($child instanceof Node) {
                $this->scanNode($child, $file, $class, $propertyTypes, $types, $inventory);
            } elseif (is_array($child)) {
                foreach ($child as $nested) {
                    if ($nested instanceof Node) {
                        $this->scanNode($nested, $file, $class, $propertyTypes, $types, $inventory);
                    }
                }
            }
        }
    }

    /** @param array<string, string> $types @param array<string, string> $propertyTypes */
    private function expressionType(Expr $expression, array $types, array $propertyTypes): ?string
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $types['$' . $expression->name] ?? null;
        }
        if ($expression instanceof Expr\PropertyFetch && $expression->var instanceof Expr\Variable && 'this' === $expression->var->name
            && $expression->name instanceof Node\Identifier) {
            return $propertyTypes[$expression->name->toString()] ?? null;
        }
        if ($expression instanceof Expr\New_ && $expression->class instanceof Name) {
            return $this->name($expression->class);
        }
        if (($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier) {
            $receiverType = $this->expressionType($expression->var, $types, $propertyTypes);
            if (null !== $receiverType) {
                return $this->methodReturnType(ltrim($receiverType, '?'), $expression->name->toString());
            }
        }

        return null;
    }

    private function methodReturnType(string $class, string $method): ?string
    {
        if (isset($this->methodReturnTypes[$class][$method])) {
            return $this->methodReturnTypes[$class][$method];
        }
        $parent = isset($this->classes[$class]) ? $this->name($this->classes[$class]['node']->extends) : null;

        return null === $parent ? null : $this->methodReturnType($parent, $method);
    }

    private function isEntityType(string $type): bool
    {
        return isset($this->entityTypes[ltrim($type, '?')]);
    }

    private function violatesOpaqueV2Signature(Stmt\ClassMethod $method, bool $revision, string $effectiveName): bool
    {
        $signatures = $revision ? self::REVISION_V2_SIGNATURES : self::STORAGE_V2_SIGNATURES;
        if (!isset($signatures[$effectiveName])) {
            return false;
        }
        [$expectedParameters, $expectedReturn] = $signatures[$effectiveName];
        $actualParameters = array_map(fn(Node\Param $parameter): ?string => $this->typeName($parameter->type), $method->params);

        return !$method->isPublic() || $method->isStatic() || $method->returnsByRef()
            || [] !== array_filter($method->params, static fn(Node\Param $parameter): bool => $parameter->byRef || $parameter->variadic)
            || $expectedParameters !== $actualParameters || $expectedReturn !== $this->typeName($method->returnType);
    }

    private function readsOpaqueBag(Stmt\ClassMethod $method, string $bag): bool
    {
        $reads = false;
        $isBag = static fn(Node $node): bool => $node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable && 'this' === $node->var->name
            && $node->name instanceof Node\Identifier && $bag === $node->name->toString();
        $walk = function (Node $node, bool $writeTarget = false) use (&$walk, &$reads, $isBag): void {
            if ($node instanceof Expr\Assign) {
                $walk($node->var, true);
                $walk($node->expr, false);

                return;
            }
            if ($node instanceof Stmt\Return_ && null !== $node->expr) {
                $walk($node->expr, false);

                return;
            }
            if ($isBag($node) && !$writeTarget) {
                $reads = true;
            }
            foreach ($node->getSubNodeNames() as $subNodeName) {
                $child = $node->{$subNodeName};
                if ($child instanceof Node) {
                    $walk($child, $writeTarget);
                } elseif (is_array($child)) {
                    foreach ($child as $nested) {
                        if ($nested instanceof Node) {
                            $walk($nested, $writeTarget);
                        }
                    }
                }
            }
        };
        $walk($method);

        return $reads;
    }

    private function typeName(Node\ComplexType|Node\Identifier|Name|null $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $inner = $this->typeName($type->type);

            return null === $inner ? null : '?' . $inner;
        }
        if ($type instanceof Name) {
            return $this->name($type);
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        return null;
    }

    private function name(?Name $name): ?string
    {
        if (null === $name) {
            return null;
        }
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }
}
