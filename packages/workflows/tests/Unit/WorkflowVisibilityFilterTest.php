<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Workflows\WorkflowVisibilityFilter;

/**
 * @covers \Waaseyaa\Workflows\WorkflowVisibilityFilter
 */
#[CoversClass(WorkflowVisibilityFilter::class)]
final class WorkflowVisibilityFilterTest extends TestCase
{
    public function testRelationshipAdaptersUseTheServedProjection(): void
    {
        $filter = new WorkflowVisibilityFilter();
        $entity = new class (['type' => 'article', 'status' => 1, 'workflow_state' => 'draft']) extends ContentEntityBase {
            /** @var array<string, string> */
            protected array $casts = ['status' => 'bool'];

            public function __construct(array $values = [])
            {
                parent::__construct($values, 'node', [
                    'id' => 'nid',
                    'uuid' => 'uuid',
                    'label' => 'title',
                    'bundle' => 'type',
                ]);
            }
        };

        self::assertTrue($filter->isEntityPublic('node', [
            'status' => 1,
            'workflow_state' => 'draft',
        ]));
        self::assertTrue($filter->isEntityPublicForEntity($entity));
    }
}
