<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\McpProtocol;
use Waaseyaa\Mcp\McpProtocolRequestException;
use Waaseyaa\Mcp\McpProtocolRequestValidator;
use Waaseyaa\Mcp\McpRequestContext;
use Waaseyaa\Mcp\McpRequestHeaders;

#[CoversClass(McpProtocolRequestValidator::class)]
#[CoversClass(McpProtocolRequestException::class)]
#[CoversClass(McpRequestContext::class)]
#[CoversClass(McpRequestHeaders::class)]
final class McpProtocolRequestValidatorTest extends TestCase
{
    private McpProtocolRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new McpProtocolRequestValidator();
    }

    #[Test]
    public function absent_body_metadata_preserves_the_legacy_protocol(): void
    {
        $context = $this->validator->validate(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            new McpRequestHeaders(),
        );

        self::assertFalse($context->modern);
    }

    #[Test]
    public function current_body_metadata_and_matching_headers_select_the_modern_protocol(): void
    {
        $context = $this->validator->validate(
            $this->modernRequest('tools/list'),
            new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list'),
        );

        self::assertTrue($context->modern);
    }

    #[Test]
    public function unsupported_body_version_is_a_specific_http_400_refusal(): void
    {
        try {
            $this->validator->validate(
                $this->modernRequest('tools/list', '2099-01-01'),
                new McpRequestHeaders('2099-01-01', 'tools/list'),
            );
            self::fail('Expected unsupported-version refusal.');
        } catch (McpProtocolRequestException $e) {
            self::assertSame(-32022, $e->jsonRpcCode);
            self::assertSame(400, $e->httpStatus);
            self::assertSame('2099-01-01', $e->data['requested']);
            self::assertContains(McpProtocol::CURRENT, $e->data['supported']);
        }
    }

    #[Test]
    public function non_string_body_version_is_invalid_metadata_not_an_unsupported_string_version(): void
    {
        $request = $this->modernRequest('tools/list');
        $request['params']['_meta'][McpProtocol::VERSION_META_KEY] = ['not-a-version'];

        try {
            $this->validator->validate(
                $request,
                new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list'),
            );
            self::fail('Expected invalid metadata refusal.');
        } catch (McpProtocolRequestException $e) {
            self::assertSame(-32602, $e->jsonRpcCode);
            self::assertSame('protocol_version_not_string', $e->reason);
        }
    }

    #[Test]
    public function modern_request_requires_object_client_capabilities_and_valid_optional_client_info(): void
    {
        foreach ([
            [],
            ['io.modelcontextprotocol/clientCapabilities' => 'not-an-object'],
            [
                'io.modelcontextprotocol/clientCapabilities' => [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'client-without-version'],
            ],
        ] as $replacementMeta) {
            $request = $this->modernRequest('tools/list');
            $request['params']['_meta'] = [
                McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT,
                ...$replacementMeta,
            ];

            try {
                $this->validator->validate(
                    $request,
                    new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list'),
                );
                self::fail('Expected invalid modern metadata refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32602, $e->jsonRpcCode);
                self::assertSame(400, $e->httpStatus);
            }
        }
    }

    #[Test]
    public function modern_version_and_method_headers_must_match_the_body(): void
    {
        foreach ([
            new McpRequestHeaders(null, 'tools/list'),
            new McpRequestHeaders(McpProtocol::CURRENT, null),
            new McpRequestHeaders(McpProtocol::CURRENT, 'tools/call'),
        ] as $headers) {
            try {
                $this->validator->validate($this->modernRequest('tools/list'), $headers);
                self::fail('Expected a header mismatch refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32020, $e->jsonRpcCode);
                self::assertSame(400, $e->httpStatus);
            }
        }
    }

    #[Test]
    public function method_header_must_be_a_safe_plain_ascii_field_value(): void
    {
        foreach (["tools/list\n", 'töols/list', ' tools/list '] as $method) {
            $request = $this->modernRequest($method);

            try {
                $this->validator->validate(
                    $request,
                    new McpRequestHeaders(McpProtocol::CURRENT, $method),
                );
                self::fail('Expected malformed method-header refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32020, $e->jsonRpcCode);
                self::assertSame('method_header_malformed', $e->reason);
            }
        }
    }

    #[Test]
    public function duplicate_standard_headers_are_malformed(): void
    {
        try {
            $this->validator->validate(
                $this->modernRequest('tools/list'),
                new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list', malformed: true),
            );
            self::fail('Expected duplicate-header refusal.');
        } catch (McpProtocolRequestException $e) {
            self::assertSame(-32020, $e->jsonRpcCode);
            self::assertSame('duplicate_standard_header', $e->reason);
        }
    }

    #[Test]
    public function tools_call_name_header_supports_the_required_base64_sentinel(): void
    {
        $name = "search/content\nprivate";
        $request = $this->modernRequest('tools/call');
        $request['params']['name'] = $name;

        $context = $this->validator->validate(
            $request,
            new McpRequestHeaders(
                McpProtocol::CURRENT,
                'tools/call',
                '=?base64?' . \base64_encode($name) . '?=',
            ),
        );

        self::assertTrue($context->modern);
    }

    #[Test]
    public function resource_read_name_header_must_match_the_uri_and_supports_the_required_encoding(): void
    {
        $uri = "waaseyaa://content/\u{00E9}";
        $request = $this->modernRequest('resources/read');
        $request['params']['uri'] = $uri;

        $context = $this->validator->validate(
            $request,
            new McpRequestHeaders(
                McpProtocol::CURRENT,
                'resources/read',
                '=?base64?' . \base64_encode($uri) . '?=',
            ),
        );

        self::assertTrue($context->modern);

        foreach ([null, 'waaseyaa://content/different', '=?base64?not valid!?='] as $name) {
            try {
                $this->validator->validate(
                    $request,
                    new McpRequestHeaders(McpProtocol::CURRENT, 'resources/read', $name),
                );
                self::fail('Expected a resource URI header mismatch refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32020, $e->jsonRpcCode);
                self::assertSame('name_mismatch', $e->reason);
            }
        }
    }

    #[Test]
    public function non_string_resource_uri_is_refused_as_an_unmatchable_required_name_mirror(): void
    {
        $request = $this->modernRequest('resources/read');
        $request['params']['uri'] = ['not-a-string'];

        try {
            $this->validator->validate(
                $request,
                new McpRequestHeaders(McpProtocol::CURRENT, 'resources/read', 'not-a-string'),
            );
            self::fail('Expected a name-header mismatch refusal.');
        } catch (McpProtocolRequestException $e) {
            self::assertSame(-32020, $e->jsonRpcCode);
            self::assertSame('name_mismatch', $e->reason);
        }
    }

    #[Test]
    public function missing_malformed_or_mismatched_tool_name_header_is_refused(): void
    {
        $request = $this->modernRequest('tools/call');
        $request['params']['name'] = 'content.search';

        foreach ([
            null,
            'different',
            '=?base64?not valid!?=',
            '=?base64?' . \base64_encode("invalid-utf8-\xFF") . '?=',
            'café',
            ' padded ',
        ] as $name) {
            try {
                $this->validator->validate(
                    $request,
                    new McpRequestHeaders(McpProtocol::CURRENT, 'tools/call', $name),
                );
                self::fail('Expected a tool-name header mismatch refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32020, $e->jsonRpcCode);
            }
        }
    }

    #[Test]
    public function modern_headers_cannot_reclassify_a_legacy_body(): void
    {
        foreach ([
            new McpRequestHeaders(McpProtocol::CURRENT),
            new McpRequestHeaders(method: 'tools/list'),
            new McpRequestHeaders(name: 'read_node'),
            new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list'),
        ] as $headers) {
            try {
                $this->validator->validate(
                    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
                    $headers,
                );
                self::fail('Expected a body/header era mismatch refusal.');
            } catch (McpProtocolRequestException $e) {
                self::assertSame(-32020, $e->jsonRpcCode);
            }
        }
    }

    #[Test]
    public function name_header_is_forbidden_on_modern_methods_without_a_name_source(): void
    {
        try {
            $this->validator->validate(
                $this->modernRequest('tools/list'),
                new McpRequestHeaders(McpProtocol::CURRENT, 'tools/list', 'read_node'),
            );
            self::fail('Expected unexpected-name refusal.');
        } catch (McpProtocolRequestException $e) {
            self::assertSame(-32020, $e->jsonRpcCode);
            self::assertSame('unexpected_name_header', $e->reason);
        }
    }

    #[Test]
    public function supported_legacy_header_is_allowed_but_unknown_header_is_not(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
        $context = $this->validator->validate(
            $request,
            new McpRequestHeaders('2025-06-18'),
        );
        self::assertFalse($context->modern);

        $this->expectException(McpProtocolRequestException::class);
        $this->validator->validate($request, new McpRequestHeaders('2099-01-01'));
    }

    /** @return array<string, mixed> */
    private function modernRequest(string $method, string $version = McpProtocol::CURRENT): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [
                '_meta' => [
                    McpProtocol::VERSION_META_KEY => $version,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ];
    }
}
