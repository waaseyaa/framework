<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Relationship;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupType;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipServiceProvider;
use Waaseyaa\Tests\Support\ComposerProjectFixture;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\User;

/**
 * #1958 — production-shaped proof that RelationshipPreSaveListener is wired
 * through the real kernel boot path (no manual listener registration), rejects
 * invalid relationship writes before persistence / POST_SAVE, and retains
 * normal PRE_SAVE → persist → POST_SAVE behaviour for valid writes.
 *
 * Negative control: on a booted kernel whose RelationshipServiceProvider::boot()
 * has been stripped of the PRE_SAVE registration, invalid relationship saves
 * persist and POST_SAVE still fires. That proves this suite fails against the
 * pre-#1992/#1958 unwired implementation.
 */
#[CoversNothing]
final class RelationshipPreSaveListenerKernelWiringTest extends TestCase
{
    private string $repoRoot;

    private string $projectRoot;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        ProcessFieldReadRuntime::reset();
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa-1958-' . bin2hex(random_bytes(6));
        foreach ([
            'APP_ENV',
            'APP_DEBUG',
            'WAASEYAA_APP_SECRET',
            'AUTH_TOKEN_SECRET',
            'WAASEYAA_DB',
            'WAASEYAA_ENTITY_VALIDATION',
        ] as $name) {
            $this->originalEnv[$name] = getenv($name);
        }
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        copy($this->repoRoot . '/VERSION', $this->projectRoot . '/VERSION');
        copy($this->repoRoot . '/composer.lock', $this->projectRoot . '/composer.lock');
        copy($this->repoRoot . '/composer.json', $this->projectRoot . '/composer.json');
        $this->putEnv('APP_ENV', 'testing');
        $this->putEnv('APP_DEBUG', '0');
        // Keep Symfony field validation enabled; relationship integrity is the
        // PRE_SAVE listener under test, not the EntityValidator opt-out path.
        $this->clearEnv('WAASEYAA_ENTITY_VALIDATION');
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        ContentEntityBase::setFieldRegistry(null);
        EntityReadRuntime::installFieldRegistry(null);
        EntityReadRuntime::installGuard(null);
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function production_kernel_boot_registers_the_listener_exactly_once_without_manual_wiring(): void
    {
        $kernel = $this->bootKernel();
        $dispatcher = $this->dispatcher($kernel);

        $listeners = $this->preSaveRelationshipListeners($dispatcher);
        self::assertCount(
            1,
            $listeners,
            'RelationshipPreSaveListener must be registered exactly once by RelationshipServiceProvider::boot()',
        );

        $provider = $this->relationshipProvider($kernel);
        $provider->boot();
        self::assertCount(
            1,
            $this->preSaveRelationshipListeners($dispatcher),
            'Repeated provider boot on the same dispatcher must not duplicate the listener',
        );
    }

    #[Test]
    public function repeated_kernel_boots_in_one_process_each_register_a_single_listener(): void
    {
        $first = $this->bootKernel();
        self::assertCount(1, $this->preSaveRelationshipListeners($this->dispatcher($first)));

        ProcessFieldReadRuntime::reset();
        $second = $this->bootKernel();
        self::assertCount(1, $this->preSaveRelationshipListeners($this->dispatcher($second)));
        self::assertNotSame($this->dispatcher($first), $this->dispatcher($second));
    }

    #[Test]
    public function frankenphp_worker_shaped_fresh_http_kernels_each_register_once(): void
    {
        // public/index.php builds a fresh HttpKernel per FrankenPHP worker
        // request. Two sequential boots in one process must each expose exactly
        // one RelationshipPreSaveListener on their own dispatcher.
        $this->writeConfig();
        $this->putEnv('WAASEYAA_APP_SECRET', 'base64:' . base64_encode(random_bytes(32)));
        $this->installRuntimeSchema();

        $first = new HttpKernel($this->projectRoot);
        $first->bootForCli();
        self::assertCount(1, $this->preSaveRelationshipListeners($this->dispatcher($first)));

        ProcessFieldReadRuntime::reset();
        $second = new HttpKernel($this->projectRoot);
        $second->bootForCli();
        self::assertCount(1, $this->preSaveRelationshipListeners($this->dispatcher($second)));
        self::assertNotSame($this->dispatcher($first), $this->dispatcher($second));
    }

    #[Test]
    public function invalid_relationship_writes_fail_before_persistence_and_post_save(): void
    {
        $kernel = $this->bootKernel();
        $manager = $kernel->getEntityTypeManager();
        $endpoints = $this->seedEndpoints($kernel);
        $repository = $manager->getRepository('relationship');
        $dispatcher = $this->dispatcher($kernel);

        $cases = [
            'missing_source' => [
                'from_entity_type' => 'user',
                'from_entity_id' => '999999',
                'to_entity_type' => 'user',
                'to_entity_id' => $endpoints['userB'],
                'relationship_type' => 'references',
                'expected' => 'from_entity_id',
            ],
            'missing_target' => [
                'from_entity_type' => 'user',
                'from_entity_id' => $endpoints['userA'],
                'to_entity_type' => 'user',
                'to_entity_id' => '999999',
                'relationship_type' => 'references',
                'expected' => 'to_entity_id',
            ],
            'invalid_relationship_type' => [
                'from_entity_type' => 'user',
                'from_entity_id' => $endpoints['userA'],
                'to_entity_type' => 'user',
                'to_entity_id' => $endpoints['userB'],
                'relationship_type' => 'Bad-Type',
                'expected' => 'relationship_type',
            ],
            'unknown_endpoint_type' => [
                'from_entity_type' => 'not_a_registered_type',
                'from_entity_id' => '1',
                'to_entity_type' => 'user',
                'to_entity_id' => $endpoints['userB'],
                'relationship_type' => 'references',
                'expected' => 'from_entity_type',
            ],
        ];

        foreach ($cases as $label => $case) {
            $postSaveFired = false;
            $postSave = static function () use (&$postSaveFired): void {
                $postSaveFired = true;
            };
            $dispatcher->addListener(EntityEvents::POST_SAVE->value, $postSave);

            $entity = $repository->create([
                'relationship_type' => $case['relationship_type'],
                'from_entity_type' => $case['from_entity_type'],
                'from_entity_id' => $case['from_entity_id'],
                'to_entity_type' => $case['to_entity_type'],
                'to_entity_id' => $case['to_entity_id'],
                'directionality' => 'directed',
                'status' => 1,
            ]);
            assert($entity instanceof Relationship);

            $thrown = null;
            try {
                $repository->save($entity);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            } finally {
                $dispatcher->removeListener(EntityEvents::POST_SAVE->value, $postSave);
            }

            self::assertInstanceOf(\InvalidArgumentException::class, $thrown, $label);
            self::assertStringContainsString('Relationship validation failed', $thrown->getMessage(), $label);
            self::assertStringContainsString($case['expected'], $thrown->getMessage(), $label);
            self::assertFalse($postSaveFired, $label . ': POST_SAVE must not fire for a rejected write');
            self::assertSame(
                0,
                $this->relationshipRowCount($kernel),
                $label . ': rejected relationship must not be persisted',
            );
        }
    }

    #[Test]
    public function valid_relationship_persists_once_with_exactly_one_pre_and_post_save(): void
    {
        $kernel = $this->bootKernel();
        $endpoints = $this->seedEndpoints($kernel);
        $repository = $kernel->getEntityTypeManager()->getRepository('relationship');
        $dispatcher = $this->dispatcher($kernel);

        $preCount = 0;
        $postCount = 0;
        $relationshipPreCount = 0;
        $pre = static function (object $event) use (&$preCount, &$relationshipPreCount): void {
            if (!$event instanceof EntityEvent) {
                return;
            }
            ++$preCount;
            if ($event->entity->getEntityTypeId() === 'relationship') {
                ++$relationshipPreCount;
            }
        };
        $post = static function (object $event) use (&$postCount): void {
            if ($event instanceof EntityEvent && $event->entity->getEntityTypeId() === 'relationship') {
                ++$postCount;
            }
        };
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, $pre);
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, $post);

        $entity = $repository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'user',
            'from_entity_id' => $endpoints['userA'],
            'to_entity_type' => 'user',
            'to_entity_id' => $endpoints['userB'],
            'directionality' => 'directed',
            'status' => 1,
        ]);
        assert($entity instanceof Relationship);
        $result = $repository->save($entity);

        $dispatcher->removeListener(EntityEvents::PRE_SAVE->value, $pre);
        $dispatcher->removeListener(EntityEvents::POST_SAVE->value, $post);

        self::assertSame(EntityConstants::SAVED_NEW, $result);
        self::assertSame(1, $this->relationshipRowCount($kernel));
        self::assertSame(1, $relationshipPreCount);
        self::assertSame(1, $postCount);
        self::assertGreaterThanOrEqual(1, $preCount);
        self::assertNotNull($repository->find((string) $entity->id()));
    }

    #[Test]
    public function validate_false_skips_entity_validator_but_listener_still_rejects_invalid_edges(): void
    {
        $kernel = $this->bootKernel();
        $endpoints = $this->seedEndpoints($kernel);
        $repository = $kernel->getEntityTypeManager()->getRepository('relationship');
        $dispatcher = $this->dispatcher($kernel);

        $postSaveFired = false;
        $postSave = static function () use (&$postSaveFired): void {
            $postSaveFired = true;
        };
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, $postSave);

        $entity = $repository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'user',
            'from_entity_id' => '999999',
            'to_entity_type' => 'user',
            'to_entity_id' => $endpoints['userB'],
            'directionality' => 'directed',
            'status' => 1,
        ]);
        assert($entity instanceof Relationship);

        $thrown = null;
        try {
            // EntityValidator opt-out is intentional for imports/bootstrap, but
            // RelationshipPreSaveListener is a separate PRE_SAVE integrity gate
            // and must still fire (EntityRepository always dispatches PRE_SAVE).
            $repository->save($entity, validate: false);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        } finally {
            $dispatcher->removeListener(EntityEvents::POST_SAVE->value, $postSave);
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $thrown);
        self::assertStringContainsString('from_entity_id', $thrown->getMessage());
        self::assertFalse($postSaveFired);
        self::assertSame(0, $this->relationshipRowCount($kernel));
    }

    #[Test]
    public function validate_false_still_persists_a_valid_membership_edge(): void
    {
        $kernel = $this->bootKernel();
        $endpoints = $this->seedEndpoints($kernel);
        $repository = $kernel->getEntityTypeManager()->getRepository('relationship');

        $entity = $repository->create([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => $endpoints['userA'],
            'to_entity_type' => 'group',
            'to_entity_id' => $endpoints['group'],
            'directionality' => 'directed',
            'status' => 1,
        ]);
        assert($entity instanceof Relationship);

        $result = $repository->save($entity, validate: false);

        self::assertSame(EntityConstants::SAVED_NEW, $result);
        self::assertSame(1, $this->relationshipRowCount($kernel));
    }

    #[Test]
    public function group_membership_service_writes_remain_valid_under_wired_listener(): void
    {
        $kernel = $this->bootKernel();
        $endpoints = $this->seedEndpoints($kernel);
        $service = new GroupMembershipService($kernel->getEntityTypeManager());

        $service->addMember($endpoints['userA'], $endpoints['group']);

        self::assertSame(1, $this->relationshipRowCount($kernel));
        self::assertSame([$endpoints['group']], $service->groupIdsForUser($endpoints['userA']));
    }

    #[Test]
    public function negative_control_unwired_provider_allows_invalid_persistence(): void
    {
        $kernel = $this->bootKernel();
        $dispatcher = $this->dispatcher($kernel);
        foreach ($this->preSaveRelationshipListeners($dispatcher) as $listener) {
            $dispatcher->removeListener(EntityEvents::PRE_SAVE->value, $listener);
        }
        self::assertSame(
            [],
            $this->preSaveRelationshipListeners($dispatcher),
            'negative control requires the production listener to be removed',
        );

        $endpoints = $this->seedEndpoints($kernel);
        $repository = $kernel->getEntityTypeManager()->getRepository('relationship');
        $postSaveFired = false;
        $postSave = static function () use (&$postSaveFired): void {
            $postSaveFired = true;
        };
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, $postSave);

        $entity = $repository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'user',
            'from_entity_id' => '999999',
            'to_entity_type' => 'user',
            'to_entity_id' => $endpoints['userB'],
            'directionality' => 'directed',
            'status' => 1,
        ]);
        assert($entity instanceof Relationship);
        $repository->save($entity, validate: false);
        $dispatcher->removeListener(EntityEvents::POST_SAVE->value, $postSave);

        self::assertTrue($postSaveFired, 'pre-fix unwired path must reach POST_SAVE');
        self::assertSame(1, $this->relationshipRowCount($kernel), 'pre-fix unwired path must persist invalid edges');
    }

    private function bootKernel(): AbstractKernel
    {
        $this->writeConfig();
        $this->putEnv('WAASEYAA_APP_SECRET', 'base64:' . base64_encode(random_bytes(32)));
        $this->installRuntimeSchema();

        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();

        return $kernel;
    }

    private function dispatcher(AbstractKernel $kernel): EventDispatcherInterface
    {
        $property = new \ReflectionProperty(AbstractKernel::class, 'dispatcher');

        $dispatcher = $property->getValue($kernel);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @return list<RelationshipPreSaveListener>
     */
    private function preSaveRelationshipListeners(EventDispatcherInterface $dispatcher): array
    {
        $matched = [];
        foreach ($dispatcher->getListeners(EntityEvents::PRE_SAVE->value) as $listener) {
            if ($listener instanceof RelationshipPreSaveListener) {
                $matched[] = $listener;
            }
        }

        return $matched;
    }

    private function relationshipProvider(AbstractKernel $kernel): RelationshipServiceProvider
    {
        foreach ($kernel->getProviders() as $provider) {
            if ($provider instanceof RelationshipServiceProvider) {
                return $provider;
            }
        }

        self::fail('RelationshipServiceProvider was not discovered on the production boot path');
    }

    /**
     * @return array{userA: string, userB: string, group: string}
     */
    private function seedEndpoints(AbstractKernel $kernel): array
    {
        $manager = $kernel->getEntityTypeManager();

        $userRepository = $manager->getRepository('user');
        $userA = new User(['name' => 'rel-a', 'mail' => 'a@example.test', 'status' => true]);
        $userA->enforceIsNew();
        $userRepository->save($userA, validate: false);
        $userB = new User(['name' => 'rel-b', 'mail' => 'b@example.test', 'status' => true]);
        $userB->enforceIsNew();
        $userRepository->save($userB, validate: false);

        $groupTypeRepository = $manager->getRepository('group_type');
        $groupType = new GroupType(['id' => 'department', 'label' => 'Department']);
        $groupType->enforceIsNew();
        $groupTypeRepository->save($groupType, validate: false);

        $groupRepository = $manager->getRepository('group');
        $group = new Group(['type' => 'department', 'name' => 'Dept A']);
        $group->enforceIsNew();
        $groupRepository->save($group, validate: false);

        return [
            'userA' => (string) $userA->id(),
            'userB' => (string) $userB->id(),
            'group' => (string) $group->id(),
        ];
    }

    private function relationshipRowCount(AbstractKernel $kernel): int
    {
        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);

        return (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM relationship');
    }

    private function installRuntimeSchema(): void
    {
        $database = DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        RuntimeSchemaMigrations::auth($database);
        RuntimeSchemaMigrations::audit($database);
        RuntimeSchemaMigrations::broadcast($database);
        RuntimeSchemaMigrations::cache($database);
        RuntimeSchemaMigrations::oidc($database);
        $database->getConnection()->close();
        RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);
    }

    private function writeConfig(string $environment = 'testing'): void
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $this->putEnv('WAASEYAA_DB', $databasePath);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<PHP
            <?php
            declare(strict_types=1);
            return [
                'database' => {$this->export($databasePath)},
                'environment' => {$this->export($environment)},
                'debug' => false,
                'app' => ['url' => 'http://localhost', 'name' => '1958'],
            ];
            PHP);
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    private function putEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name]);
    }
}
