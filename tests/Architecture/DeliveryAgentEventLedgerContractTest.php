<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DeliveryAgentEventLedgerContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function governed_ledger_conforms_to_its_executable_schema_and_causal_rules(): void
    {
        $process = new Process([PHP_BINARY, $this->root . '/bin/check-delivery-agent-events']);

        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertStringContainsString('PASS', $process->getOutput());
    }

    #[Test]
    public function hostile_self_test_proves_schema_causality_adjudication_and_history_refusals(): void
    {
        $process = new Process([PHP_BINARY, $this->root . '/bin/check-delivery-agent-events', '--self-test']);

        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertStringContainsString('self-test: PASS', $process->getOutput());
    }

    #[Test]
    public function cli_refuses_a_schema_valid_rewrite_of_prior_history(): void
    {
        $prior = tempnam(sys_get_temp_dir(), 'agent-ledger-prior-');
        $rewritten = tempnam(sys_get_temp_dir(), 'agent-ledger-rewritten-');
        self::assertNotFalse($prior);
        self::assertNotFalse($rewritten);
        try {
            $ledger = (string) file_get_contents($this->root . '/ops/observability/delivery-agent-events-v1.jsonl');
            file_put_contents($prior, $ledger);
            $lines = explode("\n", rtrim($ledger, "\n"));
            [$lines[0], $lines[1]] = [$lines[1], $lines[0]];
            file_put_contents($rewritten, implode("\n", $lines) . "\n");
            $process = new Process([
                PHP_BINARY,
                $this->root . '/bin/check-delivery-agent-events',
                '--prior-ledger=' . $prior,
                '--ledger=' . $rewritten,
            ]);

            self::assertSame(1, $process->run());
            self::assertStringContainsString('not an exact byte-prefix extension', $process->getErrorOutput());
        } finally {
            @unlink($prior);
            @unlink($rewritten);
        }
    }

    #[Test]
    public function hosted_ci_supplies_history_and_an_explicit_base_to_the_gate(): void
    {
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        preg_match('/^  verify-gates:.*?(?=^  [a-z][a-z0-9-]*:)/ms', $workflow, $matches);
        self::assertArrayHasKey(0, $matches, 'ci.yml must contain the verify-gates job.');
        $verifyGates = $matches[0];

        self::assertStringContainsString('fetch-depth: 0', $verifyGates);
        self::assertStringContainsString('check-delivery-agent-events --base=', $verifyGates);
        self::assertStringNotContainsString('run_gate check-delivery-agent-events', $verifyGates);
    }

    #[Test]
    public function misspelled_base_option_cannot_silently_disable_custody_enforcement(): void
    {
        $process = new Process([PHP_BINARY, $this->root . '/bin/check-delivery-agent-events', '--bsae=HEAD']);

        self::assertSame(2, $process->run());
        self::assertStringContainsString('unknown or malformed option', $process->getErrorOutput());
    }

    #[Test]
    public function all_zero_base_cannot_silently_disable_custody_enforcement(): void
    {
        $process = new Process([
            PHP_BINARY,
            $this->root . '/bin/check-delivery-agent-events',
            '--base=' . str_repeat('0', 40),
        ]);

        self::assertSame(2, $process->run());
        self::assertStringContainsString('not a custody boundary', $process->getErrorOutput());
    }

    #[Test]
    public function missing_ledger_paths_fail_closed(): void
    {
        $missing = sys_get_temp_dir() . '/missing-agent-ledger-' . bin2hex(random_bytes(6));
        $process = new Process([
            PHP_BINARY,
            $this->root . '/bin/check-delivery-agent-events',
            '--ledger=' . $missing,
            '--prior-ledger=' . $missing . '-prior',
        ]);

        self::assertSame(2, $process->run());
        self::assertStringContainsString('missing or unreadable', $process->getErrorOutput());
    }

    #[Test]
    public function published_v1_schema_cannot_be_widened_in_place(): void
    {
        $prior = tempnam(sys_get_temp_dir(), 'agent-schema-prior-');
        $widened = tempnam(sys_get_temp_dir(), 'agent-schema-widened-');
        self::assertNotFalse($prior);
        self::assertNotFalse($widened);
        try {
            $schema = (string) file_get_contents($this->root . '/ops/observability/delivery-agent-event-v1.schema.json');
            file_put_contents($prior, $schema);
            $document = json_decode($schema, true, flags: JSON_THROW_ON_ERROR);
            $document['properties']['event_type']['enum'][] = 'silently_widened';
            file_put_contents($widened, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $process = new Process([
                PHP_BINARY,
                $this->root . '/bin/check-delivery-agent-events',
                '--schema=' . $widened,
                '--prior-schema=' . $prior,
            ]);

            self::assertSame(1, $process->run());
            self::assertStringContainsString('published v1 schema is immutable', $process->getErrorOutput());
        } finally {
            @unlink($prior);
            @unlink($widened);
        }
    }

    #[Test]
    public function ledger_line_endings_are_bound_to_lf_for_byte_prefix_comparison(): void
    {
        $attributes = (string) file_get_contents($this->root . '/.gitattributes');

        self::assertMatchesRegularExpression('/^\*\.jsonl\s+text\s+eol=lf$/m', $attributes);
        $ledger = (string) file_get_contents($this->root . '/ops/observability/delivery-agent-events-v1.jsonl');
        self::assertStringNotContainsString("\r", $ledger);
        self::assertStringEndsWith("\n", $ledger);
    }
}
