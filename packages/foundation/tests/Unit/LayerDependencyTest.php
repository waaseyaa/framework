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
        'waaseyaa/auth', 'waaseyaa/testing',
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

    #[Test]
    public function foundationHttpLayerOutsideRouterDoesNotImportNonFoundationWaaseyaaPackages(): void
    {
        $httpSrc = dirname(__DIR__, 2) . '/src/Http';
        $httpRouterDir = $httpSrc . '/Router';
        $httpInboundDir = $httpSrc . '/Inbound';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($httpSrc, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_starts_with($path, $httpRouterDir . \DIRECTORY_SEPARATOR)) {
                continue;
            }
            // Http/Inbound/ hosts cross-layer read-model adapters (M5D pattern):
            // L0 classes that implement L4+ interfaces, bound at the kernel boundary
            // by per-feature service providers. Exempt for the same reason as
            // Http/Router/ — the directory is the documented cross-layer surface.
            // Also enumerated in bin/check-package-layers under its INBOUND_EXEMPT set.
            if (str_starts_with($path, $httpInboundDir . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (preg_match_all('/^\s*use\s+Waaseyaa\\\\(?!Foundation\\\\)[^;]+;/m', $contents, $matches)) {
                foreach ($matches[0] as $line) {
                    $violations[] = str_replace(dirname(__DIR__, 2) . '/src/', '', $path) . ': ' . trim($line);
                }
            }
            if (preg_match_all('/^\s*use\s+function\s+Waaseyaa\\\\(?!Foundation\\\\)[^;]+;/m', $contents, $fnMatches)) {
                foreach ($fnMatches[0] as $line) {
                    $violations[] = str_replace(dirname(__DIR__, 2) . '/src/', '', $path) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Foundation Http/ outside Http/Router/ must not import Waaseyaa namespaces other than Waaseyaa\\Foundation\\\n"
            . implode("\n", $violations),
        );
    }
}
