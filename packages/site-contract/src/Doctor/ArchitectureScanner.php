<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

/** @api */
final class ArchitectureScanner
{
    /**
     * Scan already-discovered shipped PHP sources. Filesystem discovery remains
     * a caller responsibility so this Layer 0 service stays deterministic.
     *
     * @param array<string, string> $sources portable path => exact bytes
     * @return list<SiteDoctorFinding>
     */
    public function scan(array $sources): array
    {
        ksort($sources, SORT_STRING);
        $findings = [];
        foreach ($sources as $path => $source) {
            $this->assertSource($path, $source);
            $tokens = token_get_all($source);
            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    continue;
                }
                [$kind, $text, $line] = $token;
                if ($kind === T_NEW && $this->nextName($tokens, $index) === 'pdo') {
                    $findings[] = $this->finding('SITE100_RAW_DATABASE_CONSTRUCTION', $path, $line, $text);
                }
                if ($kind === T_CONSTANT_ENCAPSED_STRING && preg_match('/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i', $text) === 1) {
                    $findings[] = $this->finding('SITE101_RUNTIME_DDL', $path, $line, $text);
                }
                if ($kind === T_VARIABLE && $text === '$_SERVER' && $this->isAuthorityServerRead($tokens, $index)) {
                    $findings[] = $this->finding('SITE102_SERVER_CANONICAL_ORIGIN', $path, $line, $text);
                }
                if (
                    $kind === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('#https?://[a-z0-9.-]+#i', $text) === 1
                    && $this->isOriginAssignment($tokens, $index)
                ) {
                    $findings[] = $this->finding('SITE103_HARDCODED_PRODUCTION_ORIGIN', $path, $line, $text);
                }
            }
        }
        usort($findings, static fn(SiteDoctorFinding $left, SiteDoctorFinding $right): int => [$left->id, $left->path, $left->line] <=> [$right->id, $right->path, $right->line]);

        return $findings;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function nextName(array $tokens, int $index): ?string
    {
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if (trim($token) === '' || $token === '\\') {
                    continue;
                }

                return null;
            }
            if (in_array($token[0], [T_WHITESPACE, T_NS_SEPARATOR], true)) {
                continue;
            }
            if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                return strtolower(ltrim($token[1], '\\'));
            }

            return null;
        }

        return null;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function isAuthorityServerRead(array $tokens, int $index): bool
    {
        $text = '';
        $count = count($tokens);
        for (++$index; $index < $count && strlen($text) < 80; ++$index) {
            $token = $tokens[$index];
            $text .= is_array($token) ? $token[1] : $token;
            if (str_contains($text, ']')) {
                break;
            }
        }

        return preg_match('/[\'\"](?:HTTP_HOST|SERVER_NAME|SERVER_PORT|HTTPS)[\'\"]/', $text) === 1;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function isOriginAssignment(array $tokens, int $index): bool
    {
        $significant = [];
        for (--$index; $index >= 0 && count($significant) < 3; --$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_string($token) && trim($token) === '') {
                continue;
            }
            $significant[] = $token;
        }
        if (($significant[0] ?? null) !== '=') {
            return false;
        }
        $variable = $significant[1] ?? null;

        return is_array($variable)
            && $variable[0] === T_VARIABLE
            && preg_match('/(?:origin|canonical|base_?url|site_?url)/i', $variable[1]) === 1;
    }

    private function assertSource(string $path, mixed $source): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Architecture sources require portable repository-relative paths.');
        }
        if (!is_string($source)) {
            throw new \InvalidArgumentException('Architecture source bytes must be strings.');
        }
    }

    private function finding(string $id, string $path, int $line, string $evidence): SiteDoctorFinding
    {
        [$message, $remediation] = match ($id) {
            'SITE100_RAW_DATABASE_CONSTRUCTION' => ['Application code constructs PDO directly.', 'Use the framework-managed database authority.'],
            'SITE101_RUNTIME_DDL' => ['Application runtime code contains schema DDL.', 'Move schema changes into a tracked framework migration.'],
            'SITE102_SERVER_CANONICAL_ORIGIN' => ['Application code derives canonical authority from server input.', 'Resolve canonical origin from the manifest-declared configuration authority.'],
            'SITE103_HARDCODED_PRODUCTION_ORIGIN' => ['Application code contains a hardcoded absolute origin.', 'Move the origin to typed configuration and use the canonical route authority.'],
            default => throw new \LogicException('Unknown site-doctor finding identity.'),
        };

        return new SiteDoctorFinding(
            $id,
            FindingSeverity::Error,
            $path,
            $line,
            $message,
            $remediation,
            hash('sha256', $path . "\0" . $line . "\0" . $evidence),
        );
    }
}
