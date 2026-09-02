<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Mcp\Stdio;

/**
 * Cross-platform resolution of the command a launcher runs to start the local
 * stdio MCP server (ADR-022 D-9.2 acceptance: "Executable resolution reuses
 * `waaseyaa dev` and never assumes `command: php`").
 *
 * `Waaseyaa\CLI\Handler\ServeHandler` never resolves the interpreter via a
 * bare `'php'` string that depends on `$PATH` — it invokes `[PHP_BINARY, '-S',
 * ...]`, an absolute path resolved once by the PHP runtime itself, which is
 * portable to POSIX and Windows path shapes without a platform branch. This
 * resolver follows the same discipline for the stdio server's own invocation:
 * {@see self::resolve()} always returns an absolute interpreter path (the
 * caller's own `$phpBinary`, or `PHP_BINARY`) plus an explicit argv list —
 * never a shell string a `command: php` launcher entry would have to parse,
 * and never a bare interpreter name resolved by searching `$PATH`.
 *
 * A platform launcher descriptor (Claude Desktop / VS Code config fragment)
 * consuming this result is an explicitly deferred, separate design question
 * (#2659's own scope note); this class only proves the underlying resolution
 * is portable, so that future descriptor has a correct command to serialize.
 *
 * @api
 */
final class StdioServerExecutableResolver
{
    /** The only profile {@see \Waaseyaa\CLI\Command\Mcp\McpServeCommand} accepts today. */
    public const string DEFAULT_PROFILE = 'developer';

    /**
     * @param string  $projectRoot The consumer project root — POSIX
     *        (`/home/dev/app`) or Windows (`C:\Users\dev\app`) shaped; both are
     *        accepted and normalized to forward slashes, which PHP itself
     *        accepts in every path API on every platform.
     * @param ?string $phpBinary   Overrides the resolved interpreter. Defaults
     *        to `PHP_BINARY`, exactly as `ServeHandler` resolves its own
     *        interpreter — never the literal string `'php'`.
     *
     * @throws \InvalidArgumentException When no usable interpreter path is
     *         available (an empty override, or an empty `PHP_BINARY` — the
     *         latter is a defensive guard; every SAPI Waaseyaa supports sets it).
     *
     * @return array{command: string, args: list<string>}
     */
    public static function resolve(
        string $projectRoot,
        ?string $phpBinary = null,
        string $profile = self::DEFAULT_PROFILE,
    ): array {
        $php = $phpBinary ?? \PHP_BINARY;
        if (trim($php) === '') {
            throw new \InvalidArgumentException(
                'No PHP interpreter path could be resolved. Pass $phpBinary explicitly rather than falling back '
                . 'to a bare "php" that would be resolved by searching $PATH.',
            );
        }

        if (trim($projectRoot) === '') {
            throw new \InvalidArgumentException('$projectRoot must not be empty.');
        }

        if (trim($profile) === '') {
            throw new \InvalidArgumentException('$profile must not be empty.');
        }

        // Forward-slash normalization only: PHP's file APIs accept '/' as a
        // path separator on Windows exactly as on POSIX, so this needs no
        // platform branch. Composer's own generated vendor/bin/waaseyaa exists
        // identically shaped on every platform (a POSIX shell script; Windows
        // additionally gets a .bat shim, but PHP invoked directly against the
        // extensionless file works on both because Composer's shim IS a valid
        // PHP file with a POSIX shebang PHP itself ignores).
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        return [
            'command' => $php,
            'args' => [$normalizedRoot . '/vendor/bin/waaseyaa', 'mcp:serve', '--profile=' . $profile],
        ];
    }
}
