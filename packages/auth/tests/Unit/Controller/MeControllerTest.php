<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\MeController;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

#[CoversClass(MeController::class)]
final class MeControllerTest extends TestCase
{
    #[Test]
    public function returns_401_for_anonymous_user(): void
    {
        $controller = new MeController();
        $request = Request::create('/api/user/me');
        $request->attributes->set('_account', new AnonymousUser());

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns_200_with_user_data_for_authenticated_user(): void
    {
        $controller = new MeController();
        $user = new User(['uid' => 42, 'name' => 'admin', 'mail' => 'admin@example.com', 'roles' => ['admin']]);
        $request = Request::create('/api/user/me');
        $request->attributes->set('_account', $user);

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(42, $data['data']['id']);
        $this->assertSame('admin', $data['data']['name']);
        $this->assertSame('admin@example.com', $data['data']['email']);
    }
}
