<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Tools\AgentToolContext;
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
        $result = $tool->execute(['section' => 'routing'], $this->contextWithPermission('bimaaji.read'));

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
        $result = $tool->execute(['section' => 'nonexistent'], $this->contextWithPermission('bimaaji.read'));

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
        $result = $tool->execute([], $this->contextWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsNonStringSection(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['section' => 42], $this->contextWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsAccountWithoutCapability(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(
            ['section' => 'routing'],
            $this->contextWithPermission('not.bimaaji.read'),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
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

    private function contextWithPermission(string $permission): AgentToolContext
    {
        return new AgentToolContext(
            account: $this->accountWithPermission($permission),
            entityAccessHandler: new EntityAccessHandler(),
            agentRunId: null,
        );
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
