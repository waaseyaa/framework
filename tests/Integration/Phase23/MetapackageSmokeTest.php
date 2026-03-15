<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase23;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for metapackages: cms, core, full.
 *
 * Verifies that one representative class from each declared dependency
 * is reachable via the autoloader. Catches broken autoloader config,
 * missing package splits, and namespace regressions.
 */
#[CoversNothing]
final class MetapackageSmokeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function coreClasses(): array
    {
        return [
            'foundation kernel' => [\Waaseyaa\Foundation\Kernel\AbstractKernel::class],
            'entity type'       => [\Waaseyaa\Entity\EntityType::class],
            'access result'     => [\Waaseyaa\Access\AccessResult::class],
            'user entity'       => [\Waaseyaa\User\User::class],
            'route builder'     => [\Waaseyaa\Routing\RouteBuilder::class],
        ];
    }

    /** @return array<string, array{string}> */
    public static function cmsClasses(): array
    {
        return [
            'node'                => [\Waaseyaa\Node\Node::class],
            'taxonomy term'       => [\Waaseyaa\Taxonomy\Term::class],
            'media'               => [\Waaseyaa\Media\Media::class],
            'json api controller' => [\Waaseyaa\Api\JsonApiController::class],
        ];
    }

    /** @return array<string, array{string}> */
    public static function fullClasses(): array
    {
        return [
            'ai schema registry' => [\Waaseyaa\AI\Schema\SchemaRegistry::class],
            'mcp endpoint'       => [\Waaseyaa\Mcp\McpEndpoint::class],
        ];
    }

    #[Test]
    #[DataProvider('coreClasses')]
    public function core_metapackage_dependencies_are_autoloadable(string $class): void
    {
        $this->assertTrue(
            class_exists($class),
            "Class {$class} (waaseyaa/core dependency) not found in autoloader.",
        );
    }

    #[Test]
    #[DataProvider('cmsClasses')]
    public function cms_metapackage_dependencies_are_autoloadable(string $class): void
    {
        $this->assertTrue(
            class_exists($class),
            "Class {$class} (waaseyaa/cms dependency) not found in autoloader.",
        );
    }

    #[Test]
    #[DataProvider('fullClasses')]
    public function full_metapackage_dependencies_are_autoloadable(string $class): void
    {
        $this->assertTrue(
            class_exists($class),
            "Class {$class} (waaseyaa/full dependency) not found in autoloader.",
        );
    }
}
