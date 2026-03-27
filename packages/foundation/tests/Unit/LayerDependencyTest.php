<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LayerDependencyTest extends TestCase
{
    /** Layer 1+ packages that Foundation must NOT depend on. */
    private const FORBIDDEN_DEPS = [
        // Layer 1 — Core Data
        'waaseyaa/entity', 'waaseyaa/entity-storage', 'waaseyaa/access',
        'waaseyaa/user', 'waaseyaa/config', 'waaseyaa/field',
        'waaseyaa/auth',
        // Layer 2 — Content Types
        'waaseyaa/node', 'waaseyaa/taxonomy', 'waaseyaa/media',
        'waaseyaa/path', 'waaseyaa/menu', 'waaseyaa/note',
        'waaseyaa/relationship',
        // Layer 3 — Services
        'waaseyaa/workflows', 'waaseyaa/search',
        'waaseyaa/billing', 'waaseyaa/github',
        // Layer 4 — API
        'waaseyaa/api', 'waaseyaa/routing',
        // Layer 5 — AI
        'waaseyaa/ai-schema', 'waaseyaa/ai-agent', 'waaseyaa/ai-pipeline', 'waaseyaa/ai-vector',
        // Layer 6 — Interfaces
        'waaseyaa/cli', 'waaseyaa/admin', 'waaseyaa/mcp', 'waaseyaa/ssr',
        'waaseyaa/deployer', 'waaseyaa/inertia',
    ];

    #[Test]
    public function foundationDoesNotDependOnHigherLayerPackages(): void
    {
        $composerJson = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $requires = array_keys($composerJson['require'] ?? []);

        foreach (self::FORBIDDEN_DEPS as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $requires,
                "Foundation (layer 0) must not depend on {$forbidden}",
            );
        }
    }
}
