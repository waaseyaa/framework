<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityValueContainer;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RestrictedEntityValue;

final class SealedEntityMemoryGateTest extends TestCase
{
    #[Test]
    public function retained_memory_stays_within_the_approved_restricted_field_and_user_limits(): void
    {
        $count = 10_000;
        $values = [
            'uid' => 1,
            'name' => 'Member',
            'mail' => 'member@example.test',
            'pass' => 'hash',
            'roles' => ['member'],
            'permissions' => [],
            'status' => 1,
            'created' => 1,
            'verification' => 'pending',
            'two_factor' => 'enabled',
        ];
        $fields = array_keys($values);
        $levels = array_fill_keys($fields, FieldReadLevel::Internal);
        $levels['uid'] = FieldReadLevel::Public;
        $levels['name'] = FieldReadLevel::Protected;
        $layout = new EntityReadLayout(new EntityReadLayoutGeneration(), $levels);

        gc_collect_cycles();
        $before = memory_get_usage(false);
        $baseline = [];
        for ($i = 0; $i < $count; ++$i) {
            $row = $values;
            $row['uid'] = $i;
            $entity = new MemoryGateEntity($row, 'user', ['id' => 'uid']);
            $entity->_attachEntityStructure($this->structure($i, $fields));
            $baseline[] = $entity;
        }
        $baselineBytes = memory_get_usage(false) - $before;
        unset($baseline, $entity);
        gc_collect_cycles();

        $before = memory_get_usage(false);
        $sealed = [];
        for ($i = 0; $i < $count; ++$i) {
            $row = $values;
            $row['uid'] = $i;
            $boundary = new EntityInitializationBoundary();
            $payload = $boundary->factory()->seal(
                $row,
                $layout,
                $this->structure($i, $fields),
                'user',
                ['id' => 'uid'],
            );
            $sealed[] = $boundary->installer()->instantiate(MemoryGateEntity::class, $payload);
        }
        $sealedBytes = memory_get_usage(false) - $before;
        $incrementalUser = ($sealedBytes - $baselineBytes) / $count;
        $restrictedFields = count($fields) - 1;

        self::assertLessThanOrEqual(2048.0, $incrementalUser, 'A populated User must retain no more than 2 KiB of boundary memory.');
        self::assertLessThanOrEqual(160.0, $incrementalUser / $restrictedFields, 'Each populated restricted field must retain no more than 160 bytes after layout amortization.');

        $containerProperty = new \ReflectionProperty(EntityBase::class, 'valueContainer');
        $container = $containerProperty->getValue($sealed[0]);
        self::assertInstanceOf(EntityValueContainer::class, $container);
        $valuesProperty = new \ReflectionProperty(EntityValueContainer::class, 'values');
        /** @var array<string, mixed> $stored */
        $stored = $valuesProperty->getValue($container);
        self::assertIsInt($stored['uid'], 'Public fields must remain direct values with zero per-field access object.');
        self::assertInstanceOf(RestrictedEntityValue::class, $stored['mail']);

        $weak = \WeakReference::create($sealed[0]);
        unset($container, $stored, $sealed, $payload, $boundary);
        gc_collect_cycles();
        self::assertNull($weak->get(), 'No entity/view reference may remain after scope exit.');
    }

    /** @param list<string> $fields */
    private function structure(int $id, array $fields): EntityStructure
    {
        return new EntityStructure('user', 'user', $id, null, fieldNames: $fields);
    }
}

final class MemoryGateEntity extends EntityBase {}
