<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

#[CoversClass(IntrospectGraphTool::class)]
final class IntrospectGraphToolTest extends TestCase
{
    #[Test]
    public function returnsFullGraphPayloadOnSuccess(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('bimaaji.read'));

        self::assertFalse($result->isError);

        $content = $result->content;
        self::assertNotEmpty($content);
        self::assertSame('json', $content[0]['type']);
        $data = $content[0]['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('version', $data);
        self::assertArrayHasKey('sections', $data);
        self::assertSame(
            ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
            $this->sortedKeys($data['sections']),
            'IntrospectGraphTool must return all six default sections in the AD-03 payload.',
        );
    }

    #[Test]
    public function envelopeIsJsonSerializable(): void // NFR-003
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('bimaaji.read'));

        $payload = $result->content[0]['data'] ?? null;
        self::assertIsArray($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded, 'Envelope payload must JSON round-trip cleanly.');
    }

    #[Test]
    public function rejectsAccountWithoutCapability(): void // FR-006 / FR-010
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('something.else'));

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function surfacesGeneratorFailureAsErrorEnvelope(): void
    {
        $failingProvider = new class implements GraphSectionProviderInterface {
            public function getKey(): string
            {
                return 'routing';
            }

            public function provide(): GraphSection
            {
                throw new \RuntimeException('password=do-not-leak /srv/private/specs');
            }
        };
        $generator = new ApplicationGraphGenerator(providers: [$failingProvider], strict: true);
        $tool = new IntrospectGraphTool($generator);

        $result = $tool->execute([], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        $message = $result->content[0]['text'] ?? '';
        self::assertStringContainsString('INTERNAL_ERROR', $message);
        self::assertStringContainsString('correlation_id', $message);
        self::assertStringNotContainsString('RuntimeException', $message);
        self::assertStringNotContainsString('do-not-leak', $message);
        self::assertStringNotContainsString('/srv/private/specs', $message);
    }

    #[Test]
    public function inputSchemaIsObjectWithNoProperties(): void
    {
        $schema = $this->makeTool()->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['properties']);
        self::assertFalse($schema['additionalProperties']);
    }

    private function makeTool(): IntrospectGraphTool
    {
        $providers = [
            $this->stubProvider('admin', ['entity_types' => []]),
            $this->stubProvider('entities', ['entity_types' => []]),
            $this->stubProvider('jsonapi', ['routes' => []]),
            $this->stubProvider('public_surface', ['routes' => []]),
            $this->stubProvider('routing', ['routes' => []]),
            $this->stubProvider('sovereignty', ['profile' => 'local']),
        ];

        return new IntrospectGraphTool(new ApplicationGraphGenerator(providers: $providers));
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

    /** @param array<string, mixed> $sections @return list<string> */
    private function sortedKeys(array $sections): array
    {
        $keys = array_keys($sections);
        sort($keys);

        return $keys;
    }
}
