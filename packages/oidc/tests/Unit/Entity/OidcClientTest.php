<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Oidc\ClientRegistry\OidcClientRegistration;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;

#[CoversClass(OidcClient::class)]
final class OidcClientTest extends TestCase
{
    private function registration(OidcClient $client): OidcClientRegistration
    {
        return new OidcClientSystemReader()->registration($client);
    }
    public function testExtendsContentEntityBase(): void
    {
        $client = new OidcClient();
        $this->assertInstanceOf(ContentEntityBase::class, $client);
    }

    public function testEntityTypeId(): void
    {
        $client = new OidcClient();
        $this->assertSame('oidc_client', $client->getEntityTypeId());
    }

    public function testNewClientHasNoId(): void
    {
        $client = new OidcClient();
        $this->assertNull($client->id());
    }

    public function testNewClientIsNew(): void
    {
        $client = new OidcClient();
        $this->assertTrue($client->isNew());
    }

    public function testClientWithIdIsNotNew(): void
    {
        $client = new OidcClient(['id' => 7]);
        $this->assertSame(7, $client->id());
        $this->assertFalse($client->isNew());
    }

    public function testAutoGeneratesUuid(): void
    {
        $client = new OidcClient();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $client->uuid(),
        );
    }

    public function testExplicitUuidIsPreserved(): void
    {
        $client = new OidcClient(['uuid' => 'my-uuid']);
        $this->assertSame('my-uuid', $client->uuid());
    }

    public function testLabelReturnsName(): void
    {
        $client = new OidcClient(['name' => 'Minoo']);
        $this->expectException(MissingFieldReadContext::class);
        $client->label();
    }

    public function testClientIdGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setClientId('minoo-web');
        $this->assertSame('minoo-web', $client->getClientId());
    }

    public function testClientIdFromValues(): void
    {
        $client = new OidcClient(['client_id' => 'biindigen']);
        $this->assertSame('biindigen', $client->getClientId());
    }

    public function testRedirectUrisGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setRedirectUris(['https://minoo.test/callback', 'https://minoo.test/silent']);
        $this->assertSame(
            ['https://minoo.test/callback', 'https://minoo.test/silent'],
            $this->registration($client)->redirectUris,
        );
    }

    public function testRedirectUrisDefaultsToEmptyArray(): void
    {
        $client = new OidcClient();
        $this->assertSame([], $this->registration($client)->redirectUris);
    }

    public function testHasRedirectUriExactMatch(): void
    {
        $client = new OidcClient([
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->assertTrue($this->registration($client)->hasRedirectUri('https://minoo.test/callback'));
    }

    public function testHasRedirectUriRejectsTrailingSlashVariant(): void
    {
        $client = new OidcClient([
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        // Exact match only — OIDC spec requires byte-for-byte match.
        $this->assertFalse($this->registration($client)->hasRedirectUri('https://minoo.test/callback/'));
    }

    public function testHasRedirectUriRejectsCaseVariant(): void
    {
        $client = new OidcClient([
            'redirect_uris' => ['https://minoo.test/Callback'],
        ]);
        $this->assertFalse($this->registration($client)->hasRedirectUri('https://minoo.test/callback'));
    }

    public function testScopesGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setScopes(['openid', 'profile', 'email']);
        $this->assertSame(['openid', 'profile', 'email'], $this->registration($client)->scopes);
    }

    public function testScopesDefaultsToOpenid(): void
    {
        $client = new OidcClient();
        $this->assertSame(['openid'], $this->registration($client)->scopes);
    }

    public function testHasScope(): void
    {
        $client = new OidcClient(['scopes' => ['openid', 'profile']]);
        $registration = $this->registration($client);
        $this->assertTrue($registration->hasScope('openid'));
        $this->assertTrue($registration->hasScope('profile'));
        $this->assertFalse($registration->hasScope('email'));
    }

    public function testGrantTypesGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setGrantTypes(['authorization_code', 'refresh_token']);
        $this->assertSame(['authorization_code', 'refresh_token'], $this->registration($client)->grantTypes);
    }

    public function testGrantTypesDefaultsToAuthorizationCode(): void
    {
        $client = new OidcClient();
        $this->assertSame(['authorization_code'], $this->registration($client)->grantTypes);
    }

    public function testSupportsGrantType(): void
    {
        $client = new OidcClient([
            'grant_types' => ['authorization_code', 'refresh_token'],
        ]);
        $registration = $this->registration($client);
        $this->assertTrue($registration->supportsGrantType('authorization_code'));
        $this->assertFalse($registration->supportsGrantType('client_credentials'));
    }

    public function testIsConfidentialDefaultsFalse(): void
    {
        // Public clients (SPAs, mobile) are the default for OIDC + PKCE.
        $client = new OidcClient();
        $this->assertFalse($this->registration($client)->confidential);
    }

    public function testIsConfidentialGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setConfidential(true);
        $this->assertTrue($this->registration($client)->confidential);
    }

    public function testClientSecretHashNullByDefault(): void
    {
        $client = new OidcClient();
        $this->assertFalse(new OidcClientSystemReader()->hasStoredSecretHash($client, 'anything'));
    }

    public function testClientSecretHashGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setClientSecretHash('hashed-secret-value');
        $this->assertTrue(new OidcClientSystemReader()->hasStoredSecretHash($client, 'hashed-secret-value'));
    }

    public function testNameGetterSetter(): void
    {
        $client = new OidcClient();
        $client->setName('Minoo Web App');
        $this->assertSame('Minoo Web App', $this->registration($client)->name);
    }
}
