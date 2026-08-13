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
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $tokens = token_get_all($source);
            $aliases = $this->aliases($tokens);
            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    continue;
                }
                [$kind, $text, $line] = $token;
                $literal = $kind === T_CONSTANT_ENCAPSED_STRING ? $this->concatenatedLiteral($tokens, $index) : $text;
                $newName = $kind === T_NEW ? $this->nextName($tokens, $index) : null;
                $resolvedName = $newName !== null ? ($aliases[$newName] ?? $newName) : null;
                if ($kind === T_NEW && in_array($resolvedName, ['pdo', 'sqlite3'], true)) {
                    $findings[] = $this->finding('SITE100_RAW_DATABASE_CONSTRUCTION', $path, $line, $text);
                }
                if (
                    $kind === T_NEW
                    && preg_match('/(?:repository|store|controller)$/i', (string) $resolvedName) === 1
                    && ($this->isRouteConstruction($tokens, $index) || $this->assignedValueIsLaterRegisteredAsRoute($tokens, $index))
                ) {
                    $findings[] = $this->finding('SITE104_ROUTE_CONSTRUCTION_BYPASS', $path, $line, $text);
                }
                if ($kind === T_CONSTANT_ENCAPSED_STRING && preg_match('/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i', $literal) === 1) {
                    $findings[] = $this->finding('SITE101_RUNTIME_DDL', $path, $line, $literal);
                }
                if ($kind === T_VARIABLE && $text === '$_SERVER' && $this->isAuthorityServerRead($tokens, $index)) {
                    $findings[] = $this->finding('SITE102_SERVER_CANONICAL_ORIGIN', $path, $line, $text);
                }
                if (
                    $kind === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('#https?://[a-z0-9.-]+#i', $literal) === 1
                    && !$this->isDocumentationUrl($literal)
                    && !$this->isStandardIdentifierLiteral($tokens, $index, $literal)
                    && preg_match('#https?://(?:localhost|127\.0\.0\.1|\[::1\])(?:[/:]|$)#i', $literal) !== 1
                ) {
                    $findings[] = $this->finding('SITE103_HARDCODED_PRODUCTION_ORIGIN', $path, $line, $literal);
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

    /**
     * @param list<array{int,string,int}|string> $tokens
     * @return array<string, string>
     */
    private function aliases(array $tokens): array
    {
        $aliases = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }
            $declaration = '';
            for (++$index; $index < $count; ++$index) {
                $part = $tokens[$index];
                if ($part === ';') {
                    break;
                }
                $declaration .= is_array($part) ? $part[1] : $part;
            }
            $declaration = trim($declaration);
            if (preg_match('~^\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$~iD', $declaration, $matches) !== 1) {
                continue;
            }
            $target = strtolower(ltrim($matches[1], '\\'));
            $alias = strtolower($matches[2] ?? basename(str_replace('\\', '/', $target)));
            $aliases[$alias] = $target;
        }

        return $aliases;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function concatenatedLiteral(array $tokens, int $index): string
    {
        $first = $tokens[$index];
        if (!is_array($first)) {
            return '';
        }
        $literal = $this->unquote($first[1]);
        $cursor = $index;
        while (($dot = $this->nextSignificantIndex($tokens, $cursor)) !== null && $tokens[$dot] === '.') {
            $next = $this->nextSignificantIndex($tokens, $dot);
            if ($next === null || !is_array($tokens[$next]) || $tokens[$next][0] !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }
            $literal .= $this->unquote($tokens[$next][1]);
            $cursor = $next;
        }

        return $literal;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_string($token) && trim($token) === '') {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function unquote(string $literal): string
    {
        return strlen($literal) >= 2 ? substr($literal, 1, -1) : $literal;
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

    private function isDocumentationUrl(string $literal): bool
    {
        $host = strtolower((string) parse_url($literal, PHP_URL_HOST));
        $path = strtolower((string) parse_url($literal, PHP_URL_PATH));

        return preg_match('/^(?:docs?|help)(?:\.|$)/D', $host) === 1
            || preg_match('#/(?:docs?|documentation|help)(?:/|$)#D', $path) === 1;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function isRouteConstruction(array $tokens, int $index): bool
    {
        $statement = '';
        for (--$index; $index >= 0 && strlen($statement) < 600; --$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            if ($text === ';' || $text === '{' || $text === '}') {
                break;
            }
            $statement = $text . $statement;
        }

        return $this->hasRouteContext($statement);
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function assignedValueIsLaterRegisteredAsRoute(array $tokens, int $newIndex): bool
    {
        $equal = $this->previousSignificantIndex($tokens, $newIndex);
        if ($equal === null || $tokens[$equal] !== '=') {
            return false;
        }
        $target = '';
        for ($cursor = $equal - 1; $cursor >= 0; --$cursor) {
            $token = $tokens[$cursor];
            if (is_string($token) && in_array($token, [';', '{', '}', ','], true)) {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $target = (is_array($token) ? $token[1] : $token) . $target;
        }
        $target = trim($target);
        if ($target === '' || !str_contains($target, '$')) {
            return false;
        }
        $count = count($tokens);
        for ($index = $newIndex + 1; $index < $count; ++$index) {
            $statement = $this->statementAround($tokens, $index);
            if ($this->hasRouteContext($statement) && str_contains(preg_replace('/\s+/', '', $statement) ?? $statement, preg_replace('/\s+/', '', $target) ?? $target)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function statementAround(array $tokens, int $index): string
    {
        $start = $index;
        while ($start > 0 && !in_array($tokens[$start - 1], [';', '{', '}'], true)) {
            --$start;
        }
        $end = $index;
        $count = count($tokens);
        while ($end + 1 < $count && !in_array($tokens[$end + 1], [';', '{', '}'], true)) {
            ++$end;
        }
        $statement = '';
        for ($cursor = $start; $cursor <= $end; ++$cursor) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $statement .= is_array($token) ? $token[1] : $token;
        }

        return $statement;
    }

    private function hasRouteContext(string $statement): bool
    {
        return preg_match('/(?:route|router)/i', $statement) === 1
            || preg_match('/->\s*(?:add|map|get|post|put|patch|delete)\s*\([^;]*[\'\"]\//i', $statement) === 1;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for (--$index; $index >= 0; --$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_string($token) && trim($token) === '') {
                continue;
            }

            return $index;
        }

        return null;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function isStandardIdentifierLiteral(array $tokens, int $index, string $literal): bool
    {
        if (str_contains(strtolower($literal), 'xmlns=')) {
            return true;
        }
        $host = strtolower((string) parse_url($literal, PHP_URL_HOST));
        if (in_array($host, ['schema.org', 'json-schema.org', 'www.sitemaps.org'], true)) {
            return true;
        }
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
        $arrow = $significant[0] ?? null;
        $key = $significant[1] ?? null;

        return is_array($arrow)
            && $arrow[0] === T_DOUBLE_ARROW
            && is_array($key)
            && $key[0] === T_CONSTANT_ENCAPSED_STRING
            && in_array(trim($key[1], "'\""), ['$id', '$schema', '@context'], true)
            && preg_match('#/(?:schema|vocab(?:ulary)?|ontology|context)(?:/|$)|\.(?:json|jsonld)$#i', (string) parse_url($literal, PHP_URL_PATH)) === 1;
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
            'SITE104_ROUTE_CONSTRUCTION_BYPASS' => ['Route registration constructs an application authority directly.', 'Register the repository, store, or controller in the container and reference the managed service from the route.'],
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
