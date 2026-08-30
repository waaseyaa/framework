<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Controller\VerifyTwoFactorController;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;

#[CoversClass(VerifyTwoFactorController::class)]
final class VerifyTwoFactorControllerTest extends TestCase
{
    public function testHappyPathAcceptsCurrentTotp(): void
    {
        $service = TwoFactorTestKit::makeService();
        $user = TwoFactorTestKit::makeUser();
        $manager = new TwoFactorManager();
        $secret = $manager->generateSecret();
        // Pre-enable for the user (mutate the entity directly).
        $user->setTwoFactorSecret($secret);
        $user->setTwoFactorRecoveryCodesHash([]);
        $rateLimiter = TwoFactorTestKit::makeRateLimiter();
        $controller = new VerifyTwoFactorController($service, $rateLimiter, TwoFactorTestKit::makeEntityTypeManager(), new UserInternalFieldReaderFixture());
        $request = TwoFactorTestKit::makeAuthenticatedRequest($user, ['code' => $manager->getCurrentCode($secret)]);

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($body['data']['attributes']['verified']);
        $this->assertSame([], $rateLimiter->hits, 'success must not consume rate-limit budget');
    }

    public function testWrongCodeReturns401AndConsumesBudget(): void
    {
        $user = TwoFactorTestKit::makeUser();
        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFactorRecoveryCodesHash([]);
        $rateLimiter = TwoFactorTestKit::makeRateLimiter();
        $controller = new VerifyTwoFactorController(TwoFactorTestKit::makeService(), $rateLimiter, TwoFactorTestKit::makeEntityTypeManager(), new UserInternalFieldReaderFixture());
        $request = TwoFactorTestKit::makeAuthenticatedRequest($user, ['code' => '000000']);

        $response = $controller($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertCount(1, $rateLimiter->hits);
        $this->assertSame(60, $rateLimiter->hits[0]['ttl']);
    }

    public function testRateLimitedReturns429(): void
    {
        $user = TwoFactorTestKit::makeUser();
        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');
        $rateLimiter = TwoFactorTestKit::makeRateLimiter(limited: true);
        $controller = new VerifyTwoFactorController(TwoFactorTestKit::makeService(), $rateLimiter, TwoFactorTestKit::makeEntityTypeManager(), new UserInternalFieldReaderFixture());
        $request = TwoFactorTestKit::makeAuthenticatedRequest($user, ['code' => '123456']);

        $response = $controller($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
    }

    public function testNotEnabledReturns400(): void
    {
        $controller = new VerifyTwoFactorController(TwoFactorTestKit::makeService(), TwoFactorTestKit::makeRateLimiter(), TwoFactorTestKit::makeEntityTypeManager(), new UserInternalFieldReaderFixture());
        $request = TwoFactorTestKit::makeAuthenticatedRequest(TwoFactorTestKit::makeUser(), ['code' => '123456']);

        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPendingLoginIssuesGenerationBoundSession(): void
    {
        $_SESSION = ['waaseyaa_pending_2fa_uid' => 42];
        $user = TwoFactorTestKit::makeUser();
        $manager = new TwoFactorManager();
        $secret = $manager->generateSecret();
        $user->setTwoFactorSecret($secret);
        $user->setTwoFactorRecoveryCodesHash([]);
        $entityTypeManager = TwoFactorTestKit::makeEntityTypeManager([42 => $user]);
        $controller = new VerifyTwoFactorController(
            TwoFactorTestKit::makeService($entityTypeManager),
            TwoFactorTestKit::makeRateLimiter(),
            $entityTypeManager,
            new UserInternalFieldReaderFixture(),
        );
        $request = TwoFactorTestKit::makeAuthenticatedRequest(null, ['code' => $manager->getCurrentCode($secret)]);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(42, $_SESSION['waaseyaa_uid']);
        self::assertSame(0, $_SESSION['waaseyaa_session_generation']);
        self::assertArrayNotHasKey('waaseyaa_pending_2fa_uid', $_SESSION);
    }
}
