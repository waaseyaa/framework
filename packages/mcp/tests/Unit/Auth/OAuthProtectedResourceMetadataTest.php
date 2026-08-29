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

        $response = new OAuthProtectedResourceMetadata($config)->response();
        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->contentType);
        self::assertSame('public, max-age=300', $response->headers['Cache-Control']);
        self::assertSame($config->toArray(), \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR));
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

    /**
     * Each rejected URI component must be rejected ON ITS OWN.
     *
     * The construction guard was one multi-argument `isset()`, which is a
     * conjunction: it fired only when userinfo AND password AND query AND
     * fragment were all present, so every single-component case below was
     * accepted. That matters beyond tidiness — `metadataUri()` rebuilds the
     * authority from scheme/host/port and the path, dropping any query or
     * userinfo, so an accepted `https://cms.example/mcp/write?tenant=a` would
     * advertise a discovery URI that is not the resource, while `resource` —
     * the audience handed to `OAuthAccessTokenValidatorInterface::validate()`
     * — kept the query. The two identifiers a client reconciles would disagree.
     *
     * Each case carries exactly one offending component, so a conjunction
     * cannot pass them.
     */
    #[Test]
    public function each_forbidden_uri_component_is_refused_on_its_own(): void
    {
        foreach ([
            'query only' => 'https://cms.example/mcp/write?tenant=a',
            'fragment only' => 'https://cms.example/mcp/write#frag',
            'userinfo only' => 'https://operator@cms.example/mcp/write',
        ] as $label => $uri) {
            try {
                new OAuthProtectedResourceMetadataConfig($uri, ['https://identity.example']);
                self::fail("Expected {$label} ({$uri}) to be refused.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('without credentials, query, or fragment', $e->getMessage());
            }
        }
    }

    /** The same guard runs over authorization_servers and resource_documentation. */
    #[Test]
    public function forbidden_components_are_refused_on_every_guarded_field(): void
    {
        try {
            new OAuthProtectedResourceMetadataConfig(
                'https://cms.example/mcp/write',
                ['https://identity.example?tenant=a'],
            );
            self::fail('Expected a query in authorization_servers to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('authorization_servers', $e->getMessage());
        }

        try {
            new OAuthProtectedResourceMetadataConfig(
                'https://cms.example/mcp/write',
                ['https://identity.example'],
                [],
                'https://cms.example/docs#mcp',
            );
            self::fail('Expected a fragment in resource_documentation to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('resource_documentation', $e->getMessage());
        }
    }
}
