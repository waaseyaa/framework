<?php

declare(strict_types=1);

// Run from extracted candidate bytes, never from a monorepo vendor tree.
// Usage: php listing.php <consumer-root> standalone|generate|generated
$root = realpath($argv[1] ?? '') ?: throw new RuntimeException('Consumer root missing.');
$mode = $argv[2] ?? '';
require $root . '/vendor/autoload.php';

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Listing\ExposedFilterParser;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ServiceProvider;

#[Waaseyaa\Entity\Attribute\ContentEntityType(id: 'article', label: 'Article')]
#[Waaseyaa\Entity\Attribute\ContentEntityKeys(label: 'title')]
final class ListingInstalledArticle extends ContentEntityBase
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';
}

final class ListingInstalledProvider extends Waaseyaa\Foundation\ServiceProvider\ServiceProvider implements HasListingsInterface
{
    public static ?MemoryBackend $cache = null;
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(ListingInstalledArticle::class));
        if (self::$cache !== null) {
            $this->singleton(TaggedCacheInterface::class, static fn() => self::$cache);
        }
    }
    public function listings(): array
    {
        return [new ListingDefinition(id: 'installed', entityType: 'article', filters: [Filter::contains('title', 'default')->withExposed('q')], pageSize: 1)];
    }
}

function listingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$installedListing = realpath($root . '/vendor/waaseyaa/listing') ?: throw new RuntimeException('Listing absent.');
$loadedResolver = realpath(new ReflectionClass(ListingResolver::class)->getFileName());
listingCheck(str_starts_with(str_replace('\\', '/', $loadedResolver), str_replace('\\', '/', $installedListing) . '/'), 'Resolver not bound to installed listing.');
listingCheck(!is_link($root . '/vendor/waaseyaa/listing'), 'Listing is linked, not extracted.');
listingCheck(!class_exists(PHPUnit\Framework\TestCase::class), 'Consumer includes dev PHPUnit.');

if ($mode === 'generate') {
    $recipe = new Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe();
    $yaml = sprintf(<<<'YAML'
        schema: waaseyaa.site
        version: 1
        generator_version: 1
        application:
          name: Listing Acceptance
          id: listing-acceptance
          canonical_origin:
            config_key: APP_ORIGIN
        framework:
          revision_policy: exact-lock
          observed_lock_sha256: %s
        content_types:
          - id: page
            canonical_route: /{slug}
        capabilities:
          - id: published_content
            state: active
            package: waaseyaa/listing
            provider: site.published_content
            configuration_authority: .waaseyaa/site.yaml#/capabilities/published_content
            public_routes: [/{slug}]
            data_classification: public
            lifecycle: [create, revise, publish, archive]
            verification: [tests/Acceptance/PublishedContentRecipeTest.php]
        personal_data_stores: []
        recipes:
          - id: published_content
            version: 1
            capability: published_content
            artifact_digest: %s
        verification:
          command: bin/maintenance/site-verify
        YAML, hash_file('sha256', $root . '/composer.lock'), $recipe::digest());
    $manifest = new Waaseyaa\SiteContract\SiteManifestParser()->parse($yaml);
    $plan = new Waaseyaa\SiteContract\Generation\SiteArtifactRenderer([$recipe])->compile($manifest);
    new Waaseyaa\CLI\Site\SiteInitializationService($root)->initialize($plan);
    $composer = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    listingCheck(in_array('App\\Provider\\PublishedContentServiceProvider', $composer['extra']['waaseyaa']['providers'], true), 'Generated provider missing from literal composition authority.');
    echo "listing installed recipe generation OK\n";
    exit(0);
}

if ($mode === 'generated') {
    $kernel = new HttpKernel($root);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    $repository = $kernel->getEntityTypeManager()->getRepository('node');
    foreach ([['Listing public witness', 'listing-public', 1], ['Listing draft witness', 'listing-draft', 0]] as [$title, $slug, $status]) {
        $repository->save(Waaseyaa\Node\Node::make(['type' => 'page', 'title' => $title, 'slug' => $slug, 'status' => $status, 'changed' => 1791451200]));
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['QUERY_STRING'] = '';
    $_GET = [];
    $response = $kernel->handle();
    listingCheck($response->getStatusCode() === 200, 'Generated listing route did not return 200.');
    listingCheck(str_contains($response->getContent(), 'Listing public witness'), 'Generated index omitted published row.');
    listingCheck(!str_contains($response->getContent(), 'Listing draft witness'), 'Generated index exposed draft row.');
    echo "listing generated installed HTTP journey OK\n";
    exit(0);
}

listingCheck($mode === 'standalone', 'Unknown listing acceptance mode.');
$driver = new InMemoryStorageDriver();
$driver->write('article', '1', ['id' => '1', 'title' => 'CAFÉ one']);
$driver->write('article', '2', ['id' => '2', 'title' => 'Other']);
foreach ([null, new MemoryBackend()] as $cache) {
    ListingInstalledProvider::$cache = $cache;
    $dispatcher = new EventDispatcher();
    $fields = new FieldDefinitionRegistry();
    $fields->registerCoreFields('article', Waaseyaa\Entity\Attribute\EntityMetadataReader::resolveFields(ListingInstalledArticle::class, 'article'));
    $manager = new EntityTypeManager(
        eventDispatcher: $dispatcher,
        fieldRegistry: $fields,
        repositoryFactory: static fn(string $id, EntityType $type) => V2EntityRepositoryFactory::create($type, $driver, $dispatcher),
    );
    $policy = new class implements AccessPolicyInterface {
        public bool $allow = true;
        public function appliesTo(string $id): bool
        {
            return $id === 'article';
        }
        public function createAccess(string $id, string $bundle, AccountInterface $account): AccessResult
        {
            return AccessResult::neutral();
        }
        public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
        {
            return $this->allow ? AccessResult::allowed() : AccessResult::forbidden();
        }
    };
    $handler = new EntityAccessHandler([$policy]);
    $registry = new ProviderRegistry(new NullLogger());
    $providers = $registry->discoverAndRegister(
        manifest: new PackageManifest(providers: [ServiceProvider::class, ListingInstalledProvider::class]),
        projectRoot: $root,
        config: [],
        entityTypeManager: $manager,
        database: DBALDatabase::createSqlite(':memory:'),
        dispatcher: $dispatcher,
        accessHandlerAccessor: static fn() => $handler,
        fieldReadScope: new AccountFieldReadScope(),
        requestContext: new RequestContext(),
    );
    $registry->boot($providers);
    $listing = $providers[0];
    $definition = $listing->resolve(ListingDefinitionRegistry::class)->get('installed');
    $resolver = $listing->resolve(ListingResolver::class);
    $values = ExposedFilterParser::create()->parse(['q' => 'café'], $definition);
    $result = $resolver->resolve($definition, $values);
    listingCheck(count($result->rows) === 1 && (string) $result->rows[0]->id() === '1', 'Installed parsed text contract failed.');
    listingCheck($result->pagination->totalRows === 1, 'Installed total contract failed.');
    $policy->allow = false;
    listingCheck($resolver->resolve($definition, $values)->pagination->totalRows === 0, 'Installed refusal contract failed.');
    $policy->allow = true;
    $contradiction = new ListingDefinition(id: 'and', entityType: 'article', filters: [Filter::eq('id', '1'), Filter::eq('id', '2')]);
    listingCheck($resolver->resolve($contradiction)->pagination->totalRows === 0, 'Installed equality conjunction failed.');
}
echo "listing standalone no-dev boot, parsed query, optional cache and refusal OK\n";
