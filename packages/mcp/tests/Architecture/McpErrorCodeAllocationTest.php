<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\McpProtocol;

/**
 * #2561: no code this server emits may violate the MCP 2026-07-28 error-code
 * policy.
 *
 * The nine violations that issue reported were each individually defensible —
 * a plausible number in a range that looked free — which is exactly why a
 * per-callsite review does not hold the line. This scans the shipped source
 * instead, so a new literal in the reserved band fails here rather than on a
 * consumer's wire.
 *
 * Scope and its limit: this reads integer literals out of `packages/mcp/src`.
 * A code assembled at runtime would not be seen. That is acceptable because the
 * policy is about *allocation* — choosing a number — and numbers are chosen in
 * source. {@see McpErrorCode} is the one file excluded: it declares the policy,
 * including the forbidden numbers it names.
 */
#[CoversNothing]
final class McpErrorCodeAllocationTest extends TestCase
{
    #[Test]
    public function no_shipped_source_emits_a_code_the_current_protocol_forbids(): void
    {
        $offenders = [];

        foreach (self::shippedSources() as $file => $contents) {
            foreach (self::negativeIntegerLiterals($contents) as [$code, $line]) {
                if (McpErrorCode::isEmittable($code) && !McpErrorCode::isLegacySubRange($code)) {
                    continue;
                }
                if (isset(McpErrorCode::LEGACY_IN_USE[$code])) {
                    // SHOULD NOT, and recorded with a rationale. A NEW legacy
                    // allocation is not on that list and still fails.
                    continue;
                }
                $offenders[] = sprintf(
                    '%s:%d  %d  %s',
                    $file,
                    $line,
                    $code,
                    McpErrorCode::RETIRED[$code]
                        ?? (McpErrorCode::isLegacySubRange($code)
                            ? 'inside -32019..-32000 (legacy; new implementations SHOULD NOT use it) '
                                . 'and not recorded in McpErrorCode::LEGACY_IN_USE'
                            : 'inside -32099..-32020 (reserved for the MCP specification) and not spec-defined'),
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "A JSON-RPC error code forbidden to an MCP %s server appears in packages/mcp/src.\n"
                . "Allocate it outside the JSON-RPC reserved range (-32768..-32000) as a constant on "
                . "McpErrorCode, or use one of the codes the specification defines.\n\n%s",
                McpProtocol::CURRENT,
                implode("\n", $offenders),
            ),
        );
    }

    /**
     * The exact nine violations #2561 reproduced over real HTTP must all be
     * rejected by the policy predicate, so a regression cannot re-introduce one
     * under a different name.
     */
    #[Test]
    public function every_code_the_issue_reproduced_is_refused_by_the_policy(): void
    {
        foreach ([-32029, -32030, -32040, -32041, -32042, -32043, -32002] as $code) {
            self::assertFalse(
                McpErrorCode::isEmittable($code),
                sprintf('%d was reported on the wire in #2561 and must stay forbidden.', $code),
            );
        }
    }

    #[Test]
    public function the_policy_admits_the_codes_the_specification_defines(): void
    {
        // The three MCP-defined codes, the JSON-RPC standard codes, and this
        // package's own out-of-range allocations.
        foreach ([-32020, -32021, -32022, -32700, -32600, -32601, -32602] as $code) {
            self::assertTrue(McpErrorCode::isEmittable($code), sprintf('%d must remain emittable.', $code));
        }

        foreach (self::ownAllocations() as $name => $code) {
            self::assertTrue(
                McpErrorCode::isEmittable($code),
                sprintf('McpErrorCode::%s (%d) must satisfy the policy it is declared under.', $name, $code),
            );
            self::assertGreaterThan(
                McpErrorCode::JSON_RPC_RESERVED_HIGH,
                $code,
                sprintf('McpErrorCode::%s must sit outside the JSON-RPC reserved range.', $name),
            );
        }
    }

    /**
     * The legacy sub-range is SHOULD NOT, so it is recorded rather than
     * enforced — but only for the codes already on the wire, each with a
     * rationale. A new one must not be able to slip in behind them.
     */
    #[Test]
    public function the_legacy_sub_range_is_closed_to_new_allocations(): void
    {
        foreach ([-32000, -32001, -32003, -32010, -32019] as $code) {
            self::assertTrue(
                McpErrorCode::isLegacySubRange($code),
                sprintf('%d is inside -32019..-32000 and must be reported as legacy.', $code),
            );
        }
        self::assertFalse(McpErrorCode::isLegacySubRange(-32020));
        self::assertFalse(McpErrorCode::isLegacySubRange(-31001));

        self::assertSame(
            [-32001, -32003, -32004],
            array_keys(McpErrorCode::LEGACY_IN_USE),
            'Adding a legacy code to the allowlist is a decision, not a formality: '
            . 'the specification says new implementations SHOULD NOT use this sub-range.',
        );
        foreach (McpErrorCode::LEGACY_IN_USE as $code => $rationale) {
            self::assertNotSame('', trim($rationale), sprintf('%d must carry a rationale.', $code));
        }
    }

    /** @return array<string, int> */
    private static function ownAllocations(): array
    {
        $allocations = [];
        foreach (new \ReflectionClass(McpErrorCode::class)->getConstants() as $name => $value) {
            // Skip the range bounds; they describe the policy, they are not
            // codes this server emits.
            if (is_int($value) && !str_contains($name, 'RESERVED') && !str_contains($name, 'LEGACY')) {
                $allocations[$name] = $value;
            }
        }

        self::assertNotSame([], $allocations);

        return $allocations;
    }

    /**
     * Every negative integer literal in `$source`, with its line.
     *
     * Tokenized rather than grepped, so a code named in a docblock — this
     * package documents the numbers it retired — is prose, not an allocation.
     *
     * @return list<array{int, int}>
     */
    private static function negativeIntegerLiterals(string $source): array
    {
        $literals = [];
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_LNUMBER)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0 && $tokens[$j]->isIgnorable(); $j--) {
                // Skip whitespace and comments between the sign and the digits.
            }
            if ($j < 0 || $tokens[$j]->text !== '-') {
                continue;
            }
            $literals[] = [-1 * (int) $tokens[$i]->text, $tokens[$i]->line];
        }

        return $literals;
    }

    /** @return array<string, string> */
    private static function shippedSources(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $sources = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '/McpErrorCode.php')) {
                continue;
            }
            $sources['packages/mcp/src' . substr($path, strlen(str_replace('\\', '/', $root)))]
                = (string) file_get_contents($path);
        }

        self::assertNotSame([], $sources, 'The source scan found no files, so it proves nothing.');
        ksort($sources);

        return $sources;
    }
}
