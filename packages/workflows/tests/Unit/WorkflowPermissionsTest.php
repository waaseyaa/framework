<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\WorkflowPermissions;

final class WorkflowPermissionsTest extends TestCase
{
    #[Test]
    public function it_uses_explicit_permissions_and_derives_missing_permissions(): void
    {
        $definitions = WorkflowPermissions::forWorkflow('editorial', [
            'approve' => ['label' => 'Approve', 'permission' => 'publish reviewed content'],
            'submit' => ['label' => 'Submit'],
        ]);

        self::assertSame([
            'publish reviewed content',
            'use editorial transition submit',
        ], array_keys($definitions));
    }

    #[Test]
    public function it_refuses_two_transitions_resolving_to_one_permission(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkflowPermissions::forWorkflow('editorial', [
            'approve' => 'publish content',
            'publish' => 'publish content',
        ]);
    }
}
