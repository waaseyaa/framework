<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\AdminBuild\AdminDistAcceptanceException;
use Waaseyaa\CLI\AdminBuild\AdminDistWorkspaceGuard;

/**
 * #2524 — the canonical operation must refuse to start from an ambiguous
 * Admin source or generated-output boundary, rather than baking the ambiguity
 * into a published bundle nobody can reproduce.
 */
#[CoversClass(AdminDistWorkspaceGuard::class)]
final class AdminDistWorkspaceGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            new Filesystem()->remove($dir);
        }
        $this->tempDirs = [];
    }

    #[Test]
    public function a_clean_boundary_is_accepted_even_when_admin_source_is_modified(): void
    {
        $root = $this->fixture();

        new AdminDistWorkspaceGuard()->assertAcceptable($root, [
            ' M packages/admin/app/pages/index.vue',
            'M  packages/admin/package.json',
            '?? docs/notes.md',
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function an_unmerged_admin_source_path_is_refused(): void
    {
        $root = $this->fixture();

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, [
                'UU packages/admin/app/pages/index.vue',
            ]);
            self::fail('An unmerged admin source path must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('admin-source-unmerged', $exception->errorCode);
            self::assertContains('packages/admin/app/pages/index.vue', $exception->details);
        }
    }

    #[Test]
    public function an_unmerged_generated_output_path_is_refused(): void
    {
        $root = $this->fixture();

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, [
                'AA packages/admin-surface/dist/_nuxt/entry.1234.js',
            ]);
            self::fail('An unmerged generated-output path must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('generated-output-unmerged', $exception->errorCode);
        }
    }

    #[Test]
    public function a_partially_staged_generated_tree_is_refused(): void
    {
        $root = $this->fixture();

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, [
                'MM packages/admin-surface/dist/index.html',
            ]);
            self::fail('A partially staged generated tree must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('generated-output-partially-staged', $exception->errorCode);
            self::assertContains('packages/admin-surface/dist/index.html', $exception->details);
        }
    }

    #[Test]
    public function an_untracked_admin_source_file_is_refused(): void
    {
        $root = $this->fixture();

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, [
                '?? packages/admin/app/composables/useDraft.ts',
            ]);
            self::fail('An untracked admin source file must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('admin-source-untracked', $exception->errorCode);
            self::assertContains('packages/admin/app/composables/useDraft.ts', $exception->details);
        }
    }

    #[Test]
    public function unresolved_conflict_markers_in_generated_output_are_refused(): void
    {
        $root = $this->fixture();
        file_put_contents(
            $root . '/packages/admin-surface/dist/index.html',
            "<html>\n<<<<<<< HEAD\nside a\n=======\nside b\n>>>>>>> other\n</html>\n",
        );

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, []);
            self::fail('Unresolved conflict markers must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('conflict-markers-present', $exception->errorCode);
            self::assertContains('packages/admin-surface/dist/index.html', $exception->details);
        }
    }

    #[Test]
    public function unresolved_conflict_markers_in_admin_source_are_refused(): void
    {
        $root = $this->fixture();
        file_put_contents(
            $root . '/packages/admin/app/pages/index.vue',
            "<template>\n<<<<<<< ours\n<div/>\n=======\n<span/>\n>>>>>>> theirs\n</template>\n",
        );

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, []);
            self::fail('Unresolved conflict markers in admin source must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('conflict-markers-present', $exception->errorCode);
            self::assertContains('packages/admin/app/pages/index.vue', $exception->details);
        }
    }

    #[Test]
    public function a_stale_generated_reference_without_its_published_tree_is_refused(): void
    {
        $root = $this->fixture();
        new Filesystem()->remove($root . '/packages/admin-surface/dist');

        try {
            new AdminDistWorkspaceGuard()->assertAcceptable($root, []);
            self::fail('A signature without a published tree must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('generated-output-incomplete', $exception->errorCode);
        }
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_guard_' . bin2hex(random_bytes(8));
        $this->tempDirs[] = $root;
        mkdir($root . '/packages/admin-surface/dist/_nuxt', 0o755, true);
        mkdir($root . '/packages/admin/app/pages', 0o755, true);
        file_put_contents($root . '/packages/admin-surface/dist/index.html', "<html>ok</html>\n");
        file_put_contents($root . '/packages/admin-surface/dist/_nuxt/entry.1234.js', "console.log('ok')\n");
        file_put_contents($root . '/packages/admin-surface/dist.signature', str_repeat('a', 64) . "\n");
        file_put_contents($root . '/packages/admin/app/pages/index.vue', "<template><div/></template>\n");

        return $root;
    }
}
