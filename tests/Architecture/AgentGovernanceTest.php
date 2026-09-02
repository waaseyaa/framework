<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentGovernanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_agent_harness_points_to_the_shared_contract(): void
    {
        foreach (['AGENTS.md', 'CLAUDE.md'] as $path) {
            $contents = $this->read($path);
            self::assertStringContainsString(
                'docs/governance/agent-contract.md',
                $contents,
                "$path must delegate shared operating rules to the cross-agent contract.",
            );
        }

        foreach (glob($this->root . '/.claude/rules/*.md') ?: [] as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString(
                'docs/governance/agent-contract.md',
                $contents,
                basename($path) . ' must declare the shared contract as higher authority.',
            );
        }
    }

    #[Test]
    public function shared_rules_do_not_hide_their_provenance_or_hard_code_forge_policy_counts(): void
    {
        $paths = [
            'AGENTS.md',
            'CLAUDE.md',
            ...array_map(
                fn(string $path): string => substr($path, strlen($this->root) + 1),
                glob($this->root . '/.claude/rules/*.md') ?: [],
            ),
        ];

        foreach ($paths as $path) {
            $contents = $this->read($path);
            self::assertStringNotContainsString('Do not cite this file', $contents, "$path hides applicable guidance.");
            self::assertDoesNotMatchRegularExpression(
                '/all\s+(?:the\s+)?(?:five|six|seven|eight|nine|ten|\d+)\s+required checks/i',
                $contents,
                "$path hard-codes a volatile GitHub ruleset count.",
            );
        }
    }

    #[Test]
    public function consumer_rule_source_and_skeleton_mirror_are_identical(): void
    {
        $sourceDir = $this->root . '/packages/foundation/.claude/rules';
        $mirrorDir = $this->root . '/skeleton/.claude/rules';
        $sourceFiles = array_map('basename', glob($sourceDir . '/waaseyaa-*.md') ?: []);
        $mirrorFiles = array_map('basename', glob($mirrorDir . '/waaseyaa-*.md') ?: []);
        sort($sourceFiles);
        sort($mirrorFiles);

        self::assertSame($sourceFiles, $mirrorFiles, 'The skeleton must mirror every distributed Waaseyaa rule.');
        foreach ($sourceFiles as $file) {
            self::assertSame(
                $this->read('packages/foundation/.claude/rules/' . $file),
                $this->read('skeleton/.claude/rules/' . $file),
                "$file drifted from the Foundation distribution source.",
            );
        }
    }

    #[Test]
    public function distributed_framework_rule_uses_current_nonvolatile_invariants(): void
    {
        $contents = $this->read('packages/foundation/.claude/rules/waaseyaa-framework.md');

        self::assertStringContainsString('PHP 8.5+', $contents);
        self::assertStringNotContainsString('PHP 8.4+', $contents);
        self::assertStringNotContainsString('SqlEntityStorage', $contents);
        self::assertStringNotContainsString('7-Layer Architecture', $contents);
    }

    #[Test]
    public function spec_reviewed_trailer_guidance_matches_the_drift_detector(): void
    {
        $contract = $this->read('docs/governance/agent-contract.md');

        self::assertStringNotContainsString(
            'otherwise use the supported `spec-reviewed:` commit',
            $contract,
            'agent-contract.md must not imply the trailer is a fallback for every unchanged spec.',
        );
        self::assertStringContainsString(
            'no trailer is required or accepted',
            $contract,
            'agent-contract.md must state that a change set affecting no specs takes no spec-reviewed trailer.',
        );
        self::assertStringContainsString(
            'spec-reviewed: docs/specs/<name>.md - <reason>',
            $contract,
            'agent-contract.md must document the exact accepted spec-reviewed grammar.',
        );

        $claude = $this->read('CLAUDE.md');

        self::assertStringNotContainsString(
            'After design review, carry a `spec-reviewed:` trailer on the commit (lowercase',
            $claude,
            'CLAUDE.md must not imply a spec-reviewed trailer is always available.',
        );
        self::assertStringContainsString(
            'spec-reviewed: docs/specs/<name>.md - <reason>',
            $claude,
            'CLAUDE.md must document the exact accepted spec-reviewed grammar.',
        );
        self::assertStringContainsString(
            'no trailer is required or accepted',
            $claude,
            'CLAUDE.md must state that a change set affecting no specs takes no spec-reviewed trailer.',
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, "$path must be readable.");

        return $contents;
    }
}
