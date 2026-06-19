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
