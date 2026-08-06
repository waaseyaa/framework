<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use Waaseyaa\Testing\Traits\InteractsWithApi;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversTrait(InteractsWithApi::class)]
final class InteractsWithApiTest extends TestCase
{
    use InteractsWithApi;

    #[Test]
    public function getBuildsCorrectRequest(): void
    {
        $request = $this->get('/api/articles');

        $this->assertSame('GET', $request['method']);
        $this->assertSame('/api/articles', $request['uri']);
        $this->assertSame([], $request['body']);
        $this->assertSame([], $request['headers']);
    }

    #[Test]
    public function postBuildsCorrectRequest(): void
    {
        $body = ['title' => 'New article', 'status' => 1];
        $request = $this->post('/api/articles', $body);

        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/articles', $request['uri']);
        $this->assertSame($body, $request['body']);
    }

    #[Test]
    public function putBuildsCorrectRequest(): void
    {
        $body = ['title' => 'Updated article'];
        $request = $this->put('/api/articles/1', $body);

        $this->assertSame('PUT', $request['method']);
        $this->assertSame('/api/articles/1', $request['uri']);
        $this->assertSame($body, $request['body']);
    }

    #[Test]
    public function patchBuildsCorrectRequest(): void
    {
        $body = ['status' => 0];
        $request = $this->patch('/api/articles/1', $body);

        $this->assertSame('PATCH', $request['method']);
        $this->assertSame('/api/articles/1', $request['uri']);
        $this->assertSame($body, $request['body']);
    }

    #[Test]
    public function deleteBuildsCorrectRequest(): void
    {
        $request = $this->delete('/api/articles/1');

        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('/api/articles/1', $request['uri']);
        $this->assertSame([], $request['body']);
    }

    #[Test]
    public function withHeadersSetsDefaultHeaders(): void
    {
        $this->withHeaders(['Accept' => 'application/json', 'X-Custom' => 'value']);

        $request = $this->get('/api/articles');

        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame('value', $request['headers']['X-Custom']);
    }

    #[Test]
    public function withHeaderSetsSingleHeader(): void
    {
        $this->withHeader('Accept', 'application/vnd.api+json');

        $request = $this->get('/api/articles');

        $this->assertSame('application/vnd.api+json', $request['headers']['Accept']);
    }

    #[Test]
    public function withTokenSetsAuthorizationHeader(): void
    {
        $this->withToken('abc123');

        $request = $this->get('/api/articles');

        $this->assertSame('Bearer abc123', $request['headers']['Authorization']);
    }

    #[Test]
    public function perRequestHeadersMergeWithDefaults(): void
    {
        $this->withHeader('Accept', 'application/json');

        $request = $this->get('/api/articles', ['X-Request-Id' => '42']);

        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame('42', $request['headers']['X-Request-Id']);
    }

    #[Test]
    public function perRequestHeadersOverrideDefaults(): void
    {
        $this->withHeader('Accept', 'application/json');

        $request = $this->get('/api/articles', ['Accept' => 'text/html']);

        $this->assertSame('text/html', $request['headers']['Accept']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the requestHeaders between tests.
        $this->requestHeaders = [];
    }
}
