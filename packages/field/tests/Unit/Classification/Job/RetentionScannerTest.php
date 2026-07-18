<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\Classification\Job\RetentionScanner;
use Waaseyaa\Field\Entity\RetentionPolicyMaintenanceReader;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeEntity;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeStorage;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

/**
 * Intercepts loadMultiple() calls to record batch sizes, then delegates to the
 * wrapped FakeStorage for the actual data.
 */
final class LoadRecordingStorage implements EntityStorageInterface
{
    /** @var list<int> */
    public array $loadMultipleSizes = [];

    public function __construct(private readonly FakeStorage $inner) {}

    public function create(array $values = []): EntityInterface
    {
        return $this->inner->create($values);
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->inner->load($id);
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return $this->inner->loadByKey($key, $value);
    }

    /** @return array<int|string, EntityInterface> */
    public function loadMultiple(array $ids = []): array
    {
        $this->loadMultipleSizes[] = count($ids);

        return $this->inner->loadMultiple($ids);
    }

    public function save(EntityInterface $entity): int
    {
        return $this->inner->save($entity);
    }

    /** @param EntityInterface[] $entities */
    public function delete(array $entities): void
    {
        $this->inner->delete($entities);
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->inner->getQuery();
    }

    public function getEntityTypeId(): string
    {
        return $this->inner->getEntityTypeId();
    }
}

#[CoversClass(RetentionScanner::class)]
final class RetentionScannerTest extends TestCase
{
    use JobTestEnvironment;

    #[Test]
    public function yields_all_entities_in_id_order_across_keyset_batches(): void
    {
        $entities = [];
        for ($i = 1; $i <= 25; $i++) {
            $entities[$i] = new FakeEntity('node', [
                'id' => $i,
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]);
        }

        $inner = new FakeStorage('node', $entities);
        $recording = new LoadRecordingStorage($inner);

        // makeEntityTypeManager accepts EntityStorageInterface; the docblock generic is not enforced at runtime.
        $etm = $this->makeEntityTypeManager(['node' => $recording]); // @phpstan-ignore argument.type
        $scanner = new RetentionScanner($etm, batchSize: 10);

        $yielded = iterator_to_array($scanner->scan('node'));

        // Completeness: all 25 entities yielded in ascending id order.
        self::assertCount(25, $yielded);
        $ids = array_map(static fn(EntityInterface $e): int|string|null => $e->id(), $yielded);
        self::assertSame(range(1, 25), $ids);

        // Bounded hydration: exactly 3 loadMultiple calls (10 + 10 + 5), each ≤ batchSize.
        self::assertCount(3, $recording->loadMultipleSizes, 'expected ceil(25/10) = 3 loadMultiple calls');
        self::assertSame([10, 10, 5], $recording->loadMultipleSizes);
        foreach ($recording->loadMultipleSizes as $size) {
            self::assertLessThanOrEqual(10, $size);
        }
    }

    #[Test]
    public function cutoff_filter_excludes_age_ineligible_entities(): void
    {
        $entities = [];
        for ($i = 1; $i <= 3; $i++) {
            $entities[$i] = new FakeEntity('doc', [
                'id' => $i,
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]);
        }
        // Two age-ineligible rows.
        $entities[4] = new FakeEntity('doc', [
            'id' => 4,
            'classification_label' => 'internal',
            'created_at' => '2999-01-01 00:00:00',
        ]);
        $entities[5] = new FakeEntity('doc', [
            'id' => 5,
            'classification_label' => 'internal',
            'created_at' => '2999-06-01 00:00:00',
        ]);

        $storage = new FakeStorage('doc', $entities);
        $etm = $this->makeEntityTypeManager(['doc' => $storage]);
        $scanner = new RetentionScanner($etm, batchSize: 10);

        $yielded = iterator_to_array($scanner->scan('doc', '2026-01-01 00:00:00'));

        self::assertCount(3, $yielded);
        self::assertSame([1, 2, 3], array_map(static fn(EntityInterface $e): int|string|null => $e->id(), $yielded));
    }

    #[Test]
    public function label_condition_exact_narrows_to_matching_label_only(): void
    {
        $entities = [
            1 => new FakeEntity('doc', ['id' => 1, 'classification_label' => 'internal']),
            2 => new FakeEntity('doc', ['id' => 2, 'classification_label' => 'public']),
            3 => new FakeEntity('doc', ['id' => 3, 'classification_label' => 'internal']),
        ];

        $storage = new FakeStorage('doc', $entities);
        $etm = $this->makeEntityTypeManager(['doc' => $storage]);
        $scanner = new RetentionScanner($etm, batchSize: 10);

        $yielded = iterator_to_array($scanner->scan('doc', null, ['operator' => '=', 'value' => 'internal']));

        self::assertCount(2, $yielded);
        self::assertSame([1, 3], array_map(static fn(EntityInterface $e): int|string|null => $e->id(), $yielded));
    }

    #[Test]
    public function label_condition_prefix_narrows_to_prefixed_labels_only(): void
    {
        $entities = [
            1 => new FakeEntity('doc', ['id' => 1, 'classification_label' => 'nation-confidential']),
            2 => new FakeEntity('doc', ['id' => 2, 'classification_label' => 'internal']),
            3 => new FakeEntity('doc', ['id' => 3, 'classification_label' => 'nation-restricted']),
        ];

        $storage = new FakeStorage('doc', $entities);
        $etm = $this->makeEntityTypeManager(['doc' => $storage]);
        $scanner = new RetentionScanner($etm, batchSize: 10);

        $yielded = iterator_to_array($scanner->scan('doc', null, ['operator' => 'STARTS_WITH', 'value' => 'nation-']));

        self::assertCount(2, $yielded);
        self::assertSame([1, 3], array_map(static fn(EntityInterface $e): int|string|null => $e->id(), $yielded));
    }

    #[Test]
    public function unknown_entity_type_is_skipped_silently(): void
    {
        // ETM with only 'node'; scanning 'article' should yield nothing (no exception).
        $etm = $this->makeEntityTypeManager([
            'node' => new FakeStorage('node', []),
        ]);
        $scanner = new RetentionScanner($etm);

        $yielded = iterator_to_array($scanner->scan('article'));

        self::assertSame([], $yielded);
    }

    // -------------------------------------------------------------------------
    // labelCondition() static helper
    // -------------------------------------------------------------------------

    #[Test]
    public function label_condition_returns_exact_for_single_literal_pattern(): void
    {
        $policy = $this->makePolicy(1, ['action' => 'purge', 'applies_to' => ['internal'], 'trigger_value' => '']);
        $cond = RetentionScanner::labelCondition(new RetentionPolicyMaintenanceReader()->read($policy));

        self::assertSame(['operator' => '=', 'value' => 'internal'], $cond);
    }

    #[Test]
    public function label_condition_returns_starts_with_for_prefix_pattern(): void
    {
        $policy = $this->makePolicy(1, ['action' => 'purge', 'applies_to' => ['nation-*'], 'trigger_value' => '']);
        $cond = RetentionScanner::labelCondition(new RetentionPolicyMaintenanceReader()->read($policy));

        self::assertSame(['operator' => 'STARTS_WITH', 'value' => 'nation-'], $cond);
    }

    #[Test]
    public function label_condition_returns_null_for_bare_wildcard(): void
    {
        $policy = $this->makePolicy(1, ['action' => 'purge', 'applies_to' => ['*'], 'trigger_value' => '']);

        self::assertNull(RetentionScanner::labelCondition(new RetentionPolicyMaintenanceReader()->read($policy)));
    }

    #[Test]
    public function label_condition_returns_null_for_multiple_patterns(): void
    {
        $policy = $this->makePolicy(1, ['action' => 'purge', 'applies_to' => ['internal', 'hold-*'], 'trigger_value' => '']);

        self::assertNull(RetentionScanner::labelCondition(new RetentionPolicyMaintenanceReader()->read($policy)));
    }

    #[Test]
    public function label_condition_returns_null_for_empty_applies_to(): void
    {
        $policy = $this->makePolicy(1, ['action' => 'purge', 'applies_to' => [], 'trigger_value' => '']);

        self::assertNull(RetentionScanner::labelCondition(new RetentionPolicyMaintenanceReader()->read($policy)));
    }
}
