<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Engagement\EngagementAccessPolicy;
use Waaseyaa\Engagement\EngagementAccess;
use Waaseyaa\Engagement\EngagementMutationInvariant;
use Waaseyaa\Engagement\EngagementMutationScope;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementRateLimiter;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\EngagementServiceProvider;
use Waaseyaa\Engagement\EngagementTokens;
use Waaseyaa\Engagement\EngagementRetention;
use Waaseyaa\Engagement\Migration\EngagementDataUpgrader;
use Waaseyaa\Engagement\Rekey\EngagementEphemeralTokenRekeyAdapter;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Real SQLite service harness. Each instance owns its database and lock directory. */
final class ServiceEnvironment
{
    public readonly DBALDatabase $database;
    public readonly EntityTypeManagerInterface $entities;
    public readonly EngagementMutationScope $scope;
    public readonly ServicePolicy $policy;
    public readonly AuthorizationPrincipal $alice;
    public readonly AuthorizationPrincipal $bob;
    public readonly RootReference $rootReference;
    public readonly ContentEntityBase $root;
    public readonly RootReference $secondRootReference;
    public readonly ContentEntityBase $secondRoot;
    public readonly EngagementService $service;
    public readonly EngagementTokens $tokens;
    public readonly EngagementRetention $retention;
    public readonly AccountFieldReadScope $reads;
    public readonly EntityAccessHandler $accessHandler;
    public readonly EngagementAccessPolicy $accessPolicy;
    public readonly ContentEntityBase $aliceEntity;
    public readonly RequestAccountContext $accountContext;
    public int $now = 1_800_000_000;
    private string $lockDir;

    public function __construct(bool $columnLayout = false)
    {
        $this->database = DBALDatabase::createSqlite();
        $provider = new EngagementServiceProvider();
        $provider->register();
        $definitions = [];
        foreach ($provider->getEntityTypes() as $type) {
            if ($columnLayout && in_array($type->id(), ['comment', 'reaction'], true)) {
                $type = new EntityType(
                    id: $type->id(), label: $type->getLabel(), class: $type->getClass(),
                    storageClass: $type->getStorageClass(), keys: $type->getKeys(),
                    revisionable: $type->isRevisionable(), revisionDefault: $type->getRevisionDefault(),
                    translatable: $type->isTranslatable(), bundleEntityType: $type->getBundleEntityType(),
                    constraints: $type->getConstraints(), group: $type->getGroup(), description: $type->getDescription(),
                    primaryStorageBackend: 'sql-column', tenancy: $type->getTenancy(),
                    discoverable: $type->isDiscoverable(), api: $type->isApiExposed(),
                    _fieldDefinitions: $type->getFieldDefinitions(), _foreignKeys: $type->getStorageForeignKeys(),
                    _storageUniqueKeys: $type->getStorageUniqueKeys(), _storageSchemaTransitions: $type->getStorageSchemaTransitions(),
                    requiredMutationInvariant: $type->getRequiredMutationInvariant(),
                );
            }
            $definitions[] = $type;
        }
        $definitions[] = EntityType::fromClass(ServiceActor::class);
        $definitions[] = EntityType::fromClass(ServiceRoot::class);

        $accounts = $this->accountContext = new RequestAccountContext();
        $reads = $this->reads = new AccountFieldReadScope();
        $rootAccessHandler = new EntityAccessHandler([new ServiceRootAccessPolicy()]);
        $this->scope = new EngagementMutationScope($accounts, $reads, fn (): EntityAccessHandler => $this->accessHandler);
        $registry = new FieldDefinitionRegistry();
        $manager = null;
        $this->policy = new ServicePolicy($this->database);
        $this->alice = new AuthorizationPrincipal(1, true, [], [], 'service-test-alice');
        $this->bob = new AuthorizationPrincipal(2, true, [], [], 'service-test-bob');
        $manager = new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: new SymfonyEventDispatcherAdapter(),
            fieldRegistry: $registry,
            logger: new NullLogger(),
            accessHandlerResolver: static fn () => null,
            communityScoreResolver: static fn () => null,
            accountContextAttacher: static function (object $repository) use ($accounts): void { $repository->setAccountContext($accounts); },
            fieldReadScope: $reads,
            fieldTypes: $registry->fieldTypeManager(),
            mutationInvariantResolver: function (string $id) use ($reads, &$manager): object {
                if ($id !== EngagementMutationInvariant::class || !$manager instanceof EntityTypeManagerInterface) {
                    throw new \LogicException('Unexpected engagement mutation invariant.');
                }
                return new EngagementMutationInvariant(
                    $this->database,
                    new EngagementAccess($this->database, $manager, new EntityAccessHandler([new ServiceRootAccessPolicy()]), $this->policy),
                    $this->scope,
                );
            },
        );
        $this->entities = $manager;
        foreach ($definitions as $definition) { $manager->registerEntityType($definition); }
        (new EntitySchemaSync($this->database, $registry, fieldTypes: $registry->fieldTypeManager()))->syncAll($manager->getDefinitions());
        EntityMutationAuthoritySchema::ensure($this->database);
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_11_13_000001_engagement_auxiliary_schema.php';
        $migration->up(new \Waaseyaa\Foundation\Migration\SchemaBuilder($this->database->getConnection()));

        foreach ([1 => 'Alice', 2 => 'Bob'] as $id => $name) {
            $actor = $manager->getRepository('user')->create(['uid' => $id, 'name' => $name]);
            $manager->getRepository('user')->save($actor);
            if ($id === 1) { $this->aliceEntity = $actor; }
        }
        $this->root = $manager->getRepository('service_root')->create(['title' => 'Synthetic published root']);
        $manager->getRepository('service_root')->save($this->root);
        $this->rootReference = new RootReference('service_root', (int) $this->root->id());
        $this->secondRoot = $manager->getRepository('service_root')->create(['title' => 'Second synthetic root']);
        $manager->getRepository('service_root')->save($this->secondRoot);
        $this->secondRootReference = new RootReference('service_root', (int) $this->secondRoot->id());
        (new EngagementDataUpgrader($this->database, $manager, $this->scope))->runBatch(200);

        $this->accessPolicy = new EngagementAccessPolicy(
            $manager, $rootAccessHandler, $this->policy, $this->scope, $this->database,
        );
        $this->accessHandler = new EntityAccessHandler([$this->accessPolicy, new ServiceRootAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $reads,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
        $this->lockDir = sys_get_temp_dir() . '/waaseyaa_engagement_service_' . bin2hex(random_bytes(8));
        if (!mkdir($this->lockDir, 0700, true) && !is_dir($this->lockDir)) { throw new \RuntimeException('Could not create fixture lock directory.'); }
        $tokens = $this->tokens = new EngagementTokens(self::keyring(), 'service-test-install', fn (): int => $this->now);
        $this->service = new EngagementService(
            $this->database, $manager,
            new \Waaseyaa\Engagement\EngagementAccess($this->database, $manager, new EntityAccessHandler([new ServiceRootAccessPolicy()]), $this->policy),
            $this->scope, $this->tokens, new EngagementRateLimiter($this->database, 'service-test-install', $this->lockDir),
            $reads, clock: fn (): int => $this->now,
        );
        $this->retention = new EngagementRetention($this->database, $manager, $this->scope, fn (): int => $this->now);
    }

    public function __destruct()
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
        if (is_dir($this->lockDir)) { rmdir($this->lockDir); }
    }

    public static function keyring(): ApplicationMasterKeyring
    {
        $registry = new ApplicationMasterPurposeRegistry();
        foreach ([EngagementTokens::SUBMISSION_PURPOSE => EngagementTokens::SUBMISSION_TTL, EngagementTokens::CURSOR_PURPOSE => EngagementTokens::CURSOR_TTL] as $purpose => $ttl) {
            $registry->register(new ApplicationMasterPurposePolicy($purpose, 'waaseyaa/engagement', ApplicationMasterPurposeStrategy::ReencryptCiphertext, $ttl, $ttl, EngagementEphemeralTokenRekeyAdapter::ID, 'tokens-expire-naturally'));
        }
        $registry->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new class implements SecretProviderInterface {
            public function id(): string { return 'service-test-master'; }
            public function resolve(SecretReference $reference): SensitiveValue { return SensitiveValue::fromBytes(hash('sha256', $reference->identifier(), true), SecretClass::ApplicationMaster, $reference->identifier()); }
        });
        $resolver->allow('service-test-master', ApplicationMasterKeyring::PACKAGE, SecretClass::ApplicationMaster, ApplicationMasterKeyring::MASTER_PURPOSE, ['testing']);
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();
        return ApplicationMasterKeyring::fromReferences($resolver, 1, SecretReference::create('service-test-master', 'master-v1', SecretClass::ApplicationMaster, ApplicationMasterKeyring::MASTER_PURPOSE), [], $registry);
    }
}

#[ContentEntityType(id: 'user', label: 'Service test actor', api: false)]
#[ContentEntityKeys(id: 'uid', uuid: 'uuid', label: 'name')]
final class ServiceActor extends ContentEntityBase {}

#[ContentEntityType(id: 'service_root', label: 'Service test root', api: false)]
#[ContentEntityKeys(id: 'rid', uuid: 'uuid', label: 'title')]
final class ServiceRoot extends ContentEntityBase {}

final class ServiceRootAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool { return $entityTypeId === 'service_root'; }
    public function access(EntityInterface $entity, string $operation, \Waaseyaa\Access\AccountInterface $account): AccessResult { return AccessResult::allowed('Synthetic published root.'); }
    public function createAccess(string $entityTypeId, string $bundle, \Waaseyaa\Access\AccountInterface $account): AccessResult { return AccessResult::allowed(); }
}

final class ServicePolicy implements EngagementPolicyInterface
{
    private bool $eligible = true;
    private bool $rootVisible = true;
    private bool $aliceBlocked = false;
    public bool $moderation = false;
    public int $version = 1;
    public function __construct(private readonly DBALDatabase $database) {}
    public function setEligible(bool $eligible): void { $this->eligible = $eligible; ++$this->version; }
    public function setRootVisible(bool $visible): void { $this->rootVisible = $visible; ++$this->version; }
    public function setAliceBlocked(bool $blocked): void { $this->aliceBlocked = $blocked; ++$this->version; }
    public function databaseIdentity(): string { return $this->database->databaseIdentity(); }
    public function generation(AuthorizationPrincipalInterface $viewer): string { return (string) $this->version; }
    public function canViewRoot(AuthorizationPrincipalInterface $viewer, EntityInterface $root): bool { return $this->rootVisible; }
    public function isEligible(AuthorizationPrincipalInterface $actor): bool { return $this->eligible; }
    public function canInteract(AuthorizationPrincipalInterface $actor, EntityInterface $root, string $operation): bool { return $this->eligible; }
    public function canViewActor(AuthorizationPrincipalInterface $viewer, int $authorId, EntityInterface $root): bool { return !($this->aliceBlocked && $authorId === 1 && (int) $viewer->id() !== 1); }
    public function canModerate(AuthorizationPrincipalInterface $actor, EntityInterface $root): bool { return $this->moderation; }
}
