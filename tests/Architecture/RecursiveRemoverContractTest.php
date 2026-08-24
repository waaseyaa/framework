<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Locks #2491's recursive-remover acceptance criterion: "test-only recursive
 * removers converted to Filesystem::remove or routed through the existing
 * packages/testing TemporaryDirectory helper; the duplicated private removers
 * are deleted, not left dormant."
 *
 * The defect the criterion targets is a hand-rolled remover that decides what
 * to delete with is_dir()/isDir() and no is_link() companion. PHP reports a
 * symlink pointing at a directory as a directory, and that produces TWO
 * distinct failures, both reproduced below rather than asserted from prose:
 *
 *  1. ESCAPE. The scandir()-plus-self-recursion shape calls is_dir($child),
 *     which resolves the link, then recurses into it and unlinks the contents
 *     of the link's TARGET -- a path that by construction lies outside the
 *     temporary root the harness owns. Proven by
 *     negative_control_the_self_recursing_shape_destroys_data_outside_the_root().
 *
 *  2. LEAK. The RecursiveIteratorIterator shape does not escape, because
 *     RecursiveDirectoryIterator does not follow symlinks unless
 *     FilesystemIterator::FOLLOW_SYMLINKS is set. Instead it calls rmdir() on
 *     the symlink itself, which fails with ENOTDIR, so the link is never
 *     removed and the entire temporary tree survives the run. Proven by
 *     negative_control_the_iterator_shape_leaks_the_whole_tree().
 *
 * Note this corrects #2491's own framing, which cited the iterator shape at
 * CheckPackageLayersGateTest:752-766 as the escaping one. Measured on PHP 8.5,
 * the iterator shape leaks and the self-recursing shape escapes.
 * Filesystem::remove() exhibits neither.
 *
 * Every remaining recursive remover in test scope must therefore be on the
 * allowlist below WITH a rationale. A new one fails this test.
 *
 * Files under packages/*&#47;src are deliberately out of scope: #2491 forbids
 * touching production source, and the canonical helper that lives there —
 * packages/testing/src/Filesystem/TemporaryDirectory.php — is already link-safe
 * and is pinned by its own TemporaryDirectoryTest.
 */
#[CoversNothing]
final class RecursiveRemoverContractTest extends TestCase
{
    /**
     * The only recursive tree removers permitted in test/benchmark scope, each
     * with the reason it is retained rather than converted.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'benchmarks/field-read-pages.php' =>
            'Frozen, and unconvertible. Its sha256 is pinned in '
            . 'tests/Integration/FieldReadPagePerformance/fixture-manifest.json and re-checked at benchmark '
            . 'runtime, so editing it fails that gate; and it bootstraps with two bare require statements '
            . '(lines 12-13) rather than vendor/autoload.php, so a Symfony import would not resolve. The '
            . 'remover is also unreachable by a symlink: its single call site (line 114) removes $workingRoot, '
            . 'a tree the harness itself just built by copy() (line 265), never by symlink().',
        'tests/Architecture/RecursiveRemoverContractTest.php' =>
            'This file. the_pre_conversion_shape_is_what_escapes() runs the deleted shape verbatim, on purpose, '
            . 'as the negative control that proves the escape proof still has teeth. It must stay unguarded or '
            . 'it stops demonstrating anything.',
        'tests/Architecture/WorktreeCoordinatorTest.php' =>
            'Retained deliberately: it is already link-safe AND it does something Filesystem::remove() cannot. '
            . 'It tests is_link() before descending (lines 329, 331), so it cannot walk through a directory '
            . 'symlink. It also @chmod()s each entry to 0o700 before removing it (lines 333, 337), which is '
            . 'load-bearing here: permission_residue_is_reported() chmods a committed worktree subdirectory to '
            . '0o500 (line 236) and restores it only at line 244, after the assertions at 240-242. Under '
            . 'Filesystem::remove() the r-x directory would raise IOException from tearDown() and mask whatever '
            . 'those assertions actually reported.',
        'tests/PackagedForm/skeleton/tests/Integration/PackagedKernelPathTest.php' =>
            'Cannot be converted without weakening the gate it belongs to. The skeleton is a separate '
            . 'Composer PROJECT, installed from its own composer.json, whose require-dev is phpunit alone -- '
            . 'the whole point of ci/packaged-form is to prove a consumer install of waaseyaa/core resolves '
            . 'and boots, so the root require-dev is deliberately not in scope there and '
            . 'Symfony\\Component\\Filesystem\\Filesystem does not exist at runtime. Adding it to the '
            . 'skeleton would change what that proof installs, and #2491 fences the diff to tests/, '
            . 'benchmarks/, packages/*/tests/, packages/testing/ and the ROOT require-dev. It is the '
            . 'RecursiveIteratorIterator shape, so per the negative controls above it leaks rather than '
            . 'escapes, and it only ever removes $projectRoot/storage -- a directory the fixture itself '
            . 'creates with mkdir(), never symlink().',
    ];

    #[Test]
    public function every_test_scope_recursive_remover_is_allowlisted_with_a_rationale(): void
    {
        $found = self::scanForRecursiveRemovers();

        self::assertSame(
            array_keys(self::ALLOWED),
            array_keys($found),
            "A recursive directory remover appeared or moved in test scope.\n"
            . 'Replace it with Symfony\\Component\\Filesystem\\Filesystem::remove() or the '
            . "packages/testing TemporaryDirectory helper (see #2491), or, if it genuinely cannot be\n"
            . "converted, add it to self::ALLOWED with the behavioural reason.\n"
            . 'Found: ' . json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        foreach (self::ALLOWED as $path => $rationale) {
            self::assertNotSame('', trim($rationale), "Allowlist entry {$path} must carry a rationale.");
        }
    }

    #[Test]
    public function each_retained_remover_still_satisfies_the_reason_it_was_retained(): void
    {
        // The rationales above are prose. Assert the mechanism behind each one,
        // so a retention cannot quietly stop being true.
        $root = self::repositoryRoot();

        // WorktreeCoordinatorTest is retained partly because it is link-safe.
        $worktree = (string) file_get_contents($root . '/tests/Architecture/WorktreeCoordinatorTest.php');
        self::assertMatchesRegularExpression(
            '/private function removeTree\(string \$path\): void\s*\{\s*if \(!file_exists\(\$path\) && !is_link\(\$path\)\)/',
            $worktree,
            'WorktreeCoordinatorTest::removeTree is allowlisted as link-safe but lost its is_link() guard.',
        );
        self::assertStringContainsString(
            '@chmod($path, 0o700);',
            $worktree,
            'WorktreeCoordinatorTest::removeTree is allowlisted for its chmod-before-remove behaviour, which is gone.',
        );

        // field-read-pages.php is retained because it is byte-pinned. If the pin
        // no longer matches the file, the "frozen" rationale is stale.
        $manifest = json_decode(
            (string) file_get_contents($root . '/tests/Integration/FieldReadPagePerformance/fixture-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertArrayHasKey('benchmarks/field-read-pages.php', $manifest);
        self::assertSame(
            $manifest['benchmarks/field-read-pages.php'],
            hash_file('sha256', $root . '/benchmarks/field-read-pages.php'),
            'benchmarks/field-read-pages.php is allowlisted as frozen but no longer matches its pinned hash.',
        );
        self::assertStringNotContainsString(
            'vendor/autoload.php',
            (string) file_get_contents($root . '/benchmarks/field-read-pages.php'),
            'benchmarks/field-read-pages.php now autoloads, so the "a Symfony import cannot resolve" reason is stale.',
        );
    }

    #[Test]
    public function escape_proof_a_directory_symlink_is_unlinked_without_touching_its_target(): void
    {
        // The exact hazard #2491 describes, executed. A directory that lives
        // OUTSIDE the cleanup root holds a sentinel; a directory symlink INSIDE
        // the cleanup root points at it. The pre-conversion shape
        // ($entry->isDir() ? rmdir(...) : unlink(...)) descends through the link
        // and destroys the sentinel. Filesystem::remove() must not.
        $base = self::makeTempRoot();

        $outside = $base . '/outside';
        mkdir($outside . '/nested', 0o700, true);
        file_put_contents($outside . '/SENTINEL.txt', 'must survive cleanup');
        file_put_contents($outside . '/nested/DEEP.txt', 'must also survive cleanup');

        $cleanupRoot = $base . '/cleanup-root';
        mkdir($cleanupRoot . '/subdir', 0o700, true);
        file_put_contents($cleanupRoot . '/subdir/scratch.txt', 'may be destroyed');

        $link = $cleanupRoot . '/link-to-outside';
        self::assertTrue(symlink($outside, $link), 'The probe needs a real directory symlink.');
        // Guard the premise: PHP really does report the link as a directory,
        // which is why an isDir()-only remover walks into it.
        self::assertTrue(is_dir($link), 'Premise: a directory symlink reports is_dir() === true.');
        self::assertTrue(is_link($link));

        new Filesystem()->remove($cleanupRoot);

        self::assertFalse(is_dir($cleanupRoot), 'The cleanup root itself must be gone.');
        self::assertFalse(is_link($link), 'The symlink inside the cleanup root must be unlinked.');

        self::assertDirectoryExists($outside, 'Cleanup escaped its root and removed the link target.');
        self::assertFileExists($outside . '/SENTINEL.txt', 'Cleanup destroyed a file outside the temporary root.');
        self::assertSame('must survive cleanup', file_get_contents($outside . '/SENTINEL.txt'));
        self::assertDirectoryExists($outside . '/nested');
        self::assertFileExists($outside . '/nested/DEEP.txt');

        new Filesystem()->remove($base);
    }

    #[Test]
    public function negative_control_the_self_recursing_shape_destroys_data_outside_the_root(): void
    {
        // Without this the escape proof cannot distinguish "Filesystem::remove
        // is safe" from "the probe never had a way to escape". Run the deleted
        // shape verbatim and watch it destroy the sentinel.
        $base = self::makeTempRoot();
        $outside = $base . '/outside';
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/SENTINEL.txt', 'destroyed by the old shape');

        $cleanupRoot = $base . '/cleanup-root';
        mkdir($cleanupRoot, 0o700, true);
        symlink($outside, $cleanupRoot . '/link-to-outside');

        // Verbatim the shape this change deleted from packages/cli,
        // packages/config and tests/Integration: scandir(), is_dir(), recurse.
        $remove = static function (string $dir) use (&$remove): void {
            if (!is_dir($dir)) {
                return;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir . '/' . $entry;
                is_dir($full) ? $remove($full) : @unlink($full);
            }
            @rmdir($dir);
        };
        $remove($cleanupRoot);

        self::assertFileDoesNotExist(
            $outside . '/SENTINEL.txt',
            'The self-recursing shape no longer escapes, so the escape proof has lost its teeth. '
            . 'If the platform changed, rewrite both probes rather than deleting this one.',
        );

        new Filesystem()->remove($base);
    }

    #[Test]
    public function negative_control_the_iterator_shape_leaks_the_whole_tree(): void
    {
        // The other half of the defect, and the reason "it does not escape" was
        // never good enough. RecursiveDirectoryIterator does not follow links,
        // so this shape calls rmdir() on the symlink itself; that fails with
        // ENOTDIR, the link survives, and the non-empty parent cannot be removed
        // either. Every temporary directory the harness made is left on disk.
        $base = self::makeTempRoot();
        $outside = $base . '/outside';
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/SENTINEL.txt', 'untouched by this shape');

        $cleanupRoot = $base . '/cleanup-root';
        mkdir($cleanupRoot, 0o700, true);
        symlink($outside, $cleanupRoot . '/link-to-outside');

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cleanupRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($cleanupRoot);

        self::assertFileExists($outside . '/SENTINEL.txt', 'This shape is not supposed to escape.');
        self::assertTrue(
            is_link($cleanupRoot . '/link-to-outside'),
            'The iterator shape no longer leaves the symlink behind; re-derive both negative controls.',
        );
        self::assertDirectoryExists($cleanupRoot, 'The iterator shape is recorded as leaking, but it cleaned up.');

        // And the converted call path handles what the old one could not.
        new Filesystem()->remove($cleanupRoot);
        self::assertDirectoryDoesNotExist($cleanupRoot);
        self::assertFileExists($outside . '/SENTINEL.txt');

        new Filesystem()->remove($base);
    }

    #[Test]
    public function filesystem_remove_covers_the_cases_the_hand_rolled_removers_handled(): void
    {
        $fs = new Filesystem();
        $base = self::makeTempRoot();

        // Missing path: every converted remover opened with
        // `if (!is_dir($dir)) { return; }`. remove() must be a silent no-op.
        $fs->remove($base . '/never-existed');

        // Nested tree, including a dotfile — SKIP_DOTS-based removers dropped
        // "." and ".." but still deleted dotfiles; glob('*') based ones did not.
        mkdir($base . '/tree/a/b/c', 0o700, true);
        file_put_contents($base . '/tree/a/b/c/deep.txt', 'x');
        file_put_contents($base . '/tree/.hidden', 'x');
        $fs->remove($base . '/tree');
        self::assertDirectoryDoesNotExist($base . '/tree');

        // A plain file, not a directory.
        file_put_contents($base . '/plain.txt', 'x');
        $fs->remove($base . '/plain.txt');
        self::assertFileDoesNotExist($base . '/plain.txt');

        // A symlink to a file: unlink the link, keep the target.
        file_put_contents($base . '/target.txt', 'keep me');
        symlink($base . '/target.txt', $base . '/link.txt');
        $fs->remove($base . '/link.txt');
        self::assertFileDoesNotExist($base . '/link.txt');
        self::assertFileExists($base . '/target.txt');

        // A dangling symlink: is_dir()/is_file() are both false for it, which is
        // where several hand-rolled removers silently gave up and leaked.
        symlink($base . '/gone', $base . '/dangling');
        $fs->remove($base . '/dangling');
        self::assertFalse(is_link($base . '/dangling'));

        $fs->remove($base);
        self::assertDirectoryDoesNotExist($base);
    }

    private static function makeTempRoot(): string
    {
        $base = sys_get_temp_dir() . '/waaseyaa_remover_contract_' . bin2hex(random_bytes(8));
        mkdir($base, 0o700, true);

        return $base;
    }

    /**
     * Find recursive directory removers via the PHP tokenizer.
     *
     * Deliberately shape-driven, not name-driven: it walks every function body
     * and asks whether that body removes directories AND either calls itself or
     * drives a recursive directory iterator. Renaming removeDir() to anything
     * else — or inlining it into tearDown(), which is how 75 of them were
     * written — does not evade it. Grepping for the four historical method
     * names would have missed both.
     *
     * Shallow cleanups (glob() + unlink() + a single rmdir()) are NOT reported:
     * with no recursion there is no traversal, and unlink() on a symlink always
     * removes the link rather than the target, so they cannot exhibit the
     * escape this gate exists to prevent.
     *
     * @return array<string, list<string>> repo-relative path => "method:line"
     */
    private static function scanForRecursiveRemovers(): array
    {
        $root = self::repositoryRoot();
        $roots = array_merge(
            [$root . '/tests', $root . '/benchmarks'],
            glob($root . '/packages/*/tests', GLOB_ONLYDIR) ?: [],
            glob($root . '/packages/*/testing', GLOB_ONLYDIR) ?: [],
        );

        $sites = [];
        foreach ($roots as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                // Skip installed dependencies. tests/PackagedForm/skeleton is a
                // separate Composer project; once ci/packaged-form installs it,
                // its vendor/ tree contains mirrored copies of waaseyaa
                // packages' own tests. Those are build output, not source we
                // can edit here, and whether they exist depends on whether the
                // packaged-form job has run.
                $normalised = str_replace('\\', '/', $file->getPathname());
                if (str_contains($normalised, '/vendor/')) {
                    continue;
                }
                $hits = self::recursiveRemoversIn((string) file_get_contents($file->getPathname()));
                if ($hits === []) {
                    continue;
                }
                $relative = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
                $sites[$relative] = $hits;
            }
        }

        ksort($sites);

        return $sites;
    }

    /** @return list<string> */
    private static function recursiveRemoversIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $hits = [];

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($candidate) && $candidate[0] === T_STRING) {
                    $name = $candidate[1];
                }
                break;
            }
            if ($name === null) {
                continue; // closure or arrow function
            }

            $body = self::functionBody($tokens, $index, $count);
            if ($body === null || !self::isRecursiveRemover($body, $name)) {
                continue;
            }

            $hits[] = $name . ':' . $token[2];
        }

        return $hits;
    }

    /** Source text of the function's brace block, or null for an abstract declaration. */
    private static function functionBody(array $tokens, int $start, int $count): ?string
    {
        $depth = 0;
        $open = null;
        $text = '';

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];
            $piece = is_array($token) ? $token[1] : $token;

            if ($piece === '{') {
                $depth++;
                if ($open === null) {
                    $open = $index;
                }
            } elseif ($piece === '}') {
                $depth--;
            } elseif ($piece === ';' && $open === null) {
                return null; // interface / abstract method
            }

            if ($open !== null) {
                $text .= $piece;
                if ($depth === 0) {
                    return $text;
                }
            }
        }

        return null;
    }

    private static function isRecursiveRemover(string $body, string $name): bool
    {
        // Strip comments and string literals so a path fragment or a prose
        // mention of rmdir() cannot trip the gate.
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $body) ?? $body;
        $code = preg_replace('/\'[^\']*\'|"[^"]*"/', "''", $code) ?? $code;

        if (preg_match('/(?<![\w>$])\\\\?rmdir\s*\(/', $code) !== 1) {
            return false; // removes no directories at all
        }

        $recursesIntoItself = preg_match('/(?:\$this->|self::|static::)' . preg_quote($name, '/') . '\s*\(/', $code) === 1;
        $drivesRecursiveIterator = str_contains($code, 'RecursiveDirectoryIterator');

        return $recursesIntoItself || $drivesRecursiveIterator;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
