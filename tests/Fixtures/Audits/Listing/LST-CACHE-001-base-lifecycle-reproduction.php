<?php

declare(strict_types=1);
// Original-base synthetic provider reproducer, f2b2da6dd58092d611ad52f5a2bf0fb95712ffbc.
// Execute against that source root. Exit 0 reproduces historical defects.
require getcwd() . '/vendor/autoload.php';
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ServiceProvider as ListingProvider;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

final class AuditCacheProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(TaggedCacheInterface::class, static fn() => new MemoryBackend());
    }
}
final class AuditListingProvider extends ServiceProvider implements HasListingsInterface
{
    public function register(): void {}
    public function listings(): array
    {
        return [new ListingDefinition(id: 'audit_list', entityType: 'article', pageSize: 1)];
    }
}
final class AuditReadPolicy implements AccessPolicyInterface
{
    public const SUPPORTS_LISTING_FAST_PATH = true;
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'article';
    }
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }
}
$events = new EventDispatcher();
$type = new EntityType(id: 'article', label: 'Article', class: ArticleEntity::class, keys: ['id' => 'id','label' => 'title']);
$driver = new InMemoryStorageDriver();
foreach ([['id' => '1','title' => 'Alpha','weight' => 10,'status' => 1],['id' => '2','title' => 'Beta','weight' => 20,'status' => 0]] as $row) {
    $driver->write('article', $row['id'], $row);
}
$repo = V2EntityRepositoryFactory::create($type, $driver, $events);
$manager = new EntityTypeManager($events, repositoryFactory: static fn() => $repo, fieldRegistry: new FieldDefinitionRegistry());
$manager->registerEntityType($type);
$handler = new EntityAccessHandler([new AuditReadPolicy()]);
$providersRegistry = new ProviderRegistry(new NullLogger());
$providers = $providersRegistry->discoverAndRegister(new PackageManifest(providers: [AuditCacheProvider::class,ListingProvider::class,AuditListingProvider::class]), getcwd(), [], $manager, DBALDatabase::createSqlite(':memory:'), $events, accessHandlerAccessor: static fn() => $handler);
$providersRegistry->boot($providers);
$provider = $providers[1];
$resolver = $provider->resolve(ListingResolver::class);
$def = $provider->resolve(ListingDefinitionRegistry::class)->get('audit_list');
$initial = $resolver->resolve($def);
$repo->delete($repo->find('2'));
$cached = $resolver->resolve($def);
$provider->resolve(TaggedCacheInterface::class)->invalidateByTag('entity:article');
$fresh = $resolver->resolve($def);
$gate = $provider->resolve(\Waaseyaa\Access\Gate\GateInterface::class);
$output = ['initialTotal' => $initial->pagination->totalRows,'cachedAfterDelete' => $cached->pagination->totalRows,'freshAfterManualEviction' => $fresh->pagination->totalRows,'realGate' => $gate::class,'fastPathCapability' => $gate instanceof \Waaseyaa\Access\Gate\ListingFastPathProbeInterface];
echo json_encode($output),"\n";
exit($initial->pagination->totalRows === 2 && $cached->pagination->totalRows === 2 && $fresh->pagination->totalRows === 1 && !$output['fastPathCapability'] ? 0 : 1);
