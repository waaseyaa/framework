<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\CLI\Site\Blueprint\Emitter\AccessPolicyEmitter;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintConditionKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntityKeys;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintOperation;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicy;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicyCondition;
use Waaseyaa\SiteContract\Blueprint\BlueprintStorage;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;

#[CoversClass(AccessPolicyEmitter::class)]
final class AccessPolicyEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('access-policy', new AccessPolicyEmitter()->id());
    }

    #[Test]
    public function itEmitsNothingWhenNoEntityDeclaresAPolicyMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame([], $emission->artifacts);
    }

    #[Test]
    public function itEmitsOnlyForTheEntityDeclaringPoliciesMatchingTheCompleteGoldenFixture(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame('src/Access/ArticlePolicy.php', $emission->artifacts[0]->path);
        self::assertSame($this->expected('complete/src/Access/ArticlePolicy.php'), $emission->artifacts[0]->content);
    }

    /**
     * Loads the generated policy into a REAL {@see EntityAccessHandler} and a
     * real sealed entity carrying the raw `author`/`workflow_state` inputs
     * the ownership/workflow_state conditions read, proving the generated
     * decision logic end-to-end rather than only its byte shape.
     */
    #[Test]
    public function theGeneratedPolicyGrantsAndDeniesExactlyAsTheBlueprintDeclares(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        [$namespace, $entityClass, $policyClass] = $this->loadGeneratedPolicyWithEntity(
            $emission->artifacts[0]->content,
            $manifest,
        );

        $handler = new EntityAccessHandler([new $policyClass()]);

        $viewer = AuthorizationPrincipalFactory::authenticated(99, permissions: ['view article']);
        $editor = AuthorizationPrincipalFactory::authenticated(99, permissions: ['edit article']);
        $publisher = AuthorizationPrincipalFactory::authenticated(99, permissions: ['use editorial transition publish']);
        $anonymous = AuthorizationPrincipalFactory::anonymous();

        $owned = $this->sealedArticle($entityClass, authorId: 99, workflowState: 'published');
        $unowned = $this->sealedArticle($entityClass, authorId: 7, workflowState: 'published');
        $draft = $this->sealedArticle($entityClass, authorId: 7, workflowState: 'draft');

        self::assertTrue($handler->check($owned, 'view', $viewer)->isAllowed());
        self::assertFalse($handler->check($owned, 'view', $anonymous)->isAllowed());

        self::assertTrue($handler->check($owned, 'update', $editor)->isAllowed(), 'owner + edit article permission grants update');
        self::assertFalse($handler->check($unowned, 'update', $editor)->isAllowed(), 'non-owner is not granted by the ownership condition');
        self::assertTrue($handler->check($draft, 'update', $publisher)->isAllowed(), 'draft state + publish permission grants update');
        self::assertFalse($handler->check($owned, 'update', $publisher)->isAllowed(), 'published state does not satisfy the workflow_state condition');
        self::assertFalse($handler->check($unowned, 'update', $viewer)->isAllowed());

        self::assertTrue($handler->check($owned, 'delete', $editor)->isAllowed(), 'owner + edit article permission grants delete (article_delete_own)');
        self::assertFalse($handler->check($unowned, 'delete', $editor)->isAllowed(), 'a non-owner holding the permission is not granted delete');
        self::assertFalse($handler->check($owned, 'delete', $viewer)->isAllowed());

        self::assertInstanceOf(AccessResult::class, new $policyClass()->createAccess('article', 'article', $viewer));
        self::assertTrue(new $policyClass()->createAccess('article', 'article', $editor)->isAllowed(), 'edit article permission grants create (article_create)');
        self::assertTrue(new $policyClass()->createAccess('article', 'article', $viewer)->isNeutral());
        self::assertFalse($handler->checkCreateAccess('article', 'article', $anonymous)->isAllowed());
    }

    /**
     * Root-application policies are discovered ONLY by scanning for
     * `#[PolicyAttribute]` ({@see \Waaseyaa\Foundation\Discovery\PackageManifestCompiler}
     * and instantiated from the manifest by
     * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry}) —
     * a generated policy class with no attribute is never wired into
     * `EntityAccessHandler` at boot, silently denying every grant it
     * declares. Proves the attribute is present and carries the right
     * entity type, via reflection on the real loaded class (#2788 review F1).
     */
    #[Test]
    public function theGeneratedPolicyClassCarriesADiscoverablePolicyAttribute(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        [, , $policyClass] = $this->loadGeneratedPolicyWithEntity(
            $emission->artifacts[0]->content,
            $manifest,
        );

        $attributes = new \ReflectionClass($policyClass)->getAttributes(\Waaseyaa\Access\Gate\PolicyAttribute::class);
        self::assertNotSame([], $attributes, 'Generated policy class must carry #[PolicyAttribute] to be discovered at boot.');

        $instance = $attributes[0]->newInstance();
        self::assertSame(['article'], $instance->entityTypes);
    }

    /**
     * #2788 review gap 3: an entity-level `update` grant must not let the
     * grantee rewrite the very inputs the grant was decided on. The generated
     * policy therefore also implements {@see FieldAccessPolicyInterface}
     * through the canonical `EntityAccessHandler::checkFieldAccess()` path:
     * the `keys.owner` field is edit-Forbidden on a persisted entity
     * (ownership reassignment) while still settable at create
     * (`isNew()`, the `NodeAccessPolicy` authorship precedent), and the
     * engine-owned `workflow_state` selector is edit-Forbidden always
     * (`TransitionService` is its only writer). Ordinary fields stay
     * open-by-default, and `view` is never Forbidden here (Protected fields
     * are concealed by the read layout, not by this policy).
     */
    #[Test]
    public function theGeneratedPolicySealsAuthorizationInputsAgainstEditWhileOrdinaryFieldsStayOpen(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        [, $entityClass, $policyClass] = $this->loadGeneratedPolicyWithEntity($emission->artifacts[0]->content, $manifest);
        self::assertInstanceOf(FieldAccessPolicyInterface::class, new $policyClass());

        $handler = new EntityAccessHandler([new $policyClass()]);
        $owner = AuthorizationPrincipalFactory::authenticated(99, permissions: ['edit article']);
        $persisted = $this->sealedArticle($entityClass, authorId: 99, workflowState: 'draft');
        self::assertFalse($persisted->isNew());
        self::assertTrue($handler->check($persisted, 'update', $owner)->isAllowed(), 'precondition: the owner holds an entity-level update grant');

        self::assertFalse($handler->checkFieldAccess($persisted, 'title', 'edit', $owner)->isForbidden(), 'ordinary field update stays open');
        self::assertFalse($handler->checkFieldAccess($persisted, 'stage', 'edit', $owner)->isForbidden());
        self::assertTrue($handler->checkFieldAccess($persisted, 'author', 'edit', $owner)->isForbidden(), 'owner reassignment on a persisted entity is refused');
        self::assertTrue($handler->checkFieldAccess($persisted, 'workflow_state', 'edit', $owner)->isForbidden(), 'the workflow selector is engine-owned');
        self::assertFalse($handler->checkFieldAccess($persisted, 'author', 'view', $owner)->isForbidden(), 'view is concealed by the read layout, never Forbidden here');

        $fresh = new $entityClass(['title' => 'New']);
        self::assertTrue($fresh->isNew());
        self::assertFalse($handler->checkFieldAccess($fresh, 'author', 'edit', $owner)->isForbidden(), 'authorship is settable at create');
        self::assertTrue($handler->checkFieldAccess($fresh, 'workflow_state', 'edit', $owner)->isForbidden(), 'the selector is engine-owned even at create');
    }

    /**
     * A policy for an entity with no authorization inputs (no `keys.owner`,
     * not workflow-bound) has nothing to seal, so it stays an
     * `AccessPolicyInterface`-only class: the field-access surface is
     * introduced only where a protected input exists.
     */
    #[Test]
    public function aPolicyForAnEntityWithoutAuthorizationInputsDoesNotImplementFieldAccess(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithOnePolicy(
            BlueprintOperation::View,
            new BlueprintPolicyCondition(BlueprintConditionKind::Permission, permission: 'view article'),
        ));
        $emission = new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertStringNotContainsString('FieldAccessPolicyInterface', $emission->artifacts[0]->content);
        self::assertStringNotContainsString('fieldAccess(', $emission->artifacts[0]->content);
    }

    #[Test]
    public function anOwnershipConditionOnCreateIsRefusedGen007BeforeAnyArtifactIsEmitted(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithOnePolicy(
            BlueprintOperation::Create,
            new BlueprintPolicyCondition(BlueprintConditionKind::Ownership, permission: 'edit article'),
        ));

        try {
            new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $exception->violations[0]->code);
            self::assertStringContainsString('create', $exception->violations[0]->message);
        }
    }

    #[Test]
    public function aWorkflowStateConditionOnCreateIsRefusedGen007BeforeAnyArtifactIsEmitted(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithOnePolicy(
            BlueprintOperation::Create,
            new BlueprintPolicyCondition(BlueprintConditionKind::WorkflowState, permission: 'use editorial transition publish', states: ['draft']),
        ));

        try {
            new AccessPolicyEmitter()->emit($manifest->applicationBlueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $exception->violations[0]->code);
        }
    }

    /**
     * @return array{0: string, 1: class-string, 2: class-string<\Waaseyaa\Access\AccessPolicyInterface>}
     *   namespace, entity class FQCN, policy class FQCN
     */
    private function loadGeneratedPolicyWithEntity(string $policySource, SiteManifest $manifest): array
    {
        $entitySource = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/complete/src/Entity/Article.php',
        );
        $enumSource = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/complete/src/Entity/Enum/ArticleStage.php',
        );

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintAccessPolicy' . bin2hex(random_bytes(4));
        $entitySource = str_replace(
            ['namespace App\\Entity;', 'App\\Entity\\Enum\\ArticleStage'],
            ['namespace ' . $namespace . '\\Entity;', $namespace . '\\Entity\\Enum\\ArticleStage'],
            $entitySource,
        );
        $enumSource = str_replace('namespace App\\Entity\\Enum;', 'namespace ' . $namespace . '\\Entity\\Enum;', $enumSource);
        $policySource = str_replace(
            ['namespace App\\Access;', 'App\\Entity\\Article'],
            ['namespace ' . $namespace . '\\Access;', $namespace . '\\Entity\\Article'],
            $policySource,
        );

        $dir = sys_get_temp_dir() . '/waaseyaa_access_policy_emitter_' . bin2hex(random_bytes(8));
        mkdir($dir . '/Entity/Enum', 0o700, true);
        file_put_contents($dir . '/Entity/Enum/ArticleStage.php', $enumSource);
        file_put_contents($dir . '/Entity/Article.php', $entitySource);
        file_put_contents($dir . '/ArticlePolicy.php', $policySource);

        require $dir . '/Entity/Enum/ArticleStage.php';
        require $dir . '/Entity/Article.php';
        require $dir . '/ArticlePolicy.php';

        return [$namespace, $namespace . '\\Entity\\Article', $namespace . '\\Access\\ArticlePolicy'];
    }

    /** @param class-string $entityClass */
    private function sealedArticle(string $entityClass, int $authorId, string $workflowState): \Waaseyaa\Entity\EntityBase
    {
        $values = [
            'id' => 1,
            'title' => 'Welcome',
            'author' => $authorId,
            'workflow_state' => $workflowState,
        ];
        $levels = [
            'id' => \Waaseyaa\Entity\FieldReadLevel::Public,
            'title' => \Waaseyaa\Entity\FieldReadLevel::Public,
            'author' => \Waaseyaa\Entity\FieldReadLevel::Protected,
            'workflow_state' => \Waaseyaa\Entity\FieldReadLevel::Protected,
        ];
        $layout = new \Waaseyaa\Entity\EntityReadLayout(
            new \Waaseyaa\Entity\EntityReadLayoutGeneration(),
            $levels,
            ['author', 'workflow_state'],
        );
        $structure = new \Waaseyaa\Entity\EntityStructure(
            entityTypeId: 'article',
            bundleId: 'article',
            id: $values['id'],
            uuid: null,
            fieldNames: array_keys($values),
        );
        $boundary = new \Waaseyaa\Entity\EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: $layout,
            structure: $structure,
            entityTypeId: 'article',
            entityKeys: ['id' => 'id', 'label' => 'title'],
        );
        $entity = $boundary->installer()->instantiate($entityClass, $payload);
        self::assertInstanceOf($entityClass, $entity);
        self::assertInstanceOf(\Waaseyaa\Entity\EntityBase::class, $entity);

        return $entity;
    }

    private function blueprintWithOnePolicy(BlueprintOperation $operation, BlueprintPolicyCondition $condition): ApplicationBlueprint
    {
        return new ApplicationBlueprint(
            contractVersion: 1,
            entities: [
                'article' => new BlueprintEntity(
                    id: 'article',
                    label: 'Article',
                    storage: BlueprintStorage::SqlBlob,
                    revisionable: false,
                    translatable: false,
                    keys: new BlueprintEntityKeys(id: 'id', uuid: 'uuid', label: 'title'),
                    fields: [
                        'title' => new BlueprintField('title', BlueprintFieldType::String, true, 1, false, false, false),
                    ],
                ),
            ],
            relationships: [],
            permissions: [],
            roles: [],
            policies: [
                'bad_policy' => new BlueprintPolicy('bad_policy', 'article', $operation, $condition),
            ],
            workflows: [],
            fixtures: [],
            checks: [],
        );
    }

    private function manifestWithBlueprint(ApplicationBlueprint $blueprint): SiteManifest
    {
        $parsed = $this->manifest('minimal.yaml');

        return new SiteManifest(
            $parsed->schemaVersion,
            $parsed->generatorVersion,
            $parsed->application,
            $parsed->framework,
            $parsed->contentTypes,
            $parsed->capabilities,
            $parsed->personalDataStores,
            $parsed->recipes,
            $parsed->verificationCommand,
            $parsed->canonicalJson,
            $parsed->digest,
            $blueprint,
            $parsed->requiredGeneratorFeatures,
        );
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
