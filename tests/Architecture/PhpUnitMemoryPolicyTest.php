<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpUnitMemoryPolicyTest extends TestCase
{
    private const MEMORY_LIMIT = '1G';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function canonical_configuration_owns_the_test_memory_ceiling(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load($this->root . '/phpunit.xml.dist'));

        $limits = new DOMXPath($document)->query('/phpunit/php/ini[@name="memory_limit"]');
        self::assertNotFalse($limits);
        self::assertCount(1, $limits, 'The canonical PHPUnit configuration must declare exactly one memory ceiling.');
        self::assertSame(self::MEMORY_LIMIT, $limits->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    #[Test]
    public function focused_and_full_suites_receive_the_canonical_ceiling(): void
    {
        self::assertSame(self::MEMORY_LIMIT, ini_get('memory_limit'));
    }

    #[Test]
    public function normal_entrypoints_defer_to_the_canonical_configuration(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $testScript = implode("\n", (array) ($composer['scripts']['test'] ?? []));
        self::assertStringContainsString('vendor/bin/phpunit', $testScript);
        self::assertStringNotContainsString('memory_limit', $testScript);

        $workflow = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertStringNotContainsString(
            'memory_limit=',
            $workflow,
            'Normal CI invocations must consume phpunit.xml.dist instead of duplicating its policy.',
        );
    }

    #[Test]
    public function subprocess_launcher_preserves_the_ceiling_explicitly(): void
    {
        $runner = (string) file_get_contents($this->root . '/bin/test-random-order');

        self::assertStringContainsString("PHP_BINARY, '-d', 'memory_limit=1G'", $runner);
    }
}
