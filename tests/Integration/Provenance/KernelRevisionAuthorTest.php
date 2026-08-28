<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Provenance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Tests\Support\StatementCountingDatabase;

/**
 * Mission revision-audit-provenance-01KTWY5V WP02 (T010, SC-001 + NFR-001) —
 * kernel-booted revision-author readback.
 *
 * The seam under test is the WP01 forward seam in
 * AbstractKernel::bootEntityTypeManager(): the repository factory calls
 * `setAccountContext()` behind a method_exists guard, which went live the
 * moment WP02 added the receiver on EntityRepository. A save through a
 * kernel-built repository with an account in the kernel's accountContext()
 * must read the author back via `revisionMetadata()` — proving the
 * middleware-set context reaches storage with zero per-callsite threading
 * (FR-002).
 *
 * The kernel bootstrap mirrors tests/Integration/Validation/
 * KernelValidationWiringTest.php (anonymous AbstractKernel subclass exposing
 * only the boot steps under test).
 */
#[CoversNothing]
final class KernelRevisionAuthorTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'prov_author_subject';

    /**
     * NFR-001, re-expressed as work rather than latency (#2542).
     *
     * The requirement is "attribution must not add work per save". That was
     * originally asserted as a 1.05x median wall-clock ratio, which measured
     * the runner as much as the code: it flaked at 1.0546x on an otherwise
     * green tree during unrelated work, red-lighting a PR that had never
     * touched this surface. A 5% margin is below the noise floor of a shared CI
     * runner, so the assertion could not distinguish the regression it guards
     * from the machine it ran on.
     *
     * What attribution actually does is read one in-memory holder and call
     * `id()`. So the invariant is exact and contention-insensitive: an
     * attributed save issues the SAME database statements as an unattributed
     * one, and consults the account exactly once. The regression this guards —
     * resolving the actor by loading a user entity, or re-reading the
     * context per field — moves both numbers immediately and on any machine.
     *
     * Deliberately NOT a looser wall-clock bound: a bound wide enough to
     * survive runner contention is wide enough to admit a per-save query, which
     * is the regression itself.
     */
    private const int SAVES_PER_MODE = 40;

    private const int WARMUP_SAVES = 4;

    /**
     * Account reads permitted per attributed save.
     *
     * One `id()` for the revision author is the shipped cost
     * (`EntityRepository::resolveActor()`). The ceiling is that same 1: a
     * second read is exactly double resolution, or per-field `id()` on this
     * one-field fixture, and must fail.
     */
    private const int MAX_ACCOUNT_READS_PER_SAVE = 1;

    protected function tearDown(): void
    {
        // Clear the FieldDefinitionRegistry singleton the kernel boot installs
        // on ContentEntityBase so it does not leak across tests.
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);
    }

    // ------------------------------------------------------------------
    // SC-001 — the WP01 seam is live: kernel-context save records author
    // ------------------------------------------------------------------

    #[Test]
    public function save_through_a_kernel_built_repository_records_the_context_account_as_author(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);

        $kernel->accountContext()->set(new ProvenanceStubAccount(7));

        $entity = new ProvenanceAuthorSubject(['title' => 'Attributed save']);
        $repository->save($entity);
        $id = (string) $entity->id();
        self::assertNotSame('', $id);

        $revision = $repository->loadRevision($id, 1);
        self::assertInstanceOf(RevisionableEntityInterface::class, $revision);
        $metadata = $revision->revisionMetadata();
        self::assertNotNull($metadata, 'Kernel-built repository must hydrate RevisionMetadata on loadRevision().');
        self::assertSame(
            7,
            $metadata->revisionAuthor,
            'The kernel accountContext() account must reach the revision row through the WP01 seam.',
        );
    }

    #[Test]
    public function kernel_save_with_no_account_in_context_records_null_author(): void
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);
        // accountContext() exists (the kernel always constructs one) but holds
        // no account — the CLI/bootstrap state.

        $entity = new ProvenanceAuthorSubject(['title' => 'Unattributed save']);
        $repository->save($entity);

        $metadata = $repository->loadRevision((string) $entity->id(), 1)->revisionMetadata();
        self::assertNotNull($metadata);
        self::assertNull($metadata->revisionAuthor);
    }

    // ------------------------------------------------------------------
    // NFR-001 — attribution adds no per-save database work (#2542)
    // ------------------------------------------------------------------

    #[Test]
    public function an_attributed_save_issues_the_same_database_statements_as_an_unattributed_one(): void
    {
        $storage = DBALDatabase::createSqlite();
        $counter = new StatementCountingDatabase($storage);
        $kernel = $this->bootKernel($counter);
        $repository = $this->registerSubjectType($kernel, schemaDatabase: $storage);
        $account = new ProvenanceCountingAccount(7);

        // Warm both paths first: schema creation and the SQLite page cache are
        // setup work, and counting them would drown the signal.
        for ($i = 0; $i < self::WARMUP_SAVES; $i++) {
            $kernel->accountContext()->set(($i % 2) === 0 ? $account : null);
            $repository->save(new ProvenanceAuthorSubject(['title' => 'warmup ' . $i]));
        }

        $kernel->accountContext()->set($account);
        $account->resetReads();
        $counter->reset();
        for ($i = 0; $i < self::SAVES_PER_MODE; $i++) {
            $repository->save(new ProvenanceAuthorSubject(['title' => 'attributed ' . $i]));
        }
        $attributed = $counter->counts();
        $accountReads = $account->reads();

        $kernel->accountContext()->set(null);
        $counter->reset();
        for ($i = 0; $i < self::SAVES_PER_MODE; $i++) {
            $repository->save(new ProvenanceAuthorSubject(['title' => 'unattributed ' . $i]));
        }
        $unattributed = $counter->counts();

        self::assertSame(
            $unattributed,
            $attributed,
            sprintf(
                'NFR-001: attribution changed the per-save statement mix over %d saves. '
                . "With an account: %s. Without: %s.\n"
                . 'Actor resolution is an in-memory holder read; anything that reaches storage '
                . 'per save is the regression this guards.',
                self::SAVES_PER_MODE,
                json_encode($attributed, JSON_THROW_ON_ERROR),
                json_encode($unattributed, JSON_THROW_ON_ERROR),
            ),
        );
        self::assertGreaterThan(0, array_sum($unattributed), 'The measurement must have observed real saves.');

        self::assertLessThanOrEqual(
            self::SAVES_PER_MODE * self::MAX_ACCOUNT_READS_PER_SAVE,
            $accountReads,
            sprintf(
                'NFR-001: %d account reads across %d attributed saves exceeds %d per save. '
                . 'Actor resolution must not scale with the entity.',
                $accountReads,
                self::SAVES_PER_MODE,
                self::MAX_ACCOUNT_READS_PER_SAVE,
            ),
        );
        self::assertGreaterThanOrEqual(
            self::SAVES_PER_MODE,
            $accountReads,
            'The account must actually be consulted — otherwise an equal statement count '
            . 'would only prove attribution is switched off, which SC-001 above forbids.',
        );
    }

    /**
     * @param ?DatabaseInterface $database Installed in place of the bootstrapper's
     *        own connection, so a decorator can observe what the kernel-built
     *        repository asks of storage (#2542).
     */
    private function bootKernel(?DatabaseInterface $database = null): object
    {
        $kernel = new class (sys_get_temp_dir(), new NullLogger(), $database) extends AbstractKernel {
            public function __construct(string $projectRoot, LoggerInterface $logger, private readonly ?DatabaseInterface $suppliedDatabase = null)
            {
                parent::__construct($projectRoot, $logger);
                // DatabaseBootstrapper reads `config.database` as a path string.
                $this->config = ['database' => ':memory:', 'environment' => 'testing'];
                // boot() seeds the dispatcher before bootDatabase() /
                // bootEntityTypeManager() consume it.
                $this->dispatcher = new SymfonyEventDispatcherAdapter();
            }

            public function publicBoot(): void
            {
                if ($this->suppliedDatabase !== null) {
                    // Must be in place BEFORE bootEntityTypeManager(), which
                    // captures the database by value into its repository factory.
                    $this->database = $this->suppliedDatabase;
                } else {
                    $this->bootDatabase();
                }
                $this->bootEntityTypeManager();
            }

            public function publicEntityTypeManager(): EntityTypeManager
            {
                return $this->entityTypeManager;
            }

            public function publicDatabase(): DatabaseInterface
            {
                return $this->database;
            }
        };

        $kernel->publicBoot();

        return $kernel;
    }

    /**
     * Register the revisionable subject type and resolve its repository
     * through the PRODUCTION factory closure — the seam under test.
     */
    private function registerSubjectType(object $kernel, ?DatabaseInterface $schemaDatabase = null): EntityRepositoryInterface
    {
        $type = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Provenance author subject',
            class: ProvenanceAuthorSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $kernel->publicEntityTypeManager()->registerEntityType($type, registrant: self::class);
        // Schema mutation goes through the concrete DBAL-backed coordinator
        // ([S1-DB107]), so a decorated database is handed the undecorated one
        // for setup. That also keeps setup statements out of the #2542 count,
        // which is measuring saves, not table creation.
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entities(
            $schemaDatabase ?? $kernel->publicDatabase(),
            $kernel->publicEntityTypeManager(),
            [$type],
        );

        return $kernel->publicEntityTypeManager()->getRepository(self::ENTITY_TYPE_ID);
    }
}

/** Minimal account stub: id-only. */
final class ProvenanceStubAccount implements AccountInterface
{
    public function __construct(private readonly int $uid) {}

    public function id(): int|string
    {
        return $this->uid;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return $this->uid > 0;
    }
}

/**
 * A stub account that records how often it is read (#2542).
 *
 * Actor resolution is meant to be one `id()` call per save. Counting it turns
 * "attribution is cheap" into an exact assertion instead of a latency
 * measurement a loaded runner can invalidate.
 */
final class ProvenanceCountingAccount implements AccountInterface
{
    private int $reads = 0;

    public function __construct(private readonly int $uid) {}

    public function reads(): int
    {
        return $this->reads;
    }

    public function resetReads(): void
    {
        $this->reads = 0;
    }

    public function id(): int|string
    {
        $this->reads++;

        return $this->uid;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return $this->uid > 0;
    }
}

#[ContentEntityType(id: 'prov_author_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
final class ProvenanceAuthorSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
