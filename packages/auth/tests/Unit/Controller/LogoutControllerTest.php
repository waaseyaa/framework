<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LogoutController;

#[CoversClass(LogoutController::class)]
final class LogoutControllerTest extends TestCase
{
    #[Test]
    public function returns_200_with_logout_message(): void
    {
        $controller = new LogoutController();
        $request = Request::create('/api/auth/logout', 'POST');

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Logged out.', $data['meta']['message']);
    }
}
