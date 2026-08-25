<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool;
use Waaseyaa\Bimaaji\Spec\SpecIndexProvider;

#[CoversClass(SearchSpecsTool::class)]
final class SearchSpecsToolTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_search_specs_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $files = glob($this->tempDir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function findsMatchesAcrossSpecFilesWithNearestSection(): void // FR-005
    {
        file_put_contents(
            $this->tempDir . '/entity-system.md',
            "# Entity System\n\n## Storage\n\nEntities persist via SqlEntityStorage.\n",
        );
        file_put_contents(
            $this->tempDir . '/access-control.md',
            "# Access Control\n\n### Policies\n\nAccessPolicyInterface gates entities.\n",
        );

        $tool = $this->makeTool();
        $result = $tool->execute(['query' => 'entities'], $this->accountWithPermission('bimaaji.read'));

        self::assertFalse($result->isError);
        $matches = $result->structuredContent['matches'] ?? [];
        self::assertIsArray($matches);
        self::assertCount(2, $matches);

        $sections = array_column($matches, 'section_title');
        self::assertContains('Storage', $sections);
        self::assertContains('Policies', $sections);

        foreach ($matches as $match) {
            self::assertGreaterThan(0, $match['line_number']);
            self::assertNotEmpty($match['snippet']);
            self::assertContains($match['file'], ['entity-system.md', 'access-control.md']);
            self::assertStringNotContainsString($this->tempDir, $match['file']);
        }
    }

    #[Test]
    public function rejectsMissingQueryArgument(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsNonStringQuery(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['query' => 42], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsEmptyStringQuery(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['query' => ''], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function rejectsOversizedOrControlCharacterQuery(): void
    {
        $tool = $this->makeTool();
        $account = $this->accountWithPermission('bimaaji.read');

        foreach ([str_repeat('x', 257), "line\nbreak"] as $query) {
            $result = $tool->execute(['query' => $query], $account);
            self::assertTrue($result->isError);
            self::assertSame('invalid argument', $result->summary);
        }
    }

    #[Test]
    public function rejectsInvalidLimit(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['query' => 'x', 'limit' => 0], $this->accountWithPermission('bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('invalid argument', $result->summary);
    }

    #[Test]
    public function rejectsAccountWithoutCapability(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['query' => 'x'], $this->accountWithPermission('not.bimaaji.read'));

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function returnsZeroMatchesForUnknownQuery(): void
    {
        file_put_contents($this->tempDir . '/dummy.md', "# Dummy\n");

        $tool = $this->makeTool();
        $result = $tool->execute(['query' => 'zzzzz-nonsense'], $this->accountWithPermission('bimaaji.read'));

        self::assertFalse($result->isError);
        self::assertSame([], $result->structuredContent['matches']);
        self::assertStringContainsString('0 matches', $result->summary ?? '');
    }

    #[Test]
    public function respectsLimitCap(): void
    {
        // Five matching lines in one file; limit=2 caps the result.
        $body = "# Title\n\n## Section\n";
        for ($i = 0; $i < 5; $i++) {
            $body .= "marker line {$i}\n";
        }
        file_put_contents($this->tempDir . '/many.md', $body);

        $tool = $this->makeTool();
        $result = $tool->execute(
            ['query' => 'marker', 'limit' => 2],
            $this->accountWithPermission('bimaaji.read'),
        );

        self::assertFalse($result->isError);
        self::assertCount(2, $result->structuredContent['matches']);
    }

    private function makeTool(): SearchSpecsTool
    {
        return new SearchSpecsTool(new SpecIndexProvider($this->tempDir));
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
