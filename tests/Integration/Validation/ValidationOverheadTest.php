<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Diagnostic for kernel-wired save-time validation.
 *
 * A ~20-field entity type is saved 200 times with `validate: true` and 200
 * times with `validate: false` (fresh rows each, same value shape) through a
 * kernel-built repository. The ratio remains useful diagnostic evidence, but
 * #2064 replaced synthetic microbenchmark ratio gates with cache-cold full-page
 * budgets, so this test asserts only that both measured paths produce a finite,
 * positive sample.
 */
#[CoversNothing]
final class ValidationOverheadTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'kvw_perf_subject';
    private const int SAVES_PER_MODE = 200;
    private const int WARMUP_SAVES = 20;

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);
    }

    #[Test]
    public function medianValidatedSaveRatioRemainsAWellFormedDiagnostic(): void
    {
        $ratio = $this->measureMedianRatio();

        self::assertGreaterThan(0.0, $ratio);
        self::assertTrue(is_finite($ratio));
    }

    /**
     * Boot a fresh kernel, run both timed loops, return median ratio
     * (validated / unvalidated).
     */
    private function measureMedianRatio(): float
    {
        $repository = $this->registerPerfType($this->bootKernel());

        // Warm both paths (schema creation, constraint-map first assembly,
        // SQLite page cache) before timing anything.
        for ($i = 0; $i < self::WARMUP_SAVES; $i++) {
            $repository->save($this->newEntity(), validate: ($i % 2) === 0);
        }

        $validated = $this->timeSaves($repository, validate: true);
        $unvalidated = $this->timeSaves($repository, validate: false);

        return self::median($validated) / self::median($unvalidated);
    }

    /**
     * @return list<float> Per-save durations in nanoseconds.
     */
    private function timeSaves(EntityRepositoryInterface $repository, bool $validate): array
    {
        $durations = [];
        for ($i = 0; $i < self::SAVES_PER_MODE; $i++) {
            $entity = $this->newEntity();
            $start = hrtime(true);
            $repository->save($entity, validate: $validate);
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

    /** Fresh valid entity, all 20 fields populated (same shape every save). */
    private function newEntity(): KernelValidationPerfSubject
    {
        $values = [];
        for ($i = 1; $i <= 10; $i++) {
            $values['text_' . $i] = 'value ' . $i;
            $values['count_' . $i] = $i * 7;
        }

        return new KernelValidationPerfSubject($values);
    }

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

            public function publicFieldRegistry(): FieldDefinitionRegistry
            {
                \assert($this->fieldRegistry instanceof FieldDefinitionRegistry);
                return $this->fieldRegistry;
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
     * Register a 20-field entity type (10 required strings with max_length,
     * 10 integers with min/max ranges) so the validated path assembles and
     * evaluates a realistic constraint map on every save.
     */
    private function registerPerfType(object $kernel): EntityRepositoryInterface
    {
        $type = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Validation overhead perf subject',
            class: KernelValidationPerfSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'text_1'],
        );
        // Type first: registerEntityType() re-seeds the registry from the
        // type's own (empty) definitions.
        $kernel->publicEntityTypeManager()->registerEntityType($type, registrant: self::class);

        $fields = [];
        for ($i = 1; $i <= 10; $i++) {
            $fields['text_' . $i] = new FieldDefinition(
                name: 'text_' . $i,
                type: 'string',
                settings: ['max_length' => 255],
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                required: true,
            );
            $fields['count_' . $i] = new FieldDefinition(
                name: 'count_' . $i,
                type: 'integer',
                settings: ['min' => 0, 'max' => 10_000],
                targetEntityTypeId: self::ENTITY_TYPE_ID,
            );
        }
        $kernel->publicFieldRegistry()->registerCoreFields(self::ENTITY_TYPE_ID, $fields);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entities(
            $kernel->publicDatabase(),
            $kernel->publicEntityTypeManager(),
            [$type],
        );

        return $kernel->publicEntityTypeManager()->getRepository(self::ENTITY_TYPE_ID);
    }
}

#[ContentEntityType(id: 'kvw_perf_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'text_1')]
final class KernelValidationPerfSubject extends ContentEntityBase
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
