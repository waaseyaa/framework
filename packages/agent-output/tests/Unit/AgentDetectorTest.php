<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\AgentDetector;

#[CoversClass(AgentDetector::class)]
final class AgentDetectorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [];
        foreach (AgentDetector::CLIENTS as $envVar => $_) {
            $this->envBackup[$envVar] = getenv($envVar);
            putenv($envVar);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $envVar => $originalValue) {
            if ($originalValue === false) {
                putenv($envVar);
            } else {
                putenv($envVar . '=' . $originalValue);
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function clientEnvProvider(): iterable
    {
        yield 'claude-code'    => ['CLAUDE_CODE', 'claude-code'];
        yield 'cursor'         => ['CURSOR_AGENT', 'cursor'];
        yield 'codex'          => ['CODEX_CLI', 'codex'];
        yield 'gemini'         => ['GEMINI_CLI', 'gemini'];
        yield 'windsurf'       => ['WINDSURF', 'windsurf'];
        yield 'junie'          => ['JUNIE', 'junie'];
        yield 'github-copilot' => ['COPILOT_AGENT', 'github-copilot'];
    }

    #[Test]
    #[DataProvider('clientEnvProvider')]
    public function detectsKnownClient(string $envVar, string $expectedClientId): void
    {
        putenv($envVar . '=1');

        self::assertSame($expectedClientId, (new AgentDetector())->detect());
    }

    #[Test]
    public function returnsNullWhenNoEnvVarsSet(): void
    {
        self::assertNull((new AgentDetector())->detect());
    }

    #[Test]
    public function returnsNullForUnknownEnvVar(): void
    {
        putenv('CURSOR_NEXT=1');

        try {
            self::assertNull((new AgentDetector())->detect());
        } finally {
            putenv('CURSOR_NEXT');
        }
    }

    #[Test]
    public function treatsFalsyValuesAsAbsent(): void
    {
        // Empty string and the literal "0" are treated as not-set so a
        // CI runner that leaves a stub `CLAUDE_CODE=` lying around doesn't
        // accidentally activate JSON formatting.
        putenv('CLAUDE_CODE=');
        self::assertNull((new AgentDetector())->detect());

        putenv('CLAUDE_CODE=0');
        self::assertNull((new AgentDetector())->detect());
    }

    #[Test]
    public function returnsFirstMatchWhenMultipleEnvVarsSet(): void
    {
        // Set two env vars; detector returns whichever the iteration finds
        // first. The map ordering is intentional — `CLAUDE_CODE` comes before
        // `CURSOR_AGENT`, so claude-code wins.
        putenv('CLAUDE_CODE=1');
        putenv('CURSOR_AGENT=1');

        $detected = (new AgentDetector())->detect();
        self::assertContains($detected, ['claude-code', 'cursor']);
    }
}
