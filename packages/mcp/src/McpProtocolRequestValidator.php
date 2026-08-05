<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Classifies protocol era from body metadata, then verifies that HTTP mirrors
 * describe the same request. Headers never get to reclassify a request body.
 */
final readonly class McpProtocolRequestValidator
{
    /** @param array<string, mixed> $request */
    public function validate(array $request, McpRequestHeaders $headers): McpRequestContext
    {
        if ($headers->malformed) {
            throw $this->headerMismatch('duplicate_standard_header');
        }

        $params = $request['params'] ?? [];
        $meta = \is_array($params) ? ($params['_meta'] ?? null) : null;
        $bodyVersion = \is_array($meta) ? ($meta[McpProtocol::VERSION_META_KEY] ?? null) : null;

        if ($bodyVersion !== null) {
            if (!\is_string($bodyVersion)) {
                throw $this->invalidMetadata('protocol_version_not_string');
            }
            if ($bodyVersion !== McpProtocol::CURRENT) {
                throw $this->unsupportedVersion($bodyVersion);
            }

            $this->validateModernMetadata($meta);

            $this->requireExact($headers->protocolVersion, $bodyVersion, 'protocol_version_mismatch');
            $method = $request['method'] ?? null;
            if (!self::isPlainHeaderValue($headers->method)) {
                throw $this->headerMismatch('method_header_malformed');
            }
            $this->requireExact($headers->method, \is_string($method) ? $method : null, 'method_mismatch');

            if ($method === 'tools/call' || $method === 'resources/read') {
                $bodyName = $method === 'tools/call'
                    ? ($params['name'] ?? null)
                    : ($params['uri'] ?? null);
                $headerName = self::decodeName($headers->name);
                $this->requireExact($headerName, \is_string($bodyName) ? $bodyName : null, 'name_mismatch');
            } elseif ($headers->name !== null) {
                throw $this->headerMismatch('unexpected_name_header');
            }

            return new McpRequestContext(true);
        }

        if ($headers->method !== null || $headers->name !== null || $headers->protocolVersion === McpProtocol::CURRENT) {
            throw $this->headerMismatch('modern_headers_without_body_version');
        }

        if ($headers->protocolVersion !== null && !McpProtocol::isLegacySupported($headers->protocolVersion)) {
            throw $this->unsupportedVersion($headers->protocolVersion);
        }

        return new McpRequestContext(false);
    }

    private function requireExact(?string $actual, ?string $expected, string $reason): void
    {
        if ($actual === null || $expected === null || !\hash_equals($expected, $actual)) {
            throw $this->headerMismatch($reason);
        }
    }

    private function headerMismatch(string $reason): McpProtocolRequestException
    {
        return new McpProtocolRequestException(
            -32020,
            'HeaderMismatch',
            400,
            reason: $reason,
        );
    }

    private function unsupportedVersion(string $requested): McpProtocolRequestException
    {
        return new McpProtocolRequestException(
            -32022,
            'Unsupported protocol version',
            400,
            [
                'supported' => McpProtocol::SUPPORTED,
                'requested' => $requested,
            ],
            'unsupported_protocol_version',
        );
    }

    /** @param array<mixed> $meta */
    private function validateModernMetadata(array $meta): void
    {
        $capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
        if (!self::isJsonObject($capabilities)) {
            throw $this->invalidMetadata('client_capabilities_missing_or_not_object');
        }

        if (\array_key_exists('io.modelcontextprotocol/clientInfo', $meta)) {
            $clientInfo = $meta['io.modelcontextprotocol/clientInfo'];
            if (!self::isJsonObject($clientInfo)
                || !\is_string($clientInfo['name'] ?? null)
                || !\is_string($clientInfo['version'] ?? null)
            ) {
                throw $this->invalidMetadata('client_info_invalid');
            }
        }
    }

    private function invalidMetadata(string $reason): McpProtocolRequestException
    {
        return new McpProtocolRequestException(
            -32602,
            'Invalid modern request metadata',
            400,
            reason: $reason,
        );
    }

    private static function isJsonObject(mixed $value): bool
    {
        return \is_array($value) && (!\array_is_list($value) || $value === []);
    }

    private static function decodeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\str_starts_with($value, '=?base64?')) {
            if (!self::isPlainHeaderValue($value)) {
                return null;
            }

            return $value;
        }
        if (!\str_ends_with($value, '?=')) {
            return null;
        }

        $encoded = \substr($value, 9, -2);
        $decoded = \base64_decode($encoded, true);
        if ($decoded === false
            || \base64_encode($decoded) !== $encoded
            || \preg_match('//u', $decoded) !== 1
        ) {
            return null;
        }

        return $decoded;
    }

    private static function isPlainHeaderValue(?string $value): bool
    {
        return $value !== null
            && $value !== ''
            && \preg_match('/^[\x21-\x7E](?:[\x09\x20-\x7E]*[\x21-\x7E])?$/D', $value) === 1;
    }
}
