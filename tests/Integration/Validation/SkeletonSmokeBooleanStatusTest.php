<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\User;

/**
 * Regression pin for #1655 — the skeleton-smoke save shape.
 *
 * `tools/skeleton-smoke/smoke.php` saves `User::make([... 'status' => 1 ...])`
 * against a packaged install; since validation went live in alpha.204 the
 * derived `Type('bool')` for `status` (a `boolean` field with deliberately NO
 * bool cast — User.php: "status stays 0/1 in storage and in get()/validate()")
 * rejected the framework's own 0/1 convention and the smoke died with
 * EntityValidationException.
 *
 * The smoke needs a `composer create-project` skeleton install to run, so this
 * test replicates its exact save through the same production seam the smoke
 * exercises: the real User entity type (attribute-derived field definitions
 * via EntityType::fromClass) resolved through the kernel's repository factory
 * closure, which wires the shared EntityValidator (kernel bootstrap pattern
 * mirrors KernelValidationWiringTest).
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
    public function smokeShapedUserSaveWithIntStatusPassesValidation(): void
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

        $repository->save($user);

        self::assertSame(
            1,
            $this->rowCount($kernel),
            'The smoke-shaped save (int status 1, framework 0/1 convention) must pass validation and persist.',
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
                $this->config = ['database' => ':memory:'];
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
        $kernel->publicEntityTypeManager()->registerEntityType(
            EntityType::fromClass(User::class),
            registrant: self::class,
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
}
