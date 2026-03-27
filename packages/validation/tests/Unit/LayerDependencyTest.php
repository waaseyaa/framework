<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the validation package (layer 0) never depends on higher-layer packages.
 */
#[CoversNothing]
final class LayerDependencyTest extends TestCase
{
    /**
     * Layer-1+ packages that validation must never require.
     */
    private const FORBIDDEN_PACKAGES = [
        // Layer 1 — Core Data
        'waaseyaa/entity',
        'waaseyaa/entity-storage',
        'waaseyaa/access',
        'waaseyaa/user',
        'waaseyaa/config',
        'waaseyaa/field',
        'waaseyaa/auth',
        // Layer 2 — Content Types
        'waaseyaa/node',
        'waaseyaa/taxonomy',
        'waaseyaa/media',
        'waaseyaa/path',
        'waaseyaa/menu',
        'waaseyaa/note',
        'waaseyaa/relationship',
        // Layer 3 — Services
        'waaseyaa/workflows',
        'waaseyaa/search',
        'waaseyaa/billing',
        'waaseyaa/github',
        // Layer 4 — API
        'waaseyaa/api',
        'waaseyaa/routing',
        // Layer 5 — AI
        'waaseyaa/ai-schema',
        'waaseyaa/ai-agent',
        'waaseyaa/ai-pipeline',
        'waaseyaa/ai-vector',
        // Layer 6 — Interfaces
        'waaseyaa/cli',
        'waaseyaa/admin',
        'waaseyaa/mcp',
        'waaseyaa/ssr',
        'waaseyaa/telescope',
        'waaseyaa/deployer',
        'waaseyaa/inertia',
    ];

    #[Test]
    public function validationPackageDoesNotDependOnHigherLayers(): void
    {
        $composerJsonPath = dirname(__DIR__, 2) . '/composer.json';
        $this->assertFileExists($composerJsonPath);

        $composerData = json_decode(
            (string) file_get_contents($composerJsonPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $require = $composerData['require'] ?? [];
        $requireDev = $composerData['require-dev'] ?? [];
        $allDeps = array_merge(array_keys($require), array_keys($requireDev));

        foreach (self::FORBIDDEN_PACKAGES as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $allDeps,
                sprintf(
                    'Validation (layer 0) must not depend on %s (higher layer). '
                    . 'Move the dependent code to the higher-layer package instead.',
                    $forbidden,
                ),
            );
        }
    }
}
