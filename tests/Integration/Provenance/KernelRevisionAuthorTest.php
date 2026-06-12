<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Provenance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
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
     * NFR-001 target: median revisionable save with an account in context
     * ≤ 1.05x the median save without one. Actor resolution is one in-memory
     * holder read + an id() call — expected to disappear into noise; the
     * retry-once guard absorbs whole-run CI jitter (pattern from
     * ValidationOverheadTest). If this flakes on shared CI runners, loosen
     * the bound with a comment linking NFR-001 — do not delete the test.
     */
    private const float MAX_MEDIAN_RATIO = 1.05;

    private const int SAVES_PER_MODE = 200;
    private const int WARMUP_SAVES = 20;

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
    // NFR-001 — perf smoke: attribution adds ≤5% median save overhead
    // ------------------------------------------------------------------

    #[Test]
    public function median_attributed_save_stays_within_the_overhead_envelope(): void
    {
        $ratio = $this->measureMedianRatio();

        if ($ratio > self::MAX_MEDIAN_RATIO) {
            // Retry-once jitter guard: fresh kernel, fresh DB, fresh run.
            $ratio = $this->measureMedianRatio();
        }

        self::assertLessThanOrEqual(
            self::MAX_MEDIAN_RATIO,
            $ratio,
            sprintf(
                'NFR-001: median save with an account in context took %.3fx the median without (bound %.2fx).',
                $ratio,
                self::MAX_MEDIAN_RATIO,
            ),
        );
    }

    private function measureMedianRatio(): float
    {
        $kernel = $this->bootKernel();
        $repository = $this->registerSubjectType($kernel);
        $account = new ProvenanceStubAccount(7);

        // Warm both paths (schema creation, SQLite page cache).
        for ($i = 0; $i < self::WARMUP_SAVES; $i++) {
            $kernel->accountContext()->set(($i % 2) === 0 ? $account : null);
            $repository->save(new ProvenanceAuthorSubject(['title' => 'warmup ' . $i]));
        }

        $kernel->accountContext()->set($account);
        $withAccount = $this->timeSaves($repository);

        $kernel->accountContext()->set(null);
        $withoutAccount = $this->timeSaves($repository);

        return self::median($withAccount) / self::median($withoutAccount);
    }

    /**
     * @return list<float> Per-save durations in nanoseconds.
     */
    private function timeSaves(EntityRepositoryInterface $repository): array
    {
        $durations = [];
        for ($i = 0; $i < self::SAVES_PER_MODE; $i++) {
            $entity = new ProvenanceAuthorSubject(['title' => 'timed ' . $i]);
            $start = hrtime(true);
            $repository->save($entity);
            $durations[] = (float) (hrtime(true) - $start);
        }

        return $durations;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return ($count % 2 === 1)
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    // ------------------------------------------------------------------
    // Helpers (bootstrap mirrors KernelValidationWiringTest)
    // ------------------------------------------------------------------

    private function bootKernel(): object
    {
        $kernel = new class (sys_get_temp_dir(), new NullLogger()) extends AbstractKernel {
            public function __construct(string $projectRoot, LoggerInterface $logger)
            {
                parent::__construct($projectRoot, $logger);
                // DatabaseBootstrapper reads `config.database` as a path string.
                $this->config = ['database' => ':memory:'];
                // boot() seeds the dispatcher before bootDatabase() /
                // bootEntityTypeManager() consume it.
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
     * Register the revisionable subject type and resolve its repository
     * through the PRODUCTION factory closure — the seam under test.
     */
    private function registerSubjectType(object $kernel): EntityRepositoryInterface
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
