<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\CLI\Handler\UserCreateHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * Regression pins for #2064's canonical boolean lifecycle.
 *
 * These use the real User type and production repository factory/validator
 * wiring. The CLI case exercises the canonical user:create handler, while the
 * round-trip case retains legacy 0/1 ingress coverage and proves that the
 * entity becomes native bool before validation, stays bool through persistence
 * and hydration, and validates again after the read.
 */
#[CoversNothing]
final class SkeletonSmokeBooleanStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear the FieldDefinitionRegistry singleton the kernel boot installs
        // on ContentEntityBase so it does not leak across tests through the
        // class-level static.
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);
    }

    #[Test]
    public function realUserCreateHandlerPersistsItsDefaultCanonicalStatus(): void
    {
        $kernel = $this->bootKernel();
        $this->registerUserType($kernel);
        $manager = $kernel->publicEntityTypeManager();

        $definition = null;
        foreach (new UserPermissionServiceProvider()->consoleCommands() as $command) {
            if ($command->name === 'user:create') {
                $definition = $command;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $id): mixed
            {
                if ($id === UserCreateHandler::class) {
                    return new UserCreateHandler($this->manager);
                }
                throw new \RuntimeException(sprintf('Unexpected service %s.', $id));
            }

            public function has(string $id): bool
            {
                return $id === UserCreateHandler::class;
            }
        };

        $tester = CliTester::for($definition, $container);
        $tester->executeMap(['username' => 'canonical-default']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertSame(1, $this->rowCount($kernel));
    }

    #[Test]
    public function statusRoundTripsThroughPersistenceReadAndValidationAsBool(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerUserType($kernel);

        $marker = 'smoke-' . bin2hex(random_bytes(4));

        // The exact value shape tools/skeleton-smoke/smoke.php saves.
        $user = User::make([
            'name' => $marker,
            'mail' => $marker . '@example.test',
            'status' => 1,
            'created' => time(),
        ]);

        self::assertSame(true, $this->protectedStatus($user));
        $repository->save($user);

        $loaded = $repository->find((string) $user->id());
        self::assertInstanceOf(User::class, $loaded);
        self::assertSame(
            true,
            $this->protectedStatus($loaded),
            'Persistence and hydration must not reintroduce an observable integer.',
        );

        // The second save drives the freshly hydrated value through the closed
        // validator again, proving the read representation is also valid input.
        $repository->save($loaded);

        self::assertSame(
            1,
            $this->rowCount($kernel),
            'Legacy 0/1 ingress must canonicalize without creating a duplicate row.',
        );
    }

    #[Test]
    public function smokeShapedUserSaveWithNonConventionStatusIsRejected(): void
    {
        // Control case: proves the repository under test really validates —
        // the green case above is not a no-op validator.
        $kernel = $this->bootKernel();
        $repository = $this->registerUserType($kernel);

        $user = User::make([
            'name' => 'smoke-invalid',
            'mail' => 'smoke-invalid@example.test',
            'status' => 'yes',
            'created' => time(),
        ]);

        $thrown = null;
        try {
            $repository->save($user);
        } catch (EntityValidationException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(EntityValidationException::class, $thrown);

        $paths = [];
        foreach ($thrown->violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertContains('status', $paths);

        self::assertSame(0, $this->rowCount($kernel));
    }

    // ------------------------------------------------------------------
    // Helpers (kernel bootstrap mirrors KernelValidationWiringTest)
    // ------------------------------------------------------------------

    /**
     * Build and boot an anonymous {@see AbstractKernel} subclass exposing only
     * the boot steps under test (database + entity-type manager), so the
     * production repository factory closure — the seam that wires the shared
     * EntityValidator — stays the subject.
     */
    private function bootKernel(): object
    {
        $kernel = new class (sys_get_temp_dir(), new NullLogger()) extends AbstractKernel {
            public function __construct(string $projectRoot, LoggerInterface $logger)
            {
                parent::__construct($projectRoot, $logger);
                $this->config = ['database' => ':memory:', 'environment' => 'testing'];
                $this->dispatcher = new SymfonyEventDispatcherAdapter();
            }

            public function publicBoot(): void
            {
                $this->bootDatabase();
                $this->bootEntityTypeManager();
            }

            public function publicEntityTypeManager(): EntityTypeManager
            {
                return $this->entityTypeManager;
            }

            public function publicDatabase(): DBALDatabase
            {
                \assert($this->database instanceof DBALDatabase);
                return $this->database;
            }
        };

        $kernel->publicBoot();

        return $kernel;
    }

    /**
     * Register the REAL user entity type — attribute-derived field definitions
     * via {@see EntityType::fromClass()}, exactly what UserServiceProvider
     * registers in a booted application — and resolve its repository through
     * the production factory closure.
     */
    private function registerUserType(object $kernel): EntityRepositoryInterface
    {
        $type = EntityType::fromClass(User::class);
        $kernel->publicEntityTypeManager()->registerEntityType(
            $type,
            registrant: self::class,
        );
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entities(
            $kernel->publicDatabase(),
            $kernel->publicEntityTypeManager(),
            [$type],
        );

        return $kernel->publicEntityTypeManager()->getRepository('user');
    }

    /** Count base-table rows by querying SQLite directly (no repository read path). */
    private function rowCount(object $kernel): int
    {
        return (int) $kernel->publicDatabase()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "user"',
        );
    }

    private function protectedStatus(User $user): mixed
    {
        $scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new UserAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...)));
        try {
            return $scope->run(
                new AuthorizationPrincipal(99, true, ['administrator'], ['administer users'], 'boolean-round-trip'),
                static fn(): mixed => $user->get('status'),
            );
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }
}
