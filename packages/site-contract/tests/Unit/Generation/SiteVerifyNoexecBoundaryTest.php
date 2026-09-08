<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * FW-SITE-VERIFY-NOEXEC-01 (placeholder — no GitHub issue assigned yet).
 *
 * Reproduces, without Docker, the two properties the repaired generated
 * acceptance test (`SiteArtifactRenderer::acceptanceTest()`) now measures
 * separately, against the *real* generated `bin/maintenance/site-verify`
 * bytes:
 *
 *  - Property 1 (POSIX permission bits via fileperms()) must still FAIL
 *    when the execute bits are genuinely absent — it remains a real
 *    discriminator, not a rubber stamp.
 *  - Property 2 (actually running the command through `PHP_BINARY <script>`
 *    with `--self-test`) must SUCCEED even when the execute bits are
 *    absent — because PHP interprets the file's bytes directly and never
 *    asks the filesystem for permission to execute it. This is the
 *    mount-independent behaviour the noexec-tmpfs defect needed: on the
 *    real noexec mount the bits ARE present (0755) but is_executable()
 *    still lies; here we remove the bits entirely (the closest local proxy
 *    to "the OS refuses execution of this file") and show property 2 is
 *    unaffected while property 1 correctly flags it.
 */
#[CoversNothing]
final class SiteVerifyNoexecBoundaryTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $site = new SiteArtifactRenderer()->render($manifest);
        $content = $site->artifacts['bin/maintenance/site-verify']->content;

        $this->scriptPath = tempnam(sys_get_temp_dir(), 'waaseyaa_site_verify_') . '.php';
        file_put_contents($this->scriptPath, $content);
    }

    protected function tearDown(): void
    {
        @unlink($this->scriptPath);
    }

    #[Test]
    public function propertyOneFailsWhenExecuteBitsAreGenuinelyAbsent(): void
    {
        chmod($this->scriptPath, 0o644);

        $mode = fileperms($this->scriptPath);
        self::assertNotFalse($mode);
        self::assertSame(0, $mode & 0o111, 'sanity: the mutated fixture must have no execute bits set');

        // This is exactly the assertion the generated acceptance test makes.
        // It must be false here — the discriminator still catches a missing
        // chmod. (If Framework regresses and stops chmod-ing the artifact
        // 0755, this is the assertion that turns red.)
        self::assertNotSame(0o111, $mode & 0o111);
    }

    #[Test]
    public function propertyOneSucceedsWhenExecuteBitsArePresent(): void
    {
        chmod($this->scriptPath, 0o755);

        $mode = fileperms($this->scriptPath);
        self::assertNotFalse($mode);
        self::assertSame(0o111, $mode & 0o111);
    }

    #[Test]
    public function propertyTwoSucceedsThroughPhpBinaryEvenWithoutExecuteBits(): void
    {
        // Remove every execute bit — the closest same-host proxy for "this
        // filesystem refuses to run the file", which is what a noexec mount
        // does to a genuinely mode-0755 file in the real defect.
        chmod($this->scriptPath, 0o644);
        self::assertFalse(is_executable($this->scriptPath), 'sanity: the mutated fixture must not be directly executable');

        $invocation = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->scriptPath) . ' --self-test';
        exec($invocation . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, "PHP_BINARY invocation must succeed regardless of the execute bit:\n" . implode("\n", $output));
        self::assertStringContainsString('self-test ok', implode("\n", $output));
    }

    #[Test]
    public function propertyTwoStillSucceedsWithExecuteBitsPresent(): void
    {
        chmod($this->scriptPath, 0o755);

        $invocation = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->scriptPath) . ' --self-test';
        exec($invocation . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    private function manifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Example Nation
              id: example-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - id: page
                canonical_route: /{slug}
            capabilities:
              - id: governed_authoring
                state: active
                package: waaseyaa/page-builder
                provider: site.page_builder
                configuration_authority: .waaseyaa/site.yaml#/capabilities/governed_authoring
                public_routes: []
                data_classification: public
                lifecycle: [create, revise, publish, archive]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }
}
