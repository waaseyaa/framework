<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\Tests\Support\PasswordChangeFixture;

final class ResetPasswordControllerTest extends TestCase
{
    private function request(array $body): Request
    {
        return Request::create('/api/auth/reset-password', 'POST', content: json_encode($body));
    }

    public function testCanonicalResetValidatesInputWithoutChangingCredentialsOrToken(): void
    {
        [$repo, $manager, $fields, , $tokens, $change] = new PasswordChangeFixture()->fixture();
        $controller = new ResetPasswordController($manager, $tokens, $change);
        $token = $tokens->createToken(1, 'password_reset', 300);
        foreach ([['short', 'short', 'password_too_short'], ['new-password', 'different', 'passwords_do_not_match']] as [$password, $confirmation, $error]) {
            $response = $controller($this->request(['token' => $token, 'password' => $password, 'password_confirmation' => $confirmation]));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame($error, json_decode($response->getContent(), true)['error']);
            self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
            self::assertSame(0, $fields->sessionIdentity($repo->find(1))->generation);
            self::assertNotNull($tokens->validateToken($token, 'password_reset'));
        }
    }

    public function testInvalidTokenCannotChangeCredentialsOrGeneration(): void
    {
        [$repo, $manager, $fields, , $tokens, $change] = new PasswordChangeFixture()->fixture();
        $response = new ResetPasswordController($manager, $tokens, $change)($this->request([
            'token' => 'invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ]));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
        self::assertSame(0, $fields->sessionIdentity($repo->find(1))->generation);
    }

    public function testTokenForMissingUserIsRejectedWithoutConsumption(): void
    {
        [, $manager, $fields, , $tokens, $change] = new PasswordChangeFixture()->fixture();
        $token = $tokens->createToken(999, 'password_reset', 300);
        $response = new ResetPasswordController($manager, $tokens, $change)($this->request([
            'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ]));
        self::assertSame(422, $response->getStatusCode());
        self::assertNotNull($tokens->validateToken($token, 'password_reset'));
    }

    public function testSuccessfulResetRevokesSiblingTokensAndClearsIdentity(): void
    {
        [$repo, $manager, $fields, , $tokens, $change] = new PasswordChangeFixture()->fixture();
        $token = $tokens->createToken(1, 'password_reset', 300);
        $_SESSION = [\Waaseyaa\User\Session\AuthenticatedSession::USER_ID_KEY => 1, 'waaseyaa_pending_2fa_uid' => 1];
        $response = new ResetPasswordController($manager, $tokens, $change)($this->request([
            'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(password_verify('new-password', $fields->credentials($repo->find(1))->passwordHash));
        self::assertSame(1, $fields->sessionIdentity($repo->find(1))->generation);
        self::assertNull($tokens->validateToken($token, 'password_reset'));
        self::assertArrayNotHasKey(\Waaseyaa\User\Session\AuthenticatedSession::USER_ID_KEY, $_SESSION);
        self::assertArrayNotHasKey('waaseyaa_pending_2fa_uid', $_SESSION);
    }
}
