<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * D6 served-bundle content assertion.
 *
 * The admin SPA ships to downstream consumers as the prebuilt bundle committed
 * at packages/admin-surface/dist/. This test asserts the SHIPPED bundle actually
 * contains the current feedback feature, so a stale bundle (source advanced but
 * dist not rebuilt) fails the PHP suite in addition to the signature gate
 * (bin/check-admin-dist-fresh). The alpha.226 edit busy-state feedback shipped
 * in source but never reached the served bundle — this is the second guard.
 */
#[CoversNothing]
final class AdminDistContentTest extends TestCase
{
    private function distDir(): string
    {
        return dirname(__DIR__, 2) . '/dist';
    }

    #[Test]
    public function shipped_bundle_and_signature_exist(): void
    {
        self::assertDirectoryExists($this->distDir(), 'packages/admin-surface/dist must be committed (prebuilt admin SPA).');
        self::assertFileExists($this->distDir() . '/index.html');
        self::assertFileExists(
            dirname(__DIR__, 2) . '/dist.signature',
            'The admin dist freshness signature must be committed alongside the bundle.',
        );
    }

    #[Test]
    public function shipped_bundle_contains_current_edit_feedback_and_anchors(): void
    {
        $js = $this->concatenatedBundleJs();

        // alpha.226 edit busy-state feedback ("Opening…"): absent from a stale
        // bundle, present once the dist is rebuilt from current source (D6).
        self::assertStringContainsString(
            'Opening',
            $js,
            'The served admin bundle is missing the edit busy-state feedback ("Opening…"). '
            . 'The committed dist is stale — rebuild with bin/build-admin-dist.',
        );

        // Wayfinding Phase-1 anchor groundwork: stable data-anchor IDs must be
        // compiled into the shipped bundle so the anchor catalog has real seeds.
        self::assertStringContainsString(
            'data-anchor',
            $js,
            'The served admin bundle is missing the data-anchor groundwork — rebuild with bin/build-admin-dist.',
        );
    }

    #[Test]
    public function shipped_bundle_contains_the_mcp_approvals_page(): void
    {
        // #2177 F1 C1c: the MCP approvals operator page must reach the served
        // bundle — both the prerendered route shell and the compiled page code.
        self::assertFileExists(
            $this->distDir() . '/mcp/approvals/index.html',
            'The served admin bundle is missing the prerendered /mcp/approvals route — rebuild with bin/build-admin-dist.',
        );

        $js = $this->concatenatedBundleJs();
        self::assertStringContainsString(
            'mcp_approvals_title',
            $js,
            'The served admin bundle is missing the MCP approvals page — rebuild with bin/build-admin-dist.',
        );
        self::assertStringContainsString(
            'mcp.approval.decide',
            $js,
            'The served admin bundle is missing the capability-gated MCP approval decision UI — rebuild with bin/build-admin-dist.',
        );
    }

    #[Test]
    public function shipped_bundle_contains_the_shell_free_page_builder_client(): void
    {
        $js = $this->concatenatedBundleJs();

        self::assertStringContainsString(
            'page-builder-embed',
            $js,
            'The served admin bundle is missing the shell-free page-builder route — rebuild with bin/build-admin-dist.',
        );
        self::assertStringContainsString(
            'data-page-builder-client',
            $js,
            'The served admin bundle is missing the shared page-builder client identity.',
        );
    }

    #[Test]
    public function shipped_bundle_contains_the_shared_admin_design_system(): void
    {
        $entryStylesheets = glob($this->distDir() . '/_nuxt/entry.*.css') ?: [];
        $layoutStylesheets = glob($this->distDir() . '/_nuxt/default.*.css') ?: [];
        self::assertCount(1, $entryStylesheets, 'The shipped bundle must have exactly one global entry stylesheet.');
        self::assertCount(1, $layoutStylesheets, 'The shipped bundle must have exactly one default-layout stylesheet.');
        $globalCss = (string) file_get_contents($entryStylesheets[0]);
        $layoutCss = (string) file_get_contents($layoutStylesheets[0]);

        self::assertStringContainsString(
            '--admin-target-size:44px',
            $globalCss,
            'The served admin bundle is missing the global design-system tokens.',
        );
        self::assertStringContainsString(
            '.field-input',
            $globalCss,
            'The served admin bundle is missing global structured-editor field styling.',
        );
        self::assertStringNotContainsString(
            '--admin-target-size:44px',
            $layoutCss,
            'The shared design-system token regressed into the default-shell-only stylesheet.',
        );
    }

    #[Test]
    public function shipped_bundle_contains_task_oriented_form_sections(): void
    {
        $js = $this->concatenatedBundleJs();

        self::assertStringContainsString('x-form-sections', $js);
        self::assertStringContainsString('data-section-id', $js);
        self::assertStringContainsString('form_other_details', $js);
    }

    #[Test]
    public function shipped_bundle_build_identity_is_internally_consistent(): void
    {
        $latest = json_decode(
            (string) file_get_contents($this->distDir() . '/_nuxt/builds/latest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $buildId = $latest['id'] ?? null;
        self::assertIsString($buildId);
        self::assertMatchesRegularExpression('/^waaseyaa-[a-f0-9]{32}$/D', $buildId);

        $metaFiles = glob($this->distDir() . '/_nuxt/builds/meta/*.json') ?: [];
        self::assertCount(1, $metaFiles, 'Exactly one normalized Nuxt build-meta file must ship.');
        self::assertSame($buildId . '.json', basename($metaFiles[0]));
        self::assertStringContainsString(
            'buildId:"' . $buildId . '"',
            (string) file_get_contents($this->distDir() . '/index.html'),
        );
    }

    #[Test]
    public function shipped_bundle_contains_the_shell_free_structured_entity_editor(): void
    {
        $js = $this->concatenatedBundleJs();

        self::assertStringContainsString(
            'entity-editor-embed',
            $js,
            'The served admin bundle is missing the shell-free structured-editor route; rebuild with bin/build-admin-dist.',
        );
        self::assertStringContainsString(
            'data-entity-editor-client',
            $js,
            'The served admin bundle is missing the shared structured-editor client identity.',
        );
        self::assertStringContainsString(
            'initialBundle',
            $js,
            'The served admin bundle cannot open a shared create form in a host-selected content bundle.',
        );
    }

    #[Test]
    public function admin_source_and_shipped_bundle_do_not_depend_on_external_icon_infrastructure(): void
    {
        $adminDir = dirname(__DIR__, 3) . '/admin';
        foreach (['nuxt.config.ts', 'package.json', 'package-lock.json'] as $relativePath) {
            self::assertFalse(
                str_contains((string) file_get_contents($adminDir . '/' . $relativePath), '@nuxt/icon'),
                sprintf(
                    'The admin SPA must remain self-contained; %s must not restore the runtime icon-provider dependency.',
                    $relativePath,
                ),
            );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->distDir(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach (['api.iconify.design', 'api.simplesvg.com', 'api.unisvg.com'] as $forbiddenOrigin) {
                self::assertFalse(
                    str_contains($contents, $forbiddenOrigin),
                    sprintf(
                        'The shipped admin file %s must not contact the external icon origin %s.',
                        $file->getPathname(),
                        $forbiddenOrigin,
                    ),
                );
            }
        }
    }

    private function concatenatedBundleJs(): string
    {
        $nuxtDir = $this->distDir() . '/_nuxt';
        self::assertDirectoryExists($nuxtDir, 'Built bundle dir packages/admin-surface/dist/_nuxt is missing.');

        $js = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($nuxtDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                $js .= (string) file_get_contents($file->getPathname());
            }
        }

        return $js;
    }

}
