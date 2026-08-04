<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Mcp\Admin\ServerConfigReadModel;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;

#[CoversClass(ServerConfigReadModel::class)]
final class ServerConfigReadModelTest extends TestCase
{
    // ── happy path: BearerTokenAuth with registered tokens ───────────────────

    #[Test]
    public function serverConfigReturnsFingerprintForEachToken(): void
    {
        $token1 = 'aaaa1111bbbb2222cccc3333dddd4444eeee5555ffff6666aaaa1111bbbb2222';
        $token2 = 'bbbb2222cccc3333dddd4444eeee5555ffff6666aaaa1111bbbb2222cccc3333';
        $token3 = 'cccc3333dddd4444eeee5555ffff6666aaaa1111bbbb2222cccc3333dddd4444';

        // BearerTokenAuth maps token_string → AccountInterface.
        // Token strings are the array keys; accounts are the values.
        $account = $this->makeAccount();
        $auth = new BearerTokenAuth(tokens: [
            $token1 => $account,
            $token2 => $account,
            $token3 => $account,
        ]);

        $model = new ServerConfigReadModel(auth: $auth);
        $snapshot = $model->serverConfig();

        $this->assertCount(3, $snapshot->registeredClients);

        foreach ($snapshot->registeredClients as $i => $client) {
            // Fingerprint must be exactly 16 lowercase hex chars.
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $client->tokenFingerprint);
        }
    }

    #[Test]
    public function serverConfigFingerprintMatchesSha256Prefix(): void
    {
        $plaintext = 'deadbeef12345678deadbeef12345678deadbeef12345678deadbeef12345678';
        $account = $this->makeAccount();
        $auth = new BearerTokenAuth(tokens: [$plaintext => $account]);

        $model = new ServerConfigReadModel(auth: $auth);
        $snapshot = $model->serverConfig();

        $expected = substr(hash('sha256', $plaintext), 0, 16);
        $this->assertSame($expected, $snapshot->registeredClients[0]->tokenFingerprint);
    }

    // ── NFR-003: no plaintext token in serialised response ───────────────────

    #[Test]
    public function serialisedResponseContainsNoPlaintextTokens(): void
    {
        $token1 = 'supersecret-token-64-hexchars-abcdef1234567890abcdef1234567890ab';
        $token2 = 'anothersecret-token-64-hexchars-fedcba9876543210fedcba9876543210fe';
        $token3 = 'thirdsecrettoken-64-hexcharssssss-0123456789abcdef0123456789abcdef';

        $account = $this->makeAccount();
        $auth = new BearerTokenAuth(tokens: [
            $token1 => $account,
            $token2 => $account,
            $token3 => $account,
        ]);

        $model = new ServerConfigReadModel(auth: $auth);
        $snapshot = $model->serverConfig();
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);

        // NFR-003: plaintext token strings must NEVER appear in the output.
        $this->assertStringNotContainsString($token1, $serialized);
        $this->assertStringNotContainsString($token2, $serialized);
        $this->assertStringNotContainsString($token3, $serialized);

        // Verify fingerprints ARE present.
        $this->assertStringContainsString(substr(hash('sha256', $token1), 0, 16), $serialized);
        $this->assertStringContainsString(substr(hash('sha256', $token2), 0, 16), $serialized);
        $this->assertStringContainsString(substr(hash('sha256', $token3), 0, 16), $serialized);
    }

    // ── non-BearerTokenAuth auth adapter → empty client list ─────────────────

    #[Test]
    public function serverConfigReturnsEmptyClientsForNonBearerAuth(): void
    {
        $otherAuth = new class implements McpAuthInterface {
            public function authenticate(?string $authorizationHeader): ?AccountInterface
            {
                return null;
            }
        };

        $model = new ServerConfigReadModel(auth: $otherAuth);
        $snapshot = $model->serverConfig();

        $this->assertSame([], $snapshot->registeredClients);
    }

    // ── transport / protocolVersion / serverCapabilities ─────────────────────

    #[Test]
    public function serverConfigReturnsCorrectStaticFields(): void
    {
        $model = new ServerConfigReadModel(auth: new BearerTokenAuth(tokens: []));
        $snapshot = $model->serverConfig();

        $this->assertSame('streamable-http', $snapshot->transport);
        $this->assertSame('2025-11-25', $snapshot->protocolVersion);
        $this->assertContains('tools', $snapshot->serverCapabilities);
    }

    // ── empty token map → empty client list ──────────────────────────────────

    #[Test]
    public function serverConfigReturnsEmptyClientsWhenNoTokensRegistered(): void
    {
        $auth = new BearerTokenAuth(tokens: []);
        $model = new ServerConfigReadModel(auth: $auth);
        $snapshot = $model->serverConfig();

        $this->assertSame([], $snapshot->registeredClients);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['admin']; }
        };
    }
}
