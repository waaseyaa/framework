<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\StreamHttpClient;

#[CoversClass(StreamHttpClient::class)]
final class StreamHttpClientTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $client = new StreamHttpClient();

        $this->assertInstanceOf(HttpClientInterface::class, $client);
    }

    public function testCustomTimeout(): void
    {
        $client = new StreamHttpClient(timeout: 5.0);

        $this->assertInstanceOf(HttpClientInterface::class, $client);
    }
}
