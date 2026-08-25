<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Spec\SpecIndexProvider;

/**
 * Spec-search tool. Substring-matches a query against the bodies of every
 * markdown file enumerated by {@see SpecIndexProvider} and returns the
 * matches plus their nearest preceding `## ` or `### ` header.
 *
 * Gated by `bimaaji.read`; idempotent; no side effects; no filesystem
 * writes.
 *
 * Capability: `bimaaji.read`. Naïve substring search — for ranked or
 * trigram-based results, see AD-04 follow-up notes in the mission plan
 * (`kitty-specs/bimaaji-mcp-bridge-01KS5VS8/plan.md`).
 *
 * @api
 */
#[AsAgentTool(
    name: 'bimaaji_search_specs',
    capability: 'bimaaji.read',
    destructive: false,
    dryRunSupported: true,
    category: 'bimaaji',
)]
final class SearchSpecsTool extends AbstractAgentTool
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 100;
    private const int MAX_QUERY_LENGTH = 256;
    private const int SNIPPET_RADIUS = 80;

    public function __construct(
        private readonly SpecIndexProvider $specIndex,
    ) {}

    public function description(): string
    {
        return 'Search the bodies of docs/specs/*.md for a substring (case-insensitive). Returns matching files with line numbers, nearest section heading, and a snippet.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_QUERY_LENGTH,
                    'pattern' => '^[^\\x00-\\x1F\\x7F]+$',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.read', $account);
        if ($denied !== null) {
            return $denied;
        }

        $query = $arguments['query'] ?? null;
        if (!is_string($query) || $query === '') {
            return AgentToolResult::error(
                message: 'bimaaji_search_specs: missing required argument "query" (non-empty string).',
                summary: 'missing argument',
            );
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH || preg_match('/[\\x00-\\x1F\\x7F]/u', $query) === 1) {
            return AgentToolResult::error(
                message: sprintf(
                    'bimaaji_search_specs: "query" must contain at most %d printable characters.',
                    self::MAX_QUERY_LENGTH,
                ),
                summary: 'invalid argument',
            );
        }

        $limit = self::DEFAULT_LIMIT;
        if (isset($arguments['limit'])) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1) {
                return AgentToolResult::error(
                    message: 'bimaaji_search_specs: "limit" must be a positive integer.',
                    summary: 'invalid argument',
                );
            }
            $limit = min($arguments['limit'], self::MAX_LIMIT);
        }

        $index = $this->specIndex->provide()->data;
        $matches = [];
        $queryLower = strtolower($query);

        foreach ($index as $entry) {
            if (count($matches) >= $limit) {
                break;
            }
            $path = $entry['path'] ?? null;
            if (!is_string($path) || !is_readable($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $file = basename($path);
            $matches = array_merge($matches, $this->searchInFile($file, $contents, $query, $queryLower, $limit - count($matches)));
        }

        $payload = ['matches' => $matches];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_search_specs', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('%d match%s for "%s"', count($matches), count($matches) === 1 ? '' : 'es', $query),
            structuredContent: $payload,
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }

    /**
     * @return list<array{file: string, section_title: string, line_number: int, snippet: string}>
     */
    private function searchInFile(string $file, string $contents, string $query, string $queryLower, int $remainingLimit): array
    {
        if ($remainingLimit <= 0) {
            return [];
        }

        $lines = explode("\n", $contents);
        $currentSection = '';
        $matches = [];

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '## ')) {
                $currentSection = trim(substr($line, 3));
            } elseif (str_starts_with($line, '### ')) {
                $currentSection = trim(substr($line, 4));
            }

            if (count($matches) >= $remainingLimit) {
                break;
            }

            if (str_contains(strtolower($line), $queryLower)) {
                $matches[] = [
                    'file' => $file,
                    'section_title' => $currentSection,
                    'line_number' => $index + 1,
                    'snippet' => $this->buildSnippet($line, $query),
                ];
            }
        }

        return $matches;
    }

    private function buildSnippet(string $line, string $query): string
    {
        $trimmed = trim($line);
        if (mb_strlen($trimmed) <= 2 * self::SNIPPET_RADIUS) {
            return $trimmed;
        }

        $position = stripos($trimmed, $query);
        if ($position === false) {
            return mb_substr($trimmed, 0, 2 * self::SNIPPET_RADIUS) . '…';
        }

        $start = max(0, $position - self::SNIPPET_RADIUS);
        $length = 2 * self::SNIPPET_RADIUS + mb_strlen($query);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($trimmed) ? '…' : '';

        return $prefix . mb_substr($trimmed, $start, $length) . $suffix;
    }
}
