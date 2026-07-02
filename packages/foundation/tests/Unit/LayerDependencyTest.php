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

    /**
     * First `use Waaseyaa\<Prefix>\...` namespace segment for every Layer-1+
     * package, i.e. the file-level analogue of FORBIDDEN_DEPS above (which
     * matches composer.json 'require' package names, not PHP namespaces).
     * Layer-0 sibling namespaces (Cache, Database, Queue, Plugin, Scheduler,
     * …) are intentionally excluded — those are legitimate lateral imports
     * foundation already declares in its own composer.json require.
     *
     * Ground truth for casing + layer membership: bin/check-package-layers'
     * $namespacePrefixToShort / $layerByShort arrays (verified against every
     * package's actual `namespace Waaseyaa\...;` declaration on 2026-07-02).
     * Keep in sync by hand when a new Layer-1+ package is added — the same
     * upkeep note as CLAUDE.md "Composer layer graph".
     */
    private const FORBIDDEN_NAMESPACE_PREFIXES = [
        // Layer 1 — Core Data
        'Entity', 'EntityStorage', 'Access', 'Audit', 'User', 'Config', 'Field', 'Auth', 'Testing', 'Oidc',
        // Layer 2 — Content Types
        'Attachment', 'Node', 'Taxonomy', 'Media', 'Path', 'Menu', 'Note', 'Relationship', 'Groups', 'Engagement',
        // Layer 3 — Services
        'Workflows', 'Search', 'Seo', 'Notification', 'Billing', 'GitHub', 'NorthCloud', 'StructuredImport', 'Migration', 'Listing', 'Messaging',
        // Layer 4 — API
        'Api', 'Routing', 'Bimaaji', 'Wayfinding',
        // Layer 5 — AI
        'AI',
        // Layer 6 — Interfaces
        'CLI', 'FrankenPhp', 'AdminSurface', 'GraphQL', 'Mcp', 'SSR', 'Genealogy', 'Telescope', 'Deployer', 'Inertia', 'Debug', 'Workspace',
    ];

    /**
     * Per-file exemptions for legitimate cross-layer imports outside Kernel/
     * (bulk-exempt below — CLAUDE.md "Exemption": entry-point orchestrators
     * that intentionally wire all layers) and outside Http/Router/, Http/Inbound/
     * (governed by bin/check-package-layers' allowlist scan — the HTTP-substrate
     * test above deliberately skips those two directories too, so NO
     * PHPUnit-level check covers them; skipped here to keep one owner per
     * directory rather than to avoid double coverage).
     *
     * Mirrors bin/check-package-layers' $kernelExemptFiles allowlist style:
     * every entry carries a one-line rationale. Keep the two lists in sync —
     * this test is a PHPUnit-level safety net for the same invariant
     * bin/check-package-layers enforces as a standalone CI gate.
     *
     * @var array<string, string> relative-to-src path => rationale
     */
    private const SRC_SCAN_EXEMPT_FILES = [
        // Health-check subsystem: inspects Entity/EntityStorage/Field state to
        // report operator-facing schema drift. Constructed only by Kernel/
        // (AbstractKernel::buildHandlerContainer(), itself already Kernel/-exempt)
        // and consumed downward by cli (L6) health commands. Codified as
        // kernel-adjacent rather than relocated back in mission #1257 K6(c)
        // (see docs/specs/infrastructure.md "Kernel exemption surface") — this
        // entry re-affirms that decision, it does not introduce a new one.
        // Also listed in bin/check-package-layers $kernelExemptFiles.
        'Diagnostic/HealthChecker.php' =>
            'Health-check subsystem inspects Entity/Field state; constructed only by Kernel/; codified kernel-adjacent in mission #1257 K6(c).',
        'Diagnostic/BootDiagnosticReport.php' =>
            'Boot-time snapshot DTO of EntityTypeInterface state; constructed only by Kernel::getBootReport(); same #1257 K6(c) rationale as HealthChecker.php.',
        // Binds L4 Api\MercureMonitor read-model interfaces to Foundation
        // adapters; wired only by HttpKernel via ApiServiceProvider's
        // resolveOptional() calls. Also listed in bin/check-package-layers
        // $kernelExemptFiles.
        'MercureMonitorServiceProvider.php' =>
            'Binds L4 Api\\MercureMonitor interfaces to Foundation adapters; wired only by HttpKernel (mirrors bin/check-package-layers exemption).',
        // Capability marker interfaces: a ServiceProvider implements the
        // interface, Kernel/GraphQL bootstrap checks `instanceof` before
        // calling it (CLAUDE.md "Layer discipline for imports" — the
        // event/capability-interface decoupling pattern permits the upward
        // *type* import the method signature carries). Also listed in
        // bin/check-package-layers $kernelExemptFiles.
        'ServiceProvider/Capability/HasMiddlewareInterface.php' =>
            'Capability marker interface — EntityTypeManager param crosses layers via the documented capability-interface decoupling pattern; checked by instanceof in HttpKernel.',
        'ServiceProvider/Capability/HasGraphqlMutationOverridesInterface.php' =>
            'Capability marker interface — EntityTypeManager param crosses layers via the documented capability-interface decoupling pattern; checked by instanceof in the GraphQL (L6) bootstrap.',
    ];

    #[Test]
    public function foundationSrcOutsideKernelAndHttpSubstrateDoesNotImportForbiddenLayerPackages(): void
    {
        $srcRoot = $this->normalizePath(dirname(__DIR__, 2) . '/src');
        $kernelDir = $srcRoot . '/Kernel';
        $httpRouterDir = $srcRoot . '/Http/Router';
        $httpInboundDir = $srcRoot . '/Http/Inbound';
        $violations = [];

        $prefixPattern = implode('|', array_map(
            static fn(string $prefix): string => preg_quote($prefix, '/'),
            self::FORBIDDEN_NAMESPACE_PREFIXES,
        ));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $this->normalizePath($file->getPathname());

            if (str_starts_with($path, $kernelDir . '/')) {
                continue;
            }
            if (str_starts_with($path, $httpRouterDir . '/') || str_starts_with($path, $httpInboundDir . '/')) {
                continue;
            }

            $relative = str_replace($srcRoot . '/', '', $path);

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (!preg_match_all(
                '/^\s*use(?:\s+function|\s+const)?\s+Waaseyaa\\\\(?:' . $prefixPattern . ')\\\\[^;]+;/m',
                $contents,
                $matches,
            )) {
                continue;
            }

            if (isset(self::SRC_SCAN_EXEMPT_FILES[$relative])) {
                continue;
            }

            foreach ($matches[0] as $line) {
                $violations[] = $relative . ': ' . trim($line);
            }
        }

        // Keep the allowlist itself honest: every exemption must point at a
        // file that still exists and still carries a real rationale, or it
        // is dead weight nobody will notice drifting.
        foreach (self::SRC_SCAN_EXEMPT_FILES as $relative => $rationale) {
            $this->assertFileExists(
                $srcRoot . '/' . $relative,
                "SRC_SCAN_EXEMPT_FILES entry for {$relative} no longer exists — remove the stale exemption.",
            );
            $this->assertNotSame(
                '',
                trim($rationale),
                "SRC_SCAN_EXEMPT_FILES entry for {$relative} must carry a one-line rationale.",
            );
        }

        $this->assertSame(
            [],
            $violations,
            "Foundation src/ (outside Kernel/, Http/Router/, Http/Inbound/) must not import Layer-1+ Waaseyaa\n"
            . "namespaces without an explicit, rationalized SRC_SCAN_EXEMPT_FILES entry:\n"
            . implode("\n", $violations),
        );
    }

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
        $srcRoot = $this->normalizePath(dirname(__DIR__, 2) . '/src');
        $httpSrc = $srcRoot . '/Http';
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
            $path = $this->normalizePath($file->getPathname());
            if (str_starts_with($path, $httpRouterDir . '/')) {
                continue;
            }
            // Http/Inbound/ hosts cross-layer read-model adapters (M5D pattern):
            // L0 classes that implement L4+ interfaces, bound at the kernel boundary
            // by per-feature service providers. Exempt for the same reason as
            // Http/Router/ — the directory is the documented cross-layer surface.
            // Also enumerated in bin/check-package-layers under its INBOUND_EXEMPT set.
            if (str_starts_with($path, $httpInboundDir . '/')) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (preg_match_all('/^\s*use\s+Waaseyaa\\\\(?!Foundation\\\\)[^;]+;/m', $contents, $matches)) {
                foreach ($matches[0] as $line) {
                    $violations[] = str_replace($srcRoot . '/', '', $path) . ': ' . trim($line);
                }
            }
            if (preg_match_all('/^\s*use\s+function\s+Waaseyaa\\\\(?!Foundation\\\\)[^;]+;/m', $contents, $fnMatches)) {
                foreach ($fnMatches[0] as $line) {
                    $violations[] = str_replace($srcRoot . '/', '', $path) . ': ' . trim($line);
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

    /**
     * Mechanical cross-check for the dual-list drift risk: SRC_SCAN_EXEMPT_FILES
     * here and bin/check-package-layers' $kernelExemptFiles are maintained by
     * hand (the script calls exit(), so it cannot be require'd from a test).
     * The script CAN be read as text, though — every foundation exemption this
     * test grants must also appear in the script's allowlist, so the two lists
     * cannot silently drift apart in the permissive direction. (The reverse —
     * script entries absent here — is fine: the script governs directories
     * this scan deliberately skips, e.g. Http/Router/.)
     */
    #[Test]
    public function srcScanExemptionsAreMirroredInCheckPackageLayersAllowlist(): void
    {
        $script = dirname(__DIR__, 4) . '/bin/check-package-layers';
        $contents = file_get_contents($script);
        self::assertIsString($contents, 'bin/check-package-layers must be readable');

        preg_match_all("/'((?:foundation|cache)\\/src\\/[^']+)'/", $contents, $m);
        $scriptEntries = array_flip($m[1]);

        $missing = [];
        foreach (array_keys(self::SRC_SCAN_EXEMPT_FILES) as $relative) {
            if (!isset($scriptEntries['foundation/src/' . $relative])) {
                $missing[] = $relative;
            }
        }

        self::assertSame(
            [],
            $missing,
            "SRC_SCAN_EXEMPT_FILES entries missing from bin/check-package-layers' \$kernelExemptFiles "
            . '(add them there with a rationale, or remove the exemption here): '
            . implode(', ', $missing),
        );
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
