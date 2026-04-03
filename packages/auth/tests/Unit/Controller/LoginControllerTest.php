<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createMock(EntityTypeManager::class);

        if ($storage !== null) {
            $manager->method('getStorage')->willReturn($storage);
        }

        return $manager;
    }

    private function makeController(
        ?EntityTypeManager $entityTypeManager = null,
        ?RateLimiter $rateLimiter = null,
    ): LoginController {
        return new LoginController(
            entityTypeManager: $entityTypeManager ?? $this->makeEntityTypeManager(),
            rateLimiter: $rateLimiter ?? new RateLimiter(),
        );
    }

    private function makeRequest(array $body = [], string $ip = '127.0.0.1'): Request
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => $ip],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_400_when_username_is_empty(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest(['username' => '', 'password' => 'secret']));

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
        $this->assertStringContainsString('username and password are required', $data['errors'][0]['detail']);
    }

    #[Test]
    public function returns_400_when_password_is_empty(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest(['username' => 'alice', 'password' => '']));

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
    }

    #[Test]
    public function returns_400_when_body_is_missing(): void
    {
        $controller = $this->makeController();

        $request = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            '',
        );

        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
    }

    #[Test]
    public function returns_429_when_rate_limited(): void
    {
        $rateLimiter = new RateLimiter();
        $ip = '127.0.0.1';
        $key = 'login:' . $ip;

        // Pre-fill 5 hits to exhaust the limit
        for ($i = 0; $i < 5; $i++) {
            $rateLimiter->hit($key, 60);
        }

        $controller = $this->makeController(rateLimiter: $rateLimiter);

        $response = $controller($this->makeRequest(['username' => 'alice', 'password' => 'secret'], $ip));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('429', $data['errors'][0]['status']);
        $this->assertStringContainsString('Too many login attempts', $data['errors'][0]['detail']);
    }
}
