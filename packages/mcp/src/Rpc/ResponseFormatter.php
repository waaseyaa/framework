<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Rpc;

final class ResponseFormatter
{
    private const string CONTRACT_VERSION = 'v1.0';
    private const string CONTRACT_STABILITY = 'stable';

    /**
     * @return array<string, mixed>
     */
    public function result(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function withStableContractMeta(array $result, string $invokedTool): array
    {
        if (!isset($result['meta']) || !is_array($result['meta'])) {
            $result['meta'] = [];
        }

        $canonicalTool = $this->canonicalToolName($invokedTool);
        $result['meta']['contract_version'] = self::CONTRACT_VERSION;
        $result['meta']['contract_stability'] = self::CONTRACT_STABILITY;
        $result['meta']['tool_invoked'] = $invokedTool;
        if (!is_string($result['meta']['tool'] ?? null) || trim($result['meta']['tool']) === '') {
            $result['meta']['tool'] = $canonicalTool;
        }

        return $result;
    }

    public function canonicalToolName(string $tool): string
    {
        return $tool;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function formatToolContent(array $result): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ]],
        ];
    }

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }
}
