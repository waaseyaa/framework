<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
use Waaseyaa\Seo\Discovery\CrawlEligibilityPolicyInterface;
use Waaseyaa\Seo\Discovery\DiscoveryFailurePolicy;
use Waaseyaa\Seo\Discovery\Exception\DiscoveryConfigurationException;
use Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface;
use Waaseyaa\Seo\Discovery\SitemapContributorInterface;
use Waaseyaa\Seo\Discovery\SitemapPath;
use Waaseyaa\SSR\Http\CanonicalPublicOrigin;
use Waaseyaa\SSR\Http\SeoPublicController;

/**
 * The discovery seams (#2501), proved against a real SQLite-backed repository
 * and a real access handler rather than doubles, so the interaction between the
 * access pass and the policy pass is the production one.
 */
#[CoversClass(SeoPublicController::class)]
#[CoversClass(CanonicalPublicOrigin::class)]
final class SeoDiscoveryPolicyTest extends TestCase
{
    private const string ORIGIN = 'https://example.com';

    // ---------------------------------------------------------------- harness

    private function entityTypeManager(): EntityTypeManager
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $accessHandler = new EntityAccessHandler();
        $accessHandler->addPolicy(new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' ? AccessResult::allowed('public') : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        });

        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use (
                $dispatcher,
                $resolver,
                $database,
                $accessHandler,
            ): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    database: $database,
                    accessHandler: $accessHandler,
                );
            },
        );

        foreach (['article', 'ledger'] as $entityTypeId) {
            $manager->registerEntityType(new EntityType(
                id: $entityTypeId,
                label: ucfirst($entityTypeId),
                class: TestEntity::class,
                keys: TestEntity::definitionKeys(),
            ));
            $repository = $manager->getRepository($entityTypeId);
            $repository->save($repository->create(['title' => 'First', 'status' => 1]), validate: false);
            $repository->save($repository->create(['title' => 'Second', 'status' => 1]), validate: false);
        }

        return $manager;
    }

    private function origin(): CanonicalPublicOrigin
    {
        $origin = CanonicalPublicOrigin::tryFrom(self::ORIGIN);
        self::assertNotNull($origin);

        return $origin;
    }

    private function controller(
        ?PublicUrlPolicyInterface $urlPolicy = null,
        ?CrawlEligibilityPolicyInterface $eligibility = null,
        ?SitemapContributorInterface $contributor = null,
        ?DiscoveryFailurePolicy $failurePolicy = null,
        bool $withOrigin = true,
        ?EntityTypeManager $manager = null,
    ): SeoPublicController {
        return new SeoPublicController(
            $manager ?? $this->entityTypeManager(),
            null,
            null,
            $withOrigin ? $this->origin() : null,
            $urlPolicy,
            $eligibility,
            $contributor,
            $failurePolicy,
        );
    }

    /** A URL policy that maps every entity to `/a/{id}` and `/a/{id}?format=md`. */
    private function slugUrlPolicy(): PublicUrlPolicyInterface
    {
        return new class implements PublicUrlPolicyInterface {
            public function canonicalPath(EntityInterface $entity): ?string
            {
                return '/a/' . $entity->id();
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                return '/raw/' . $entity->id() . '?format=md';
            }
        };
    }

    private function typePolicy(string $allowed): CrawlEligibilityPolicyInterface
    {
        return new class ($allowed) implements CrawlEligibilityPolicyInterface {
            public function __construct(private readonly string $allowed) {}

            public function allowsEntityType(string $entityTypeId): bool
            {
                return $entityTypeId === $this->allowed;
            }
        };
    }

    // ------------------------------------------------- backward compatibility

    #[Test]
    public function binding_nothing_keeps_the_zero_config_relative_url_model(): void
    {
        $body = (string) $this->controller()->sitemapXml()->getContent();

        self::assertStringContainsString('<loc>/article/1</loc>', $body);
        self::assertStringContainsString('<loc>/ledger/2</loc>', $body);
        self::assertStringNotContainsString(self::ORIGIN, $body, 'zero-config locs stay relative even when an origin is configured');
    }

    #[Test]
    public function binding_nothing_keeps_the_zero_config_llms_links(): void
    {
        $body = (string) $this->controller()->llmsTxt()->getContent();

        self::assertStringContainsString('/article/1?format=md', $body);
    }

    #[Test]
    public function the_constructor_stays_positionally_compatible_with_existing_consumers(): void
    {
        $constructor = new \ReflectionMethod(SeoPublicController::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertSame('entityTypeManager', $parameters[0]->getName());
        self::assertSame('fieldReadScope', $parameters[1]->getName());
        self::assertSame('principalFactory', $parameters[2]->getName());
        self::assertSame('canonicalOrigin', $parameters[3]->getName());
        self::assertSame(1, $constructor->getNumberOfRequiredParameters(), 'new parameters must all be optional');

        foreach (array_slice($parameters, 4) as $added) {
            self::assertTrue($added->isDefaultValueAvailable(), $added->getName() . ' must be defaulted');
        }
    }

    // ------------------------------------------------------- canonical URL mode

    #[Test]
    public function a_bound_url_policy_produces_absolute_canonical_locs(): void
    {
        $body = (string) $this->controller(urlPolicy: $this->slugUrlPolicy())->sitemapXml()->getContent();

        self::assertStringContainsString('<loc>https://example.com/a/1</loc>', $body);
        self::assertStringNotContainsString('<loc>/article/1</loc>', $body, 'the built-in URL model must not survive alongside a bound policy');
    }

    #[Test]
    public function a_bound_url_policy_supplies_the_llms_markdown_links(): void
    {
        $body = (string) $this->controller(urlPolicy: $this->slugUrlPolicy())->llmsTxt()->getContent();

        self::assertStringContainsString('/raw/1?format=md', $body);
        self::assertStringNotContainsString('/article/1?format=md', $body);
    }

    #[Test]
    public function an_entity_without_a_canonical_path_is_omitted(): void
    {
        $policy = new class implements PublicUrlPolicyInterface {
            public function canonicalPath(EntityInterface $entity): ?string
            {
                return (string) $entity->id() === '1' ? '/a/1' : null;
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                return null;
            }
        };

        $body = (string) $this->controller(urlPolicy: $policy)->sitemapXml()->getContent();

        self::assertSame(1, substr_count($body, '<url>'));
        self::assertStringContainsString('https://example.com/a/1', $body);
    }

    // --------------------------------------------- trusted-origin ownership

    #[Test]
    public function canonical_mode_without_a_trusted_origin_fails_closed_rather_than_emitting_relative_urls(): void
    {
        $body = (string) $this->controller(
            urlPolicy: $this->slugUrlPolicy(),
            withOrigin: false,
        )->sitemapXml()->getContent();

        // Well-formed, and carrying no URL at all: never a relative or
        // request-derived substitute for the origin the application asked for.
        self::assertStringContainsString('<urlset', $body);
        self::assertStringNotContainsString('<url>', $body);
        self::assertStringNotContainsString('/a/1', $body);
    }

    #[Test]
    public function canonical_mode_without_a_trusted_origin_is_reported_under_the_propagate_policy(): void
    {
        $controller = $this->controller(
            urlPolicy: $this->slugUrlPolicy(),
            failurePolicy: DiscoveryFailurePolicy::Propagate,
            withOrigin: false,
        );

        $this->expectException(DiscoveryConfigurationException::class);
        $controller->sitemapXml();
    }

    // ------------------------------------------------------ type eligibility

    #[Test]
    public function an_application_may_narrow_the_eligible_entity_types(): void
    {
        $body = (string) $this->controller(
            urlPolicy: $this->slugUrlPolicy(),
            eligibility: $this->typePolicy('article'),
        )->sitemapXml()->getContent();

        self::assertSame(2, substr_count($body, '<url>'), 'only the two article rows remain');
    }

    #[Test]
    public function the_framework_floor_cannot_be_re_enabled_by_an_application_policy(): void
    {
        $manager = $this->entityTypeManager();
        $manager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        $repository = $manager->getRepository('user');
        $repository->save($repository->create(['title' => 'Someone', 'status' => 1]), validate: false);

        $permissive = new class implements CrawlEligibilityPolicyInterface {
            public function allowsEntityType(string $entityTypeId): bool
            {
                return true;
            }
        };

        $sitemap = (string) $this->controller(eligibility: $permissive, manager: $manager)->sitemapXml()->getContent();
        $llms = (string) $this->controller(eligibility: $permissive, manager: $manager)->llmsTxt()->getContent();

        self::assertStringNotContainsString('/user/', $sitemap);
        self::assertStringNotContainsString('/user/', $llms);
    }

    #[Test]
    public function an_eligibility_policy_that_throws_fails_closed_for_that_type(): void
    {
        $broken = new class implements CrawlEligibilityPolicyInterface {
            public function allowsEntityType(string $entityTypeId): bool
            {
                if ($entityTypeId === 'ledger') {
                    throw new RuntimeException('policy unavailable');
                }

                return true;
            }
        };

        $body = (string) $this->controller(urlPolicy: $this->slugUrlPolicy(), eligibility: $broken)
            ->sitemapXml()->getContent();

        self::assertSame(2, substr_count($body, '<url>'), 'the type whose policy threw is excluded, not admitted');
    }

    // ------------------------------------------------- malformed policy returns

    #[Test]
    public function a_malformed_canonical_path_drops_the_entity_with_no_legacy_fallback(): void
    {
        $hostile = new class implements PublicUrlPolicyInterface {
            public function canonicalPath(EntityInterface $entity): ?string
            {
                return match ((string) $entity->id()) {
                    '1' => 'https://evil.example/taken-over',
                    default => '/a/' . $entity->id(),
                };
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                return null;
            }
        };

        $body = (string) $this->controller(urlPolicy: $hostile, eligibility: $this->typePolicy('article'))
            ->sitemapXml()->getContent();

        self::assertStringNotContainsString('evil.example', $body);
        self::assertStringNotContainsString('/article/1', $body, 'never falls back to the built-in URL model');
        self::assertSame(1, substr_count($body, '<url>'));
        self::assertStringContainsString('https://example.com/a/2', $body);
    }

    #[Test]
    public function a_malformed_markdown_path_drops_the_entity_with_no_legacy_fallback(): void
    {
        $hostile = new class implements PublicUrlPolicyInterface {
            public function canonicalPath(EntityInterface $entity): ?string
            {
                return '/a/' . $entity->id();
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                return "/raw/{$entity->id()}?format=md\nSitemap: https://evil.example";
            }
        };

        $body = (string) $this->controller(urlPolicy: $hostile)->llmsTxt()->getContent();

        self::assertStringNotContainsString('evil.example', $body);
        self::assertStringNotContainsString('?format=md', $body);
    }

    #[Test]
    public function a_throwing_url_policy_drops_the_entity_under_the_default_failure_policy(): void
    {
        $body = (string) $this->controller(
            urlPolicy: $this->throwingUrlPolicy(),
            eligibility: $this->typePolicy('article'),
        )->sitemapXml()->getContent();

        self::assertSame(1, substr_count($body, '<url>'));
        self::assertStringContainsString('https://example.com/a/2', $body);
    }

    #[Test]
    public function a_throwing_url_policy_is_reported_under_the_propagate_policy(): void
    {
        $controller = $this->controller(
            urlPolicy: $this->throwingUrlPolicy(),
            failurePolicy: DiscoveryFailurePolicy::Propagate,
        );

        $this->expectException(RuntimeException::class);
        $controller->sitemapXml();
    }

    private function throwingUrlPolicy(): PublicUrlPolicyInterface
    {
        return new class implements PublicUrlPolicyInterface {
            public function canonicalPath(EntityInterface $entity): ?string
            {
                if ((string) $entity->id() === '1') {
                    throw new RuntimeException('url policy unavailable');
                }

                return '/a/' . $entity->id();
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                return null;
            }
        };
    }

    // ------------------------------------------------------------ contributors

    #[Test]
    public function contributed_paths_are_appended_in_order_and_joined_to_the_trusted_origin(): void
    {
        $contributor = new class implements SitemapContributorInterface {
            public function contributedPaths(): iterable
            {
                yield new SitemapPath('/services', changefreq: 'weekly', priority: 0.8);
                yield new SitemapPath('/calendar');
                yield new SitemapPath('/employment');
            }
        };

        $body = (string) $this->controller(
            urlPolicy: $this->slugUrlPolicy(),
            eligibility: $this->typePolicy('article'),
            contributor: $contributor,
        )->sitemapXml()->getContent();

        self::assertSame(5, substr_count($body, '<url>'));
        self::assertStringContainsString('<loc>https://example.com/services</loc>', $body);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $body);

        $order = [
            strpos($body, 'https://example.com/a/1'),
            strpos($body, 'https://example.com/services'),
            strpos($body, 'https://example.com/calendar'),
            strpos($body, 'https://example.com/employment'),
        ];
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'entity URLs first, then contributed URLs in the order supplied');
    }

    #[Test]
    public function duplicate_locations_are_suppressed_with_the_first_occurrence_winning(): void
    {
        $contributor = new class implements SitemapContributorInterface {
            public function contributedPaths(): iterable
            {
                // Collides with the entity URL for id 1, and with itself.
                yield new SitemapPath('/a/1', changefreq: 'never');
                yield new SitemapPath('/calendar');
                yield new SitemapPath('/calendar', changefreq: 'daily');
            }
        };

        $body = (string) $this->controller(
            urlPolicy: $this->slugUrlPolicy(),
            eligibility: $this->typePolicy('article'),
            contributor: $contributor,
        )->sitemapXml()->getContent();

        self::assertSame(3, substr_count($body, '<url>'), '2 entities + 1 surviving contributed URL');
        self::assertSame(1, substr_count($body, '<loc>https://example.com/a/1</loc>'));
        self::assertSame(1, substr_count($body, '<loc>https://example.com/calendar</loc>'));
        self::assertStringNotContainsString('<changefreq>never</changefreq>', $body, 'the entity entry won, so its metadata is what appears');
        self::assertStringNotContainsString('<changefreq>daily</changefreq>', $body, 'the first /calendar entry won');
    }

    #[Test]
    public function contributed_output_is_byte_stable_across_repeated_requests(): void
    {
        $contributor = new class implements SitemapContributorInterface {
            public function contributedPaths(): iterable
            {
                yield new SitemapPath('/services');
                yield new SitemapPath('/calendar');
            }
        };

        $manager = $this->entityTypeManager();
        $first = (string) $this->controller(urlPolicy: $this->slugUrlPolicy(), contributor: $contributor, manager: $manager)
            ->sitemapXml()->getContent();
        $second = (string) $this->controller(urlPolicy: $this->slugUrlPolicy(), contributor: $contributor, manager: $manager)
            ->sitemapXml()->getContent();

        self::assertSame($first, $second);
    }
}
