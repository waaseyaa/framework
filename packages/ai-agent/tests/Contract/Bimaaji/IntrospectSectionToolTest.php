<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

#[CoversClass(IntrospectSectionTool::class)]
final class IntrospectSectionToolTest extends TestCase
{
    #[Test]
    public function returnsSingleSectionPayloadOnSuccess(): void // FR-002
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['section' => 'routing'], $this->accountWithPermission('bimaaji.read'));

        self::assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('routing', $data['key']);
        self::assertSame('1.0', $data['version']);
        self::assertSame(['routes' => []], $data['data']);
    }

    #[Test]
    public function reportsUnknownSectionWithAvailableList(): void // FR-002 error path
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['section' => 'nonexistent'], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        $message = $result->content[0]['text'] ?? '';
        self::assertStringContainsString('Unknown section "nonexistent"', $message);
        self::assertStringContainsString('Available sections:', $message);
        self::assertStringContainsString('routing', $message);
        self::assertStringContainsString('entities', $message);
    }

    #[Test]
    public function rejectsMissingSectionArgument(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsNonStringSection(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['section' => 42], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsAccountWithoutCapability(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(
            ['section' => 'routing'],
            $this->accountWithPermission('not.bimaaji.read'),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function sanitizesGeneratorFailure(): void
    {
        $failingProvider = new class implements GraphSectionProviderInterface {
            public function getKey(): string
            {
                return 'routing';
            }

            public function provide(): GraphSection
            {
                throw new \RuntimeException('token=do-not-leak /srv/private/routes');
            }
        };
        $tool = new IntrospectSectionTool(new ApplicationGraphGenerator(providers: [$failingProvider], strict: true));

        $result = $tool->execute(['section' => 'routing'], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        $message = $result->content[0]['text'] ?? '';
        self::assertStringContainsString('INTERNAL_ERROR', $message);
        self::assertStringContainsString('correlation_id', $message);
        self::assertStringNotContainsString('RuntimeException', $message);
        self::assertStringNotContainsString('do-not-leak', $message);
        self::assertStringNotContainsString('/srv/private/routes', $message);
    }

    #[Test]
    public function inputSchemaEnumeratesSixSectionKeys(): void
    {
        $schema = $this->makeTool()->inputSchema();

        self::assertSame(['section'], $schema['required']);
        $enum = $schema['properties']['section']['enum'];
        sort($enum);
        self::assertSame(
            ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
            $enum,
        );
    }

    private function makeTool(): IntrospectSectionTool
    {
        $providers = [
            $this->stubProvider('admin', ['entity_types' => []]),
            $this->stubProvider('entities', ['entity_types' => []]),
            $this->stubProvider('jsonapi', ['routes' => []]),
            $this->stubProvider('public_surface', ['routes' => []]),
            $this->stubProvider('routing', ['routes' => []]),
            $this->stubProvider('sovereignty', ['profile' => 'local']),
        ];

        return new IntrospectSectionTool(new ApplicationGraphGenerator(providers: $providers));
    }

    /** @param array<string, mixed> $data */
    private function stubProvider(string $key, array $data): GraphSectionProviderInterface
    {
        return new class ($key, $data) implements GraphSectionProviderInterface {
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
