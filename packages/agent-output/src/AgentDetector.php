<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput;

/**
 * Detects the AI-agent runtime an invocation is running inside by inspecting
 * a small set of well-known environment variables.
 *
 * The detector is intentionally I/O-free: a single pass of `getenv()` lookups
 * over a constant client map (NFR-001 — ≤ 1 ms median). Adding a new client
 * is a one-line change to {@see AgentDetector::CLIENTS} — see
 * `docs/specs/agent-output.md` (M4 WP02) for the canonical client list and
 * the extension procedure.
 *
 * Returns the canonical client identifier (`'claude-code'`, `'cursor'`, …)
 * or `null` when no agent env is detected. The caller decides whether to
 * activate JSON formatting based on this result, the `--output=json` flag,
 * and / or the `WAASEYAA_OUTPUT` env var fallback.
 *
 * The mapping deliberately uses `getenv()` rather than `$_ENV` because some
 * agent runtimes do not populate the PHP superglobals — `getenv()` always
 * reads the live process environment.
 *
 * @api
 */
final class AgentDetector
{
    /**
     * The canonical {env var name => client identifier} map.
     *
     * Order is not load-bearing — `detect()` returns the first match found
     * during iteration, but every supported env var maps to a unique client
     * so callers cannot encounter a collision.
     *
     * @var array<string, string>
     */
    public const array CLIENTS = [
        'CLAUDE_CODE'   => 'claude-code',
        'CURSOR_AGENT'  => 'cursor',
        'CODEX_CLI'     => 'codex',
        'GEMINI_CLI'    => 'gemini',
        'WINDSURF'      => 'windsurf',
        'JUNIE'         => 'junie',
        'COPILOT_AGENT' => 'github-copilot',
    ];

    /**
     * Returns the detected client identifier, or `null` if no agent runtime
     * is detected via the {@see CLIENTS} map.
     */
    public function detect(): ?string
    {
        foreach (self::CLIENTS as $envVar => $clientId) {
            $value = getenv($envVar);
            if ($value !== false && $value !== '' && $value !== '0') {
                return $clientId;
            }
        }

        return null;
    }
}
