<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\Document\SearchDocument;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;
use Waaseyaa\Search\ProvidesEntitySearchProjectorsInterface;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchServiceProvider;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;

/**
 * #2270: the production wiring must thread one shared projection registry
 * through the candidate resolver and the lifecycle subscriber, defaulting to
 * the built-in node projector with application projectors taking precedence.
 */
#[CoversClass(SearchServiceProvider::class)]
final class SearchServiceProviderProjectionWiringTest extends TestCase
{
    #[Test]
    public function the_projection_registry_is_bound_with_the_default_node_projector(): void
    {
        $provider = $this->registeredProvider($this->kernelServices());

        $registry = $provider->resolve(EntitySearchProjectionRegistry::class);

        self::assertInstanceOf(EntitySearchProjectionRegistry::class, $registry);
        $document = $registry->resolveIndexable($this->nodeEntity(7, 'Registry default'));
        self::assertNotNull($document, 'Ordinary node entities must be projectable out of the box.');
        self::assertSame('node:7', $document->getSearchDocumentId());
    }

    #[Test]
    public function application_projectors_take_precedence_over_the_default_node_projector(): void
    {
        $appProjectors = new class implements ProvidesEntitySearchProjectorsInterface {
            public function entitySearchProjectors(): array
            {
                return [
                    new class implements EntitySearchProjectorInterface {
                        public function supports(EntityInterface $entity): bool
                        {
                            return $entity->getEntityTypeId() === 'node';
                        }

                        public function project(EntityInterface $entity): ?SearchIndexableInterface
                        {
                            return new SearchDocument('node:' . $entity->id(), 'App override', 'app body', [
                                'entity_type' => 'node',
                            ]);
                        }
                    },
                ];
            }
        };
        $provider = $this->registeredProvider($this->kernelServices(projectorProvider: $appProjectors));

        $registry = $provider->resolve(EntitySearchProjectionRegistry::class);

        self::assertInstanceOf(EntitySearchProjectionRegistry::class, $registry);
        $document = $registry->resolveIndexable($this->nodeEntity(7, 'Ignored'));
        self::assertNotNull($document);
        self::assertSame('App override', $document->toSearchDocument()['title']);
    }

    #[Test]
    public function the_candidate_resolver_reaches_projector_backed_entities(): void
    {
        $node = $this->nodeEntity(1, 'Wired through the provider');
        $provider = $this->registeredProvider($this->kernelServices(
            entityTypeManager: $this->entityTypeManager($node),
            accessHandler: new EntityAccessHandler([$this->allowViewPolicy()]),
        ));

        $resolver = $provider->resolve(SearchCandidateResolverInterface::class);
        self::assertInstanceOf(SearchCandidateResolverInterface::class, $resolver);

        $projection = $resolver->resolve(new SearchCandidateReference('node:1', 'node'), SearchTestPrincipal::create());

        self::assertNotNull($projection, 'The production resolver must project ordinary node entities.');
        self::assertSame('Wired through the provider', $projection->title);
    }

    #[Test]
    public function the_booted_lifecycle_subscriber_indexes_projector_backed_entities(): void
    {
        $node = $this->nodeEntity(1, 'Booted subscriber');
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $provider = $this->registeredProvider($this->kernelServices(
            entityTypeManager: $this->entityTypeManager($node),
            database: $database,
            dispatcher: $dispatcher,
        ));

        $provider->boot();
        $dispatcher->dispatch(new EntityEvent($node), EntityEvents::POST_SAVE->value);

        $rows = iterator_to_array($database->query(
            "SELECT document_id FROM search_metadata WHERE document_id = 'node:1'",
        ));
        self::assertCount(1, $rows, 'The booted subscriber must index ordinary node entities through the projection registry.');
    }

    private function registeredProvider(KernelServicesInterface $services): SearchServiceProvider
    {
        $provider = new SearchServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        return $provider;
    }

    private function kernelServices(
        ?EntityTypeManagerInterface $entityTypeManager = null,
        ?EntityAccessHandler $accessHandler = null,
        ?DatabaseInterface $database = null,
        ?EventDispatcher $dispatcher = null,
        ?ProvidesEntitySearchProjectorsInterface $projectorProvider = null,
    ): KernelServicesInterface {
        return new class (
            $entityTypeManager ?? $this->createStub(EntityTypeManagerInterface::class),
            $accessHandler ?? new EntityAccessHandler([]),
            new AccountFieldReadScope(),
            $database ?? DBALDatabase::createSqlite(),
            $dispatcher,
            $projectorProvider,
        ) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $entityTypeManager,
                private readonly EntityAccessHandler $accessHandler,
                private readonly AccountFieldReadScopeInterface $fieldReadScope,
                private readonly DatabaseInterface $database,
                private readonly ?EventDispatcher $dispatcher,
                private readonly ?ProvidesEntitySearchProjectorsInterface $projectorProvider,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class => $this->entityTypeManager,
                    EntityAccessHandler::class => $this->accessHandler,
                    AccountFieldReadScopeInterface::class => $this->fieldReadScope,
                    DatabaseInterface::class => $this->database,
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    ProvidesEntitySearchProjectorsInterface::class => $this->projectorProvider,
                    default => null,
                };
            }
        };
    }

    private function entityTypeManager(EntityInterface $servedEntity): EntityTypeManagerInterface
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($servedEntity);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $type): bool => $type === 'node');
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }

    private function allowViewPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('test');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }
        };
    }

    private function nodeEntity(int $id, string $title): ContentEntityBase
    {
        return new class (['nid' => $id, 'title' => $title, 'type' => 'page', 'body' => 'Body text.']) extends ContentEntityBase {
            public function __construct(array $values)
            {
                parent::__construct($values, 'node', ['id' => 'nid', 'label' => 'title', 'bundle' => 'type']);
            }
        };
    }
}
