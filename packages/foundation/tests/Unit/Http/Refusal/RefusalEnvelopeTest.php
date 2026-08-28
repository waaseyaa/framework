<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Refusal\HttpRefusal;
use Waaseyaa\Foundation\Http\Refusal\RefusalEnvelope;

#[CoversClass(RefusalEnvelope::class)]
#[CoversClass(HttpRefusal::class)]
final class RefusalEnvelopeTest extends TestCase
{
    #[Test]
    public function default_envelope_renders_the_json_api_error_document(): void
    {
        $response = RefusalEnvelope::jsonApi()->refuse($this->payloadTooLarge());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '413', 'title' => 'Payload Too Large']],
            ],
            $this->decode($response->getContent()),
        );
    }

    #[Test]
    public function json_api_envelope_includes_detail_only_when_present(): void
    {
        $response = RefusalEnvelope::jsonApi()->refuse(new HttpRefusal(
            status: 400,
            reason: RefusalEnvelope::REASON_PARSE_ERROR,
            title: 'Bad Request',
            detail: 'Invalid JSON in request body.',
        ));

        self::assertSame(
            [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => 'Invalid JSON in request body.',
                ]],
            ],
            $this->decode($response->getContent()),
        );
    }

    #[Test]
    public function a_route_declaring_json_rpc_refuses_in_json_rpc(): void
    {
        $envelope = RefusalEnvelope::fromRouteOptions($this->jsonRpcRouteOptions());

        $response = $envelope->refuse($this->payloadTooLarge());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32043,
                    'message' => 'Request body exceeds maximum size',
                    'data' => ['max_request_bytes' => 1024],
                ],
                'id' => null,
            ],
            $this->decode($response->getContent()),
        );
    }

    #[Test]
    public function json_rpc_error_omits_data_when_the_refusal_carries_none(): void
    {
        $envelope = RefusalEnvelope::fromRouteOptions($this->jsonRpcRouteOptions());

        $response = $envelope->refuse(new HttpRefusal(
            status: 400,
            reason: RefusalEnvelope::REASON_PARSE_ERROR,
            title: 'Bad Request',
            detail: 'Invalid JSON in request body.',
            transportMessage: 'Parse error',
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
                'id' => null,
            ],
            $this->decode($response->getContent()),
        );
    }

    #[Test]
    public function a_reason_without_a_declared_code_falls_back_to_json_api(): void
    {
        // The transport is declared, but only the parse-error code is mapped:
        // the seam never invents an error code it was not given.
        $envelope = RefusalEnvelope::fromRouteOptions([
            RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
            RefusalEnvelope::CODES_OPTION => [RefusalEnvelope::REASON_PARSE_ERROR => -32700],
        ]);

        $response = $envelope->refuse($this->payloadTooLarge());

        self::assertSame('413', $this->decode($response->getContent())['errors'][0]['status']);
    }

    #[Test]
    public function an_unknown_transport_falls_back_to_json_api(): void
    {
        $envelope = RefusalEnvelope::fromRouteOptions([
            RefusalEnvelope::TRANSPORT_OPTION => 'grpc',
            RefusalEnvelope::CODES_OPTION => [RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => 8],
        ]);

        $response = $envelope->refuse($this->payloadTooLarge());

        self::assertSame('413', $this->decode($response->getContent())['errors'][0]['status']);
    }

    #[Test]
    public function malformed_route_options_degrade_to_json_api(): void
    {
        foreach ([
            [],
            [RefusalEnvelope::TRANSPORT_OPTION => ''],
            [RefusalEnvelope::TRANSPORT_OPTION => ['jsonrpc']],
            [
                RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
                RefusalEnvelope::CODES_OPTION => 'not-a-map',
            ],
            [
                RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
                RefusalEnvelope::CODES_OPTION => [RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => '-32043'],
            ],
        ] as $index => $options) {
            $response = RefusalEnvelope::fromRouteOptions($options)->refuse($this->payloadTooLarge());

            self::assertSame(
                '413',
                $this->decode($response->getContent())['errors'][0]['status'],
                "options set #{$index} should degrade to the JSON:API envelope",
            );
        }
    }

    #[Test]
    public function a_request_without_a_resolved_envelope_uses_json_api(): void
    {
        $response = RefusalEnvelope::forRequest(Request::create('/test', 'POST'))
            ->refuse($this->payloadTooLarge());

        self::assertSame('413', $this->decode($response->getContent())['errors'][0]['status']);
    }

    #[Test]
    public function a_request_carrying_an_envelope_uses_it(): void
    {
        $request = Request::create('/mcp', 'POST');
        $request->attributes->set(
            RefusalEnvelope::REQUEST_ATTRIBUTE,
            RefusalEnvelope::fromRouteOptions($this->jsonRpcRouteOptions()),
        );

        $response = RefusalEnvelope::forRequest($request)->refuse($this->payloadTooLarge());

        self::assertSame(-32043, $this->decode($response->getContent())['error']['code']);
    }

    #[Test]
    public function a_non_envelope_request_attribute_is_ignored(): void
    {
        $request = Request::create('/mcp', 'POST');
        $request->attributes->set(RefusalEnvelope::REQUEST_ATTRIBUTE, 'jsonrpc');

        $response = RefusalEnvelope::forRequest($request)->refuse($this->payloadTooLarge());

        self::assertSame('413', $this->decode($response->getContent())['errors'][0]['status']);
    }

    /**
     * A route declares an error *code*, never a status. The HTTP status comes
     * from the refusal itself, so no route declaration — hostile, drifted, or
     * simply wrong — can soften a refusal into a success.
     */
    #[Test]
    public function a_route_declaration_cannot_soften_the_http_status(): void
    {
        $envelope = RefusalEnvelope::fromRouteOptions([
            RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
            RefusalEnvelope::CODES_OPTION => [RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => 200],
        ]);

        $response = $envelope->refuse($this->payloadTooLarge());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(200, $this->decode($response->getContent())['error']['code']);
    }

    #[Test]
    public function the_json_api_branch_carries_the_frameworks_json_api_content_type(): void
    {
        $response = RefusalEnvelope::jsonApi()->refuse($this->payloadTooLarge());

        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_json_rpc_branch_carries_the_transports_content_type(): void
    {
        $response = RefusalEnvelope::fromRouteOptions($this->jsonRpcRouteOptions())
            ->refuse($this->payloadTooLarge());

        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function transport_message_falls_back_to_detail_then_title(): void
    {
        self::assertSame(
            'detail',
            new HttpRefusal(status: 400, reason: 'r', title: 'title', detail: 'detail')->message(),
        );
        self::assertSame(
            'title',
            new HttpRefusal(status: 400, reason: 'r', title: 'title')->message(),
        );
    }

    /** @return array<string, mixed> */
    private function jsonRpcRouteOptions(): array
    {
        return [
            RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
            RefusalEnvelope::CODES_OPTION => [
                RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => -32043,
                RefusalEnvelope::REASON_PARSE_ERROR => -32700,
            ],
        ];
    }

    private function payloadTooLarge(): HttpRefusal
    {
        return new HttpRefusal(
            status: 413,
            reason: RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE,
            title: 'Payload Too Large',
            transportMessage: 'Request body exceeds maximum size',
            transportData: ['max_request_bytes' => 1024],
        );
    }

    /** @return array<string, mixed> */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
