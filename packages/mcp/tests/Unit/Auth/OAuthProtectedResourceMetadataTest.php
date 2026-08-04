<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadata;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadataConfig;

#[CoversClass(OAuthProtectedResourceMetadataConfig::class)]
#[CoversClass(OAuthProtectedResourceMetadata::class)]
final class OAuthProtectedResourceMetadataTest extends TestCase
{
    #[Test]
    public function it_builds_rfc_9728_metadata_and_the_matching_challenge(): void
    {
        $config = new OAuthProtectedResourceMetadataConfig(
            resource: 'https://cms.example/mcp/write',
            authorizationServers: ['https://identity.example/tenant'],
            scopesSupported: ['content.read', 'content.write'],
            resourceDocumentation: 'https://cms.example/docs/mcp',
        );

        self::assertSame('/.well-known/oauth-protected-resource/mcp/write', $config->metadataPath());
        self::assertSame('https://cms.example/.well-known/oauth-protected-resource/mcp/write', $config->metadataUri());
        self::assertSame(
            'Bearer resource_metadata="https://cms.example/.well-known/oauth-protected-resource/mcp/write", scope="content.read content.write"',
            $config->challenge(),
        );
        self::assertSame(['header'], $config->toArray()['bearer_methods_supported']);

        $response = new OAuthProtectedResourceMetadata($config)->serve();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        self::assertSame($config->toArray(), \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function loopback_http_is_allowed_for_local_development(): void
    {
        $config = new OAuthProtectedResourceMetadataConfig(
            'http://127.0.0.1:8080/mcp/write',
            ['http://localhost:8081/oidc'],
        );

        self::assertSame(
            'http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp/write',
            $config->metadataUri(),
        );
    }

    #[Test]
    public function non_loopback_plain_http_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OAuthProtectedResourceMetadataConfig('http://cms.example/mcp/write', ['https://identity.example']);
    }

    #[Test]
    public function duplicate_or_malformed_scopes_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OAuthProtectedResourceMetadataConfig(
            'https://cms.example/mcp/write',
            ['https://identity.example'],
            ['content.read', 'content.read'],
        );
    }
}
