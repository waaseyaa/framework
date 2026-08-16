<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Mission live-entity-validation-key-protection (#1643) WP02 — booted-kernel
 * enforcement of save-time entity validation.
 *
 * The seam under test is AbstractKernel::bootEntityTypeManager(): every
 * repository the kernel's factory closure constructs must carry the shared
 * EntityValidator::createDefault() instance, gated by the boot-time env
 * switch WAASEYAA_ENTITY_VALIDATION. Each case below pins one clause of
 * `contracts/validation-error.md`:
 *
 *   1. Pre-persistence rejection — the exception fires BEFORE any storage
 *      write; the row is proven absent by querying SQLite directly.
 *   2/3. Completeness + addressability — ALL violations across ALL fields are
 *      present, each propertyPath prefixed with its field name. The fixture
 *      type exercises all three constraint layers at once: derived required
 *      (NotBlank on `name`), derived Range from `['min','max']` settings
 *      (`score`), and a per-field declared constraint (GreaterThan on
 *      `rating`).
 *   —  Valid save unchanged — returns SAVED_NEW, row persisted.
 *   6. Per-save opt-out — `save($entity, validate: false)` preserves
 *      pre-mission behavior for invalid data.
 *   5. saveMany rollback — a validation failure mid-batch aborts the
 *      UnitOfWork transaction; the earlier valid entity is rolled back.
 *   6. Env opt-out — WAASEYAA_ENTITY_VALIDATION=0 read at boot disables the
 *      wiring for a kernel booted AFTER the env var is set.
 *
 * The anonymous AbstractKernel subclass mirrors the Mission #1257 §C1
 * bootstrap (tests/Integration/Phase26/Mission1257KernelPathTest.php): it
 * exposes only the boot steps under test so the production wiring decision in
 * bootEntityTypeManager() stays the subject.
 */
#[CoversNothing]
final class KernelValidationWiringTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'kvw_subject';

    /** Prior value of the env switch, restored in tearDown even on failure. */
    private string|false $priorEnv = false;

    protected function setUp(): void
    {
        $this->priorEnv = getenv('WAASEYAA_ENTITY_VALIDATION');
    }

    protected function tearDown(): void
    {
        // Restore the env switch even when a test failed mid-way (boot-time
        // decision — leaking `0` would silently disable validation for every
        // kernel booted by later tests in the process).
        if ($this->priorEnv === false) {
            putenv('WAASEYAA_ENTITY_VALIDATION');
        } else {
            putenv('WAASEYAA_ENTITY_VALIDATION=' . $this->priorEnv);
        }

        // Clear the FieldDefinitionRegistry singleton the kernel boot installs
        // on ContentEntityBase so the fixture registry does not leak across
        // tests through the class-level static.
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);
    }

    // ------------------------------------------------------------------
    // Contract clause 1 — pre-persistence rejection
    // ------------------------------------------------------------------

    #[Test]
    public function invalidSaveIsRejectedBeforeAnyStorageWrite(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $entity = new KernelValidationWiringSubject([
            'name' => 'Out of range',
            'score' => 150,
        ]);

        $thrown = null;
        try {
            $repository->save($entity);
        } catch (EntityValidationException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(EntityValidationException::class, $thrown);
        self::assertGreaterThan(0, $thrown->violations->count());

        // Contract clause 1: do not trust the exception — prove the base table
        // is untouched by querying storage directly.
        self::assertSame(
            0,
            $this->rowCount($kernel),
            'EntityValidationException must fire BEFORE any storage write; the rejected row must not exist.',
        );
    }

    // ------------------------------------------------------------------
    // Contract clauses 2 + 3 — completeness and addressability
    // ------------------------------------------------------------------

    #[Test]
    public function violationListIsCompleteAcrossAllFieldsAndFieldPrefixed(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        // Three violations across three fields, one per constraint layer:
        // `name` missing (derived NotBlank), `score` out of range (derived
        // Range from settings), `rating` 0 (declared GreaterThan(0)).
        $entity = new KernelValidationWiringSubject([
            'score' => 150,
            'rating' => 0,
        ]);

        $thrown = null;
        try {
            $repository->save($entity);
        } catch (EntityValidationException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(EntityValidationException::class, $thrown);

        $paths = [];
        foreach ($thrown->violations as $violation) {
            $paths[] = $violation->getPropertyPath();
            self::assertNotSame('', $violation->getMessage());
        }

        self::assertContains('name', $paths, 'Derived NotBlank violation must be addressable by field name.');
        self::assertContains('score', $paths, 'Derived Range violation must be addressable by field name.');
        self::assertContains('rating', $paths, 'Declared GreaterThan violation must be addressable by field name.');
        self::assertCount(3, $paths, 'The violation list must contain ALL violations, not first-failure.');

        self::assertSame(0, $this->rowCount($kernel));
    }

    // ------------------------------------------------------------------
    // Valid save unchanged
    // ------------------------------------------------------------------

    #[Test]
    public function validEntitySavesAndReturnsSavedNew(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $entity = new KernelValidationWiringSubject([
            'name' => 'Well formed',
            'score' => 42,
            'rating' => 5,
        ]);

        $result = $repository->save($entity);

        self::assertSame(EntityConstants::SAVED_NEW, $result);
        self::assertSame(1, $this->rowCount($kernel));
    }

    // ------------------------------------------------------------------
    // Contract clause 6 — per-save opt-out
    // ------------------------------------------------------------------

    #[Test]
    public function perSaveOptOutPersistsInvalidEntity(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $entity = new KernelValidationWiringSubject([
            'score' => 150,
        ]);

        // Pre-mission behavior preserved: the surgical escape hatch skips
        // validation entirely for this one write.
        $result = $repository->save($entity, validate: false);

        self::assertSame(EntityConstants::SAVED_NEW, $result);
        self::assertSame(1, $this->rowCount($kernel));
    }

    // ------------------------------------------------------------------
    // Contract clause 5 — saveMany rollback
    // ------------------------------------------------------------------

    #[Test]
    public function saveManyRollsBackEarlierEntitiesWhenALaterOneFailsValidation(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $valid = new KernelValidationWiringSubject([
            'name' => 'Valid sibling',
            'score' => 10,
        ]);
        $invalid = new KernelValidationWiringSubject([
            'name' => 'Invalid sibling',
            'score' => 150,
        ]);

        $thrown = null;
        try {
            $repository->saveMany([$valid, $invalid]);
        } catch (EntityValidationException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(EntityValidationException::class, $thrown);

        // Contract clause 5: the UnitOfWork transaction aborts — the valid
        // entity saved earlier in the same batch must be rolled back. Query
        // storage directly; NEITHER row may exist.
        self::assertSame(
            0,
            $this->rowCount($kernel),
            'saveMany() validation failure must roll back every entity in the batch.',
        );
    }

    // ------------------------------------------------------------------
    // Contract clause 6 — env opt-out (boot-time decision)
    // ------------------------------------------------------------------

    #[Test]
    public function envOptOutDisablesValidationForKernelsBootedAfterItIsSet(): void
    {
        // The wiring decision is made ONCE at boot: set the env var FIRST,
        // boot AFTER.
        putenv('WAASEYAA_ENTITY_VALIDATION=0');

        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $entity = new KernelValidationWiringSubject([
            'score' => 150,
        ]);

        // No EntityValidationException: behavior identical to pre-mission.
        $result = $repository->save($entity);

        self::assertSame(EntityConstants::SAVED_NEW, $result);
        self::assertSame(1, $this->rowCount($kernel));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build and boot an anonymous {@see AbstractKernel} subclass exposing only
     * the boot steps WP02 wires (database + entity-type manager). Anonymous so
     * the architectural rule `NoKernelSubclassesInTestsTest` holds; the kernel
     * bootstrap exemption (kernels import from all layers) applies.
     */
    private function bootKernel(): object
    {
        $kernel = new class (sys_get_temp_dir(), new NullLogger()) extends AbstractKernel {
            public function __construct(string $projectRoot, LoggerInterface $logger)
            {
                parent::__construct($projectRoot, $logger);
                // DatabaseBootstrapper reads `config.database` as a path string.
                $this->config = ['database' => ':memory:', 'environment' => 'testing'];
                // boot() seeds the dispatcher before bootDatabase() /
                // bootEntityTypeManager() consume it; reproduce the bare
                // minimum (mirrors Mission1257KernelPathTest).
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

            public function publicFieldRegistry(): FieldDefinitionRegistry
            {
                \assert($this->fieldRegistry instanceof FieldDefinitionRegistry);
                return $this->fieldRegistry;
            }
        };

        $kernel->publicBoot();

        return $kernel;
    }

    /**
     * Register the throwaway entity type on a booted kernel and resolve its
     * repository through the production factory closure (the seam under test).
     *
     * Field layers: `name` required string (derived NotBlank + Type),
     * `score` integer with `['min' => 0, 'max' => 100]` (derived Range),
     * `rating` integer with a declared GreaterThan(0) (per-field constraint
     * merge, research D5).
     */
    private function registerSubjectType(object $kernel): EntityRepositoryInterface
    {
        $type = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Kernel validation wiring subject',
            class: KernelValidationWiringSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        // Register the type FIRST: registerEntityType() re-seeds the registry
        // from the type's own (empty) field definitions, so core fields
        // registered earlier would be silently dropped.
        $kernel->publicEntityTypeManager()->registerEntityType($type, registrant: self::class);

        $kernel->publicFieldRegistry()->registerCoreFields(self::ENTITY_TYPE_ID, [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                label: 'Name',
                required: true,
            ),
            'score' => new FieldDefinition(
                name: 'score',
                type: 'integer',
                settings: ['min' => 0, 'max' => 100],
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                label: 'Score',
            ),
            'rating' => new FieldDefinition(
                name: 'rating',
                type: 'integer',
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                label: 'Rating',
                constraints: [new GreaterThan(0)],
            ),
        ]);

        return $kernel->publicEntityTypeManager()->getRepository(self::ENTITY_TYPE_ID);
    }

    /** Count base-table rows by querying SQLite directly (no repository read path). */
    private function rowCount(object $kernel): int
    {
        return (int) $kernel->publicDatabase()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "' . self::ENTITY_TYPE_ID . '"',
        );
    }
}

#[ContentEntityType(id: 'kvw_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class KernelValidationWiringSubject extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
