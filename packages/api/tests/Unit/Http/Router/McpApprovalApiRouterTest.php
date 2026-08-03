<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\McpApprovalController;
use Waaseyaa\Api\Http\Router\McpApprovalApiRouter;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;

#[CoversClass(McpApprovalApiRouter::class)]
final class McpApprovalApiRouterTest extends TestCase
{
    private function router(): McpApprovalApiRouter
    {
        $store = new class implements OperationApprovalStoreInterface {
            public function open(\Waaseyaa\Foundation\Audit\Approval\ApprovalTuple $tuple, string $correlationId, array $safeArguments): \Waaseyaa\Foundation\Audit\Approval\ApprovalRequest
            {
                throw new \LogicException('unused');
            }

            public function find(string $requestId): ?\Waaseyaa\Foundation\Audit\Approval\ApprovalRequest
            {
                return null;
            }

            public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
            {
                return new ApprovalRequestPage([]);
            }

            public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): \Waaseyaa\Foundation\Audit\Approval\ApprovalRequest
            {
                throw new \LogicException('unused');
            }

            public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
            {
                return false;
            }
        };

        return new McpApprovalApiRouter(new McpApprovalController(
            storeResolver: static fn(): OperationApprovalStoreInterface => $store,
        ));
    }

    #[Test]
    public function it_supports_only_approval_controller_references(): void
    {
        $router = $this->router();

        $supported = Request::create('/api/mcp/approvals', 'GET');
        $supported->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpApprovalController::index');
        self::assertTrue($router->supports($supported));

        $other = Request::create('/api/mcp/tools', 'GET');
        $other->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools');
        self::assertFalse($router->supports($other));

        $none = Request::create('/api/mcp/approvals', 'GET');
        self::assertFalse($router->supports($none));
    }

    #[Test]
    public function it_dispatches_index(): void
    {
        $request = Request::create('/api/mcp/approvals', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpApprovalController::index');

        $response = $this->router()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['data']);
    }

    #[Test]
    public function it_dispatches_decide_with_the_route_id(): void
    {
        // No Origin header: the controller's origin gate refuses with 403 —
        // proof the request reached decide() with the route id in play.
        $request = Request::create('/api/mcp/approvals/apr_0123456789abcdef0123456789abcdef/decision', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpApprovalController::decide');
        $request->attributes->set('id', 'apr_0123456789abcdef0123456789abcdef');

        $response = $this->router()->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function it_refuses_an_unknown_action(): void
    {
        $request = Request::create('/api/mcp/approvals', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpApprovalController::nuke');

        $response = $this->router()->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }
}
