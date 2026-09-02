<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentRepository;
use Waaseyaa\Attachment\AttachmentServiceProvider;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/** Production-bus regression coverage for architecture-integrity issue #2760. */
#[CoversClass(AttachmentServiceProvider::class)]
final class AttachmentServiceProviderRepositoryResolutionTest extends TestCase
{
    #[Test]
    public function an_invalid_manager_binding_fails_closed(): void
    {
        $provider = new AttachmentServiceProvider();
        $provider->setKernelServices(new readonly class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return $abstract === \Waaseyaa\Entity\EntityTypeManagerInterface::class
                    ? new \stdClass()
                    : null;
            }
        });
        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('invalid entity type manager');
        $provider->resolve(AttachmentRepository::class);
    }

    #[Test]
    public function provider_bound_repository_resolves_and_persists_through_the_attachment_repository(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $fieldRegistry = new FieldDefinitionRegistry();
        $logger = new NullLogger();
        $manager = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: $dispatcher,
            fieldRegistry: $fieldRegistry,
            logger: $logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (): void {},
            fieldReadScope: new AccountFieldReadScope(),
            fieldTypes: $fieldRegistry->fieldTypeManager(),
        );

        $provider = new AttachmentServiceProvider();
        $services = new ProviderRegistryKernelServices(
            entityTypeManager: $manager,
            database: $database,
            dispatcher: $dispatcher,
            logger: $logger,
            providersAccessor: static fn(): array => [$provider],
        );
        $provider->setKernelContext(sys_get_temp_dir(), ['environment' => 'testing'], []);
        $provider->setKernelServices($services);
        $provider->register();
        foreach ($provider->getEntityTypes() as $entityType) {
            $manager->registerEntityType($entityType);
        }

        self::assertNull(
            $services->get(EntityRepositoryInterface::class),
            'The kernel must not invent an arbitrary context-free repository binding.',
        );
        self::assertNull(
            $manager->getDefinition('attachment')->getPrimaryStorageBackend(),
            'The public attachment composition declares the framework-default sql-blob layout.',
        );

        new EntitySchemaSyncRunner($database, $fieldRegistry, $logger)
            ->run($manager->getDefinitions());
        EntityMutationAuthoritySchema::ensure($database);

        $repository = $provider->resolve(AttachmentRepository::class);
        self::assertInstanceOf(AttachmentRepository::class, $repository);
        self::assertSame($repository, $provider->resolve(AttachmentRepository::class));
        $innerRepository = new \ReflectionProperty(AttachmentRepository::class, 'entityRepository');
        self::assertSame(
            $manager->getRepository('attachment'),
            $innerRepository->getValue($repository),
            'The specialized wrapper must select the attachment repository from the kernel manager.',
        );

        $attachment = new Attachment([
            'filename' => 'provider-bound.txt',
            'parent_entity_type' => 'node',
            'parent_entity_id' => '42',
            'is_active' => false,
        ]);
        $attachment->enforceIsNew();
        $repository->save($attachment);

        $loaded = $repository->listFor('node', '42');
        self::assertCount(1, $loaded);
        self::assertInstanceOf(Attachment::class, $loaded[0]);
        self::assertSame($attachment->id(), $loaded[0]->id());
    }
}
