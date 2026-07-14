<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Migrate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Migrate\OutputSanitizer;

#[CoversClass(OutputSanitizer::class)]
final class OutputSanitizerTest extends TestCase
{
    #[Test]
    public function developmentModePassesThrough(): void
    {
        $s = new OutputSanitizer(isProduction: false);

        self::assertSame(
            'In /home/jones/dev/waaseyaa/packages/foo.php on line 42',
            $s->sanitize('In /home/jones/dev/waaseyaa/packages/foo.php on line 42'),
        );
    }

    #[Test]
    public function productionStripsAbsolutePhpPaths(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame(
            'In <path> on line 42',
            $s->sanitize('In /home/jones/dev/waaseyaa/packages/foo.php on line 42'),
        );
    }

    #[Test]
    public function productionPreservesMigrationIds(): void
    {
        // Migration ids look like "package/name:v2:slug" — colons set
        // them apart from filesystem paths. Sanitizer must not touch.
        $s = new OutputSanitizer(isProduction: true);

        $message = 'Migration "waaseyaa/foundation:v2:ledger-add-checksum-columns" mismatch.';
        self::assertSame($message, $s->sanitize($message));
    }

    #[Test]
    public function productionPreservesPackageNames(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame(
            'Package waaseyaa/cli is missing.',
            $s->sanitize('Package waaseyaa/cli is missing.'),
        );
    }

    #[Test]
    public function productionPreservesUrls(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame(
            'See https://example.com/releases/latest for details.',
            $s->sanitize('See https://example.com/releases/latest for details.'),
        );
    }

    #[Test]
    public function productionStripsMultiplePathsInOneMessage(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        $input = 'See /a/b/foo.php and /c/d/bar.json for details.';
        self::assertSame('See <path> and <path> for details.', $s->sanitize($input));
    }

    #[Test]
    public function productionStripsBareUnixDirectory(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame('Cache root: <path>', $s->sanitize('Cache root: /srv/waaseyaa/storage/cache'));
    }

    #[Test]
    public function productionStripsWindowsPath(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame('Lockfile: <path>', $s->sanitize('Lockfile: C:\\sites\\waaseyaa\\composer.lock'));
    }

    #[Test]
    public function productionStripsAbsolutePathsContainingSpaces(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame(
            'Windows: <path>; Unix: <path>',
            $s->sanitize('Windows: C:\\Program Files\\Waaseyaa\\file.php; Unix: /srv/My App/cache/file.php'),
        );
    }

    #[Test]
    public function productionStripsFileUrisAndUncPaths(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame(
            'Local: <path>; share: <path>',
            $s->sanitize('Local: file:///srv/secret/file.php; share: \\\\server\\share\\secret.txt'),
        );
    }

    #[Test]
    public function productionStripsSpacedBareDirectoriesAndExtensionlessShares(): void
    {
        $s = new OutputSanitizer(isProduction: true);

        self::assertSame('<path>', $s->sanitize('/srv/My App/cache'));
        self::assertSame('<path>', $s->sanitize('C:\\Program Files\\Waaseyaa\\cache'));
        self::assertSame('<path>', $s->sanitize('file:///srv/My App/file.php'));
        self::assertSame('<path>', $s->sanitize('\\\\server\\share\\My Folder\\cache'));
    }
}
