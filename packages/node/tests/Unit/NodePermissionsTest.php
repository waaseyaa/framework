<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Node\NodePermissions;

final class NodePermissionsTest extends TestCase
{
    #[Test]
    public function it_builds_the_complete_deterministic_bundle_family(): void
    {
        $definitions = NodePermissions::forBundles(['press_release', 'article', 'article']);

        self::assertSame([
            'create article content',
            'create press_release content',
            'delete any article content',
            'delete any press_release content',
            'delete own article content',
            'delete own press_release content',
            'edit any article content',
            'edit any press_release content',
            'edit own article content',
            'edit own press_release content',
        ], array_keys($definitions));
        self::assertSame('Create Article content', $definitions[NodePermissions::create('article')]['title']);
    }

    #[Test]
    public function it_refuses_a_subject_that_could_change_the_permission_grammar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NodePermissions::forBundles(['article content']);
    }
}
