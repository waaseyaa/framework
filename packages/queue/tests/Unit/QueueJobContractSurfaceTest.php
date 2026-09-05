<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\QueueInterface;

/**
 * Consumer-facing job contract: applications subclass {@see Job}.
 * There is no queue JobInterface; README key classes must name loadable types.
 */
#[CoversNothing]
final class QueueJobContractSurfaceTest extends TestCase
{
    /**
     * Monorepo root. Dispositions are composed from package-local declarations
     * (docs/specs/public-surface-declarations.md), never the generated aggregate.
     */
    private const MONOREPO_ROOT = __DIR__ . '/../../../..';

    #[Test]
    public function readmeKeyClassesNameOnlyLoadableTypes(): void
    {
        $readme = file_get_contents(self::MONOREPO_ROOT . '/packages/queue/README.md');
        self::assertIsString($readme);
        self::assertSame(1, preg_match('/^Key classes: (.+)$/m', $readme, $match));

        preg_match_all('/`([^`]+)`/', $match[1], $names);
        self::assertNotSame([], $names[1], 'README must list at least one key class.');

        foreach ($names[1] as $name) {
            $fqcn = str_contains($name, '\\') ? $name : 'Waaseyaa\\Queue\\' . $name;
            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn),
                "README key class `{$name}` does not exist as {$fqcn}.",
            );
        }
    }

    #[Test]
    public function applicationJobsSubclassThePublicJobBaseNotAMissingInterface(): void
    {
        self::assertFalse(
            interface_exists('Waaseyaa\\Queue\\JobInterface'),
            'Do not invent JobInterface; applications subclass Job.',
        );
        self::assertTrue(class_exists(Job::class));
        self::assertTrue((new \ReflectionClass(Job::class))->isAbstract());
        self::assertTrue(method_exists(Job::class, 'handle'));

        $map = $this->surfaceMap();
        self::assertSame('public', $map[Job::class] ?? null);
        self::assertSame('public', $map[QueueInterface::class] ?? null);
    }

    /** @return array<string, string> */
    private function surfaceMap(): array
    {
        /** @var array<string, string> $map */
        $map = require self::MONOREPO_ROOT . '/tools/lib/compose-public-surface.php';

        return $map;
    }
}
