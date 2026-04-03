<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\JsonResponseTrait;
use Waaseyaa\Foundation\Http\HttpResponse;

#[CoversClass(JsonResponseTrait::class)]
final class JsonResponseTraitTest extends TestCase
{
    use JsonResponseTrait;

    #[Test]
    public function json_body_decodes_valid_json(): void
    {
        $request = Request::create('/', 'POST', content: '{"key":"value"}');
        $this->assertSame(['key' => 'value'], $this->jsonBody($request));
    }

    #[Test]
    public function json_body_returns_empty_array_for_empty_content(): void
    {
        $request = Request::create('/', 'POST', content: '');
        $this->assertSame([], $this->jsonBody($request));
    }

    #[Test]
    public function json_body_returns_empty_array_for_invalid_json(): void
    {
        $request = Request::create('/', 'POST', content: '{broken');
        $this->assertSame([], $this->jsonBody($request));
    }

    #[Test]
    public function json_builds_response_with_defaults(): void
    {
        $response = $this->json(['ok' => true]);
        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame('{"ok":true}', $response->content);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/json', $response->headers['Content-Type']);
    }

    #[Test]
    public function json_builds_response_with_custom_status(): void
    {
        $response = $this->json(['error' => 'not found'], 404);
        $this->assertSame(404, $response->statusCode);
    }
}
