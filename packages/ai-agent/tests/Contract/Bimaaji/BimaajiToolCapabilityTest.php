<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

/**
 * Cross-tool capability-gating contract test.
 *
 * Asserts the `#[AsAgentTool]` capability declaration matches the actual
 * runtime gate for every read tool shipped in this WP. The pair is the
 * canonical source of truth for the capability strings M3 will reuse.
 *
 * Marked `#[CoversNothing]` — this is a cross-tool gate, not coverage
 * for any single class.
 */
#[CoversNothing]
final class BimaajiToolCapabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: array<string, mixed>}>
     */
    public static function readToolProvider(): iterable
    {
        yield 'IntrospectGraphTool'   => [IntrospectGraphTool::class,   'bimaaji.read', []];
        yield 'IntrospectSectionTool' => [IntrospectSectionTool::class, 'bimaaji.read', ['section' => 'routing']];
    }

    /**
     * @param class-string         $toolClass
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readToolProvider')]
    public function attributeAdvertisesExpectedCapability(string $toolClass, string $expectedCapability, array $arguments): void
    {
        $attributes = (new ReflectionClass($toolClass))->getAttributes(AsAgentTool::class);
        self::assertCount(1, $attributes, $toolClass . ' must carry exactly one #[AsAgentTool] attribute.');

        $attribute = $attributes[0]->newInstance();
        self::assertSame($expectedCapability, $attribute->capability);
        self::assertFalse($attribute->destructive, 'Read tools must not be marked destructive.');
    }

    /**
     * @param class-string         $toolClass
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readToolProvider')]
    public function executeRejectsAccountWithoutDeclaredCapability(string $toolClass, string $expectedCapability, array $arguments): void
    {
        $generator = new ApplicationGraphGenerator(providers: $this->stubProviders());
        $tool = new $toolClass($generator);

        $result = $tool->execute($arguments, $this->accountWithPermission('not.' . $expectedCapability));

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    /**
     * @param class-string         $toolClass
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readToolProvider')]
    public function executeAcceptsAccountWithDeclaredCapability(string $toolClass, string $expectedCapability, array $arguments): void
    {
        $generator = new ApplicationGraphGenerator(providers: $this->stubProviders());
        $tool = new $toolClass($generator);

        $result = $tool->execute($arguments, $this->accountWithPermission($expectedCapability));

        self::assertFalse($result->isError);
    }

    /** @return list<GraphSectionProviderInterface> */
    private function stubProviders(): array
    {
        $sections = [
            'admin'          => ['entity_types' => []],
            'entities'       => ['entity_types' => []],
            'jsonapi'        => ['routes' => []],
            'public_surface' => ['routes' => []],
            'routing'        => ['routes' => []],
            'sovereignty'    => ['profile' => 'local'],
        ];

        $providers = [];
        foreach ($sections as $key => $data) {
            $providers[] = new class ($key, $data) implements GraphSectionProviderInterface {
                /** @param array<string, mixed> $data */
                public function __construct(
                    private readonly string $key,
                    private readonly array $data,
                ) {}

                public function getKey(): string
                {
                    return $this->key;
                }

                public function provide(): GraphSection
                {
                    return new GraphSection(key: $this->key, version: '1.0', data: $this->data);
                }
            };
        }

        return $providers;
    }

    private function accountWithPermission(string $permission): AccountInterface
    {
        return new class ($permission) implements AccountInterface {
            public function __construct(private readonly string $grantedPermission) {}

            public function id(): int
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === $this->grantedPermission;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
