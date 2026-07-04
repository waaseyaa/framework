<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\Http\SeoPublicController;

/**
 * Exploit/regression test — R6 PR3 (audit M3, the R5 residual on the SEO
 * surface).
 *
 * Before the fix, {@see \Waaseyaa\Seo\SitemapGenerator::collectFromEntityTypes()}
 * and {@see \Waaseyaa\Seo\Llms\LlmsTxtGenerator::collectTopics()} built their
 * enumeration query with `->accessCheck(false)` and gated ONLY on entity-type
 * membership plus a bare `status = 1` condition — never consulting an
 * `AccessPolicyInterface`. A PUBLISHED entity carrying an entity-level
 * Forbidden restriction (a classification hold, an OCAP/genealogy privacy
 * rule, etc.) still had its canonical path enumerated in `/sitemap.xml` and
 * `/llms.txt` for any anonymous crawler.
 *
 * {@see SeoPublicController} now binds an explicit `AnonymousUser` when
 * calling the generators, so the underlying `SqlEntityQuery::execute()` runs
 * the SAME per-candidate `EntityAccessHandler::check()` pipeline every other
 * read surface in the framework uses (see
 * `SqlEntityQueryAccessCheckTest::anonymousAccountSeesEmptyWhenPolicyForbidsAll`
 * for the underlying query-layer guarantee this relies on).
 *
 * A real SQLite-backed {@see EntityRepository} + a real {@see EntityAccessHandler}
 * are wired (not stubs) so this test exercises the production access-check
 * code path, not a test double's approximation of it.
 */
#[CoversClass(SeoPublicController::class)]
final class SeoPublicControllerAccessTest extends TestCase
{
    /**
     * Builds a controller backed by a real SQLite 'article' table with two
     * PUBLISHED rows: one publicly viewable, one carrying an entity-level
     * Forbidden restriction for anonymous (mirrors a classification hold).
     */
    private function controllerWithArticles(): SeoPublicController
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $accessHandler = $this->accessHandlerForbiddingRestrictedArticles();

        $entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use (
                $dispatcher,
                $resolver,
                $database,
                $accessHandler,
            ): EntityRepository {
                (new SqlSchemaHandler($definition, $database))->ensureTable();

                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    database: $database,
                    accessHandler: $accessHandler,
                );
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        $repository = $entityTypeManager->getRepository('article');
        // id 1: published, publicly viewable — the positive control.
        $repository->save(
            $repository->create(['title' => 'Public Story', 'status' => 1, 'restricted' => 0]),
            validate: false,
        );
        // id 2: PUBLISHED but access-restricted (e.g. a classification hold)
        // — must NOT be enumerated to an anonymous crawler.
        $repository->save(
            $repository->create(['title' => 'Held Story', 'status' => 1, 'restricted' => 1]),
            validate: false,
        );

        return new SeoPublicController($entityTypeManager);
    }

    /**
     * A real AccessPolicyInterface implementation (anonymous class — the
     * blessed pattern for intersection-free interfaces per the testing
     * conventions) that forbids `view` on any 'article' whose `restricted`
     * field is truthy, regardless of publish status. Mirrors the shape of
     * `ClassificationFieldAccessPolicy` (entity-level Forbidden on a
     * published entity) without depending on the `field` package.
     */
    private function accessHandlerForbiddingRestrictedArticles(): EntityAccessHandler
    {
        $handler = new EntityAccessHandler();
        $handler->addPolicy(new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation !== 'view') {
                    return AccessResult::neutral();
                }

                return $entity->get('restricted')
                    ? AccessResult::forbidden('held for anonymous viewing')
                    : AccessResult::allowed('publicly viewable');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        });

        return $handler;
    }

    #[Test]
    public function sitemap_excludes_a_published_but_access_restricted_entity(): void
    {
        $controller = $this->controllerWithArticles();

        $body = (string) $controller->sitemapXml()->getContent();

        self::assertStringContainsString('<loc>/article/1</loc>', $body, 'the publicly viewable published entity must be enumerated');
        self::assertStringNotContainsString('/article/2', $body, 'the access-restricted published entity must NOT be enumerated to anonymous');
    }

    #[Test]
    public function llms_txt_excludes_a_published_but_access_restricted_entity(): void
    {
        $controller = $this->controllerWithArticles();

        $body = (string) $controller->llmsTxt()->getContent();

        self::assertStringContainsString('/article/1?format=md', $body, 'the publicly viewable published entity must be enumerated');
        self::assertStringNotContainsString('/article/2?format=md', $body, 'the access-restricted published entity must NOT be enumerated to anonymous');
    }
}
