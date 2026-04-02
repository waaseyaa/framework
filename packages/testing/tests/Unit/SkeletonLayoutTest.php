<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkeletonLayoutTest extends TestCase
{
    #[Test]
    public function skeletonPhpUnitDirectoriesExist(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $config = simplexml_load_file($repoRoot . '/skeleton/phpunit.xml.dist');

        self::assertInstanceOf(\SimpleXMLElement::class, $config);

        $directories = $config->xpath('//testsuite/directory');

        self::assertIsArray($directories);
        self::assertNotEmpty($directories);

        foreach ($directories as $directory) {
            $path = $repoRoot . '/skeleton/' . trim((string) $directory);
            self::assertDirectoryExists($path, sprintf('Skeleton PHPUnit path missing: %s', $path));
        }
    }
}
