<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Error;

use Waaseyaa\AI\Tools\AgentToolResult;

/**
 * The single source of truth for what an agent / MCP caller is told when a tool
 * fails for a reason that is NOT a deliberate domain error.
 *
 * A thrown exception's message routinely carries DSN fragments, credentials,
 * absolute filesystem paths and internal class names. Returning it verbatim
 * hands all of that to an unauthenticated or least-privilege caller. Everything
 * the caller receives here is therefore a fixed literal plus a random
 * correlation id.
 *
 * The message is not relocated to the log either — see {@see logContext()}. The
 * log receives safe diagnostic METADATA (where the failure happened), not the
 * exception detail (what the data was). A log store is not a private channel,
 * and moving a credential from a response into an indexed, widely-readable
 * aggregator is relocating a disclosure, not fixing one. The correlation id is
 * what joins the two sides.
 *
 * This is deliberately NOT used for domain errors. `VALIDATION_FAILED`,
 * `REVISION_CONFLICT`, `ASSET_REJECTED`, the Content Publishing envelopes and
 * the per-tool `forbidden` refusals are all authored, machine-readable results
 * that an agent is meant to act on — they never pass through here.
 *
 * @api
 */
final class SanitizedToolError
{
    /** Machine-readable code; stable across releases so agents can branch on it. */
    public const string CODE = 'INTERNAL_ERROR';

    /** Stable code for an installed tool whose optional backend cannot resolve. */
    public const string UNAVAILABLE_CODE = 'TOOL_UNAVAILABLE';

    /**
     * The only human-readable text a caller ever receives for an internal
     * failure. A fixed literal: it interpolates nothing, so it cannot leak.
     */
    public const string MESSAGE = 'The tool failed because of an internal error. '
        . 'Quote the correlation id to an operator to have it diagnosed.';

    /** Fixed caller text for an optional backend wiring failure. */
    public const string UNAVAILABLE_MESSAGE = 'The tool is temporarily unavailable. '
        . 'Quote the correlation id to an operator to have it diagnosed.';

    /**
     * A random, non-guessable id joining the caller-visible envelope to the
     * server-side log entry. 16 hex chars — enough to be unique in a log window,
     * and carrying no information about the failure itself.
     */
    public static function correlationId(): string
    {
        return \bin2hex(\random_bytes(8));
    }

    /**
     * @return array{code: string, message: string, meta: array{correlation_id: string}}
     */
    public static function body(string $correlationId): array
    {
        return [
            'code' => self::CODE,
            'message' => self::MESSAGE,
            'meta' => ['correlation_id' => $correlationId],
        ];
    }

    /** The caller-visible envelope, serialized for a `text` content block. */
    public static function json(string $correlationId): string
    {
        return \json_encode(self::body($correlationId), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The tool-level form. `summary` is the audit/transcript line, so it carries
     * the code and correlation id only — never the exception message.
     */
    public static function result(string $correlationId): AgentToolResult
    {
        return new AgentToolResult(
            isError: true,
            content: [['type' => 'text', 'text' => self::json($correlationId)]],
            summary: \sprintf('%s (correlation_id=%s)', self::CODE, $correlationId),
        );
    }

    public static function unavailableResult(string $correlationId): AgentToolResult
    {
        $body = [
            'code' => self::UNAVAILABLE_CODE,
            'message' => self::UNAVAILABLE_MESSAGE,
            'meta' => ['correlation_id' => $correlationId],
        ];

        return new AgentToolResult(
            isError: true,
            content: [[
                'type' => 'text',
                'text' => \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ]],
            summary: \sprintf('%s (correlation_id=%s)', self::UNAVAILABLE_CODE, $correlationId),
        );
    }

    /**
     * Safe diagnostic metadata for the server-side log.
     *
     * **The exception message is deliberately absent.** A log is not a private
     * channel: it is shipped to aggregators, indexed, retained, and read by
     * people with far broader access than the operator debugging one failure.
     * Copying a message that contains a DSN, a password or a host address out of
     * the response and into the log store relocates the disclosure rather than
     * fixing it. The same reasoning excludes the bearer token, the tool
     * arguments, and the stack trace — a trace carries argument VALUES frame by
     * frame, so it is the widest leak of the three.
     *
     * The {@see \Throwable} object itself is never attached either: a logger that
     * serializes context objects (JSON, `var_export`, an error tracker's payload
     * builder) would walk straight into the message and trace this method is
     * built to exclude.
     *
     * What remains identifies WHERE the failure happened without saying what the
     * data was: the correlation id joining log to response, the tool, the
     * exception class, its integer code, and the throw site. An operator pairs
     * that with the correlation id from the caller's response; reproducing the
     * message is a debugger's job, under an access decision someone actually
     * made.
     *
     * @return array<string, mixed>
     */
    public static function logContext(\Throwable $e, string $correlationId, string $toolName): array
    {
        $context = [
            'correlation_id' => $correlationId,
            'tool' => $toolName,
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        // Only an INTEGER code. `getCode()` is `mixed` in practice — PDOException
        // carries a SQLSTATE string, and a custom exception may put anything
        // there, including an interpolated identifier. An int cannot carry a
        // credential; a string might, so it is dropped rather than inspected.
        // (Dropping beats a heuristic here: a "does this string look sensitive?"
        // test is exactly the guesswork this class exists to avoid.)
        $code = $e->getCode();
        if (\is_int($code)) {
            $context['code'] = $code;
        }

        return $context;
    }
}
