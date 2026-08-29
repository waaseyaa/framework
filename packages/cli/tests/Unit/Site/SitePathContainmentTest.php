<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\SitePathContainment;

#[CoversClass(SitePathContainment::class)]
final class SitePathContainmentTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function paths(): iterable
    {
        yield 'posix child' => ['/srv/proj', '/srv/proj/tests/Acceptance', true];
        yield 'posix root itself' => ['/srv/proj', '/srv/proj', true];
        yield 'posix sibling with shared prefix' => ['/srv/proj', '/srv/projX', false];
        yield 'posix outside' => ['/srv/proj', '/srv/other/file', false];

        // The Windows shapes are the regression. realpath() returns
        // backslashes there, and the previous separator-naive prefix test
        // rejected every generated target on a Windows host.
        yield 'windows child' => ['C:\\proj', 'C:\\proj\\tests\\Acceptance', true];
        yield 'windows root itself' => ['C:\\proj', 'C:\\proj', true];
        yield 'windows sibling with shared prefix' => ['C:\\proj', 'C:\\projX', false];
        yield 'windows outside' => ['C:\\proj', 'C:\\other\\file', false];
        yield 'windows mixed separators' => ['C:\\proj', 'C:/proj/bin/maintenance', true];
        yield 'windows trailing separator on root' => ['C:\\proj\\', 'C:\\proj\\bin', true];
    }

    #[Test]
    #[DataProvider('paths')]
    public function containmentIsSeparatorAgnostic(string $root, string $path, bool $expected): void
    {
        self::assertSame($expected, SitePathContainment::contains($root, $path));
    }
}
