<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Http\Router\EngagementApiRouter;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Engagement\Tests\Fixtures\ServiceEnvironment;
use Waaseyaa\User\AnonymousUser;

require_once dirname(__DIR__, 3) . '/engagement/tests/Fixtures/ServiceEnvironment.php';

/** HTTP boundary coverage over the shared real-SQLite engagement service fixture. */
#[CoversNothing]
final class EngagementHttpIntegrationTest extends TestCase
{
    #[Test]
    public function commentLifecycleAndLikeContractRunAgainstBothSqlLayouts(): void
    {
        foreach ([false, true] as $columnLayout) {
            $env = new ServiceEnvironment($columnLayout);
            $router = new EngagementApiRouter($env->service);
            $root = $env->rootReference;
            $alice = $env->alice;

            $this->assertError(401, 'AUTH_REQUIRED', $this->dispatch($router, 'like', 'PUT', $root, ['liked' => true]));
            $this->assertError(422, 'VALIDATION_FAILED', $this->dispatch($router, 'like', 'PUT', $root, ['liked' => 'true'], $alice));
            $this->assertError(422, 'VALIDATION_FAILED', $this->dispatch($router, 'like', 'PUT', $root, ['liked' => true, 'actorId' => 2], $alice));

            $liked = $this->dispatch($router, 'like', 'PUT', $root, ['liked' => true], $alice);
            self::assertSame(200, $liked->getStatusCode());
            self::assertSame(['liked' => true, 'count' => 1], $this->data($liked));
            $summary = $this->dispatch($router, 'summary', 'GET', $root, null, $alice);
            self::assertSame(['root' => ['type' => $root->type, 'id' => $root->id], 'liked' => true, 'likeCount' => 1, 'commentCount' => 0, 'topLevelCount' => 0], $this->data($summary));
            self::assertSame(200, $this->dispatch($router, 'like', 'PUT', $root, ['liked' => false], $alice)->getStatusCode());

            $keyResponse = $this->dispatch($router, 'commentKey', 'POST', $root, [], $alice);
            self::assertSame(200, $keyResponse->getStatusCode());
            $key = $this->data($keyResponse)['submissionKey'];
            self::assertIsString($key);
            $created = $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'first', 'submissionKey' => $key], $alice);
            self::assertSame(201, $created->getStatusCode());
            $comment = $this->data($created);
            self::assertSame('first', $comment['text']);
            self::assertSame(1, $comment['revision']);
            self::assertSame(200, $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'first', 'submissionKey' => $key], $alice)->getStatusCode());
            self::assertSame(422, $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'first', 'submissionKey' => $key, 'authorId' => 2], $alice)->getStatusCode());

            $uuid = $comment['uuid'];
            $edited = $this->dispatch($router, 'edit', 'PATCH', $root, ['text' => 'edited', 'expectedRevision' => 1], $alice, $uuid);
            self::assertSame(200, $edited->getStatusCode());
            self::assertSame('edited', $this->data($edited)['text']);
            self::assertSame(409, $this->dispatch($router, 'edit', 'PATCH', $root, ['text' => 'stale', 'expectedRevision' => 1], $alice, $uuid)->getStatusCode());

            $removed = $this->dispatch($router, 'remove', 'DELETE', $root, null, $alice, $uuid);
            self::assertSame(204, $removed->getStatusCode());
            self::assertSame('', $removed->getContent());
            self::assertSame(['no-store', 'private'], array_map('trim', explode(',', (string) $removed->headers->get('Cache-Control'))));
            self::assertSame(404, $this->dispatch($router, 'show', 'GET', $root, null, $alice, $uuid)->getStatusCode());

            $env->policy->setRootVisible(false);
            self::assertSame(404, $this->dispatch($router, 'summary', 'GET', $root, null, $alice)->getStatusCode());
        }
    }

    #[Test]
    public function replyPagingAndStrictHttpParsingPreserveServiceBoundaries(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        $root = $env->rootReference;
        $alice = $env->alice;
        $key = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, [], $alice))['submissionKey'];
        $parent = $this->data($this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'parent', 'submissionKey' => $key], $alice));
        $replyKey = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, ['parentUuid' => $parent['uuid']], $alice))['submissionKey'];
        $reply = $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'reply', 'submissionKey' => $replyKey, 'parentUuid' => $parent['uuid']], $alice);
        self::assertSame(201, $reply->getStatusCode());
        $page = $this->dispatch($router, 'replies', 'GET', $root, null, $alice, $parent['uuid'], ['limit' => '1']);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('reply', $this->data($page)[0]['text']);
        self::assertSame(422, $this->dispatch($router, 'comments', 'GET', $root, null, $alice, null, ['limit' => '1.5'])->getStatusCode());
        self::assertSame(422, $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'x', 'submissionKey' => $replyKey], $alice, null, [], 'text/plain')->getStatusCode());
        self::assertSame(422, $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'x', 'submissionKey' => $replyKey], $alice, null, [], 'application/json', '{"text":"x","submissionKey":"k","surprise":true}')->getStatusCode());
        // Direct router validation is 422; the canonical kernel JSON parser owns
        // malformed-syntax 400 responses before this adapter is dispatched.
        self::assertSame(422, $this->dispatch($router, 'createComment', 'POST', $root, null, $alice, null, [], 'application/json', '{"text":')->getStatusCode());
    }

    #[Test]
    public function blockedAuthorsBecomeNeutralPlaceholdersAndQuotaRefusalsAreRetryable(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        $root = $env->rootReference;
        $alice = $env->alice;
        $bob = $env->bob;
        $key = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, [], $alice))['submissionKey'];
        $parent = $this->data($this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'private parent', 'submissionKey' => $key], $alice));
        $replyKey = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, ['parentUuid' => $parent['uuid']], $bob))['submissionKey'];
        $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'visible reply', 'submissionKey' => $replyKey, 'parentUuid' => $parent['uuid']], $bob);
        $env->policy->setAliceBlocked(true);
        $page = $this->data($this->dispatch($router, 'comments', 'GET', $root, null, $bob));
        self::assertTrue($page[0]['placeholder']);
        self::assertNull($page[0]['authorId']);
        self::assertNull($page[0]['text']);
        self::assertSame(1, $page[0]['replyCount']);

        $quotaEnv = new ServiceEnvironment();
        $quotaRouter = new EngagementApiRouter($quotaEnv->service);
        $quotaRoot = $quotaEnv->rootReference;
        for ($index = 0; $index < 6; ++$index) {
            $submissionKey = $this->data($this->dispatch($quotaRouter, 'commentKey', 'POST', $quotaRoot, [], $quotaEnv->alice))['submissionKey'];
            $response = $this->dispatch($quotaRouter, 'createComment', 'POST', $quotaRoot, ['text' => 'attempt ' . $index, 'submissionKey' => $submissionKey], $quotaEnv->alice);
        }
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('RATE_LIMITED', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'][0]['code']);
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    #[Test]
    public function accountResolutionAnonymousReadsAndTargetDisclosureAreBounded(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        $root = $env->rootReference;
        $request = $this->request($router, 'like', 'PUT', $root, ['liked' => true], $env->alice);
        $request->attributes->set('_account', $env->bob);
        $this->assertError(401, 'AUTH_REQUIRED', $router->handle($request));

        $entityAccount = $this->request($router, 'like', 'PUT', $root, ['liked' => true]);
        $entityAccount->attributes->set('_account', $env->aliceEntity);
        $this->assertError(401, 'AUTH_REQUIRED', $router->handle($entityAccount));

        $anonymous = new AnonymousUser();
        self::assertSame(200, $this->dispatch($router, 'summary', 'GET', $root, null, $anonymous)->getStatusCode());
        self::assertSame(200, $this->dispatch($router, 'comments', 'GET', $root, null, $anonymous)->getStatusCode());
        $summaryRequest = Request::create('/engagement/v1/summaries', 'POST', [], [], [], [], '{"roots":[]}');
        $summaryRequest->headers->set('Content-Type', 'application/vnd.api+json');
        $summaryRequest->attributes->set('_engagement_action', 'summaries');
        $summaryRequest->attributes->set('_controller', EngagementApiRouter::class . '::handle');
        $summaryRequest->attributes->set('_authorization_principal', $anonymous);
        $summaryRequest->attributes->set('_account', $anonymous);
        $summaryRequest->attributes->set('_route_object', new Route('/engagement/v1/summaries', [], [], ['_engagement' => true, '_csrf' => false]));
        $terminal = new class($router) implements HttpHandlerInterface {
            public function __construct(private readonly EngagementApiRouter $router) {}
            public function handle(Request $request): Response { return $this->router->handle($request); }
        };
        self::assertSame(200, (new CsrfMiddleware())->process($summaryRequest, $terminal)->getStatusCode());

        $key = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, [], $env->alice))['submissionKey'];
        $comment = $this->data($this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'visible', 'submissionKey' => $key], $env->alice));
        $forbidden = $this->dispatch($router, 'remove', 'DELETE', $root, null, $env->bob, $comment['uuid']);
        self::assertSame(403, $forbidden->getStatusCode());
        $missing = $this->dispatch($router, 'show', 'GET', $root, null, $env->alice, '00000000-0000-4000-8000-000000000000');
        $env->policy->setRootVisible(false);
        $hidden = $this->dispatch($router, 'show', 'GET', $root, null, $env->alice, $comment['uuid']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame((string) $missing->getContent(), (string) $hidden->getContent());
    }

    #[Test]
    public function strictNumericCursorModerationAndExpiredSubmissionFailuresMapToContract(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        $root = $env->rootReference;
        $key = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, [], $env->alice))['submissionKey'];
        $comment = $this->data($this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'moderate me', 'submissionKey' => $key], $env->alice));
        foreach ([['limit' => ['1']], ['cursor' => ['opaque']]] as $query) {
            self::assertSame(422, $this->dispatch($router, 'comments', 'GET', $root, null, $env->alice, null, $query)->getStatusCode());
        }
        self::assertSame(400, $this->dispatch($router, 'comments', 'GET', $root, null, $env->alice, null, ['cursor' => 'malformed-token'])->getStatusCode());
        foreach ([1.5, '1', 0, -1] as $revision) {
            self::assertSame(422, $this->dispatch($router, 'edit', 'PATCH', $root, ['text' => 'bad', 'expectedRevision' => $revision], $env->alice, $comment['uuid'])->getStatusCode());
        }
        foreach (['', str_repeat('r', 501)] as $reason) {
            self::assertSame(422, $this->dispatch($router, 'moderateRemove', 'POST', $root, ['reason' => $reason], $env->bob, $comment['uuid'])->getStatusCode());
        }
        self::assertSame(403, $this->dispatch($router, 'moderateRemove', 'POST', $root, ['reason' => 'reviewed'], $env->bob, $comment['uuid'])->getStatusCode());
        $env->policy->moderation = true;
        self::assertSame(503, $this->dispatch($router, 'moderateRemove', 'POST', $root, ['reason' => 'reviewed'], $env->alice, $comment['uuid'])->getStatusCode());

        $expiredKey = $this->data($this->dispatch($router, 'commentKey', 'POST', $root, [], $env->alice))['submissionKey'];
        $env->now += \Waaseyaa\Engagement\EngagementTokens::SUBMISSION_TTL + 1;
        self::assertSame(409, $this->dispatch($router, 'createComment', 'POST', $root, ['text' => 'too late', 'submissionKey' => $expiredKey], $env->alice)->getStatusCode());
    }

    #[Test]
    public function authorizedModeratorRemovalRecordsTheModeratorAndErasesText(): void
    {
        foreach ([false, true] as $columnLayout) {
            $env = new ServiceEnvironment($columnLayout);
            $comment = $env->service->createComment($env->alice, $env->rootReference, 'remove through moderation', $env->service->issueCommentKey($env->alice, $env->rootReference));
            $env->policy->moderation = true;
            $audit = $this->createMock(\Waaseyaa\Audit\Contract\AuditWriterInterface::class);
            $audit->expects(self::once())->method('record')->with(self::callback(static function ($event) use ($comment): bool {
                return $event->accountUid === 2 && $event->entityUuid === $comment->uuid
                    && $event->attributes === ['operation' => 'moderator-removal', 'reason' => 'reviewed'];
            }));
            $service = new \Waaseyaa\Engagement\EngagementService(
                $env->database, $env->entities,
                new \Waaseyaa\Engagement\EngagementAccess($env->database, $env->entities, $env->accessHandler, $env->policy),
                $env->scope, $env->tokens,
                new \Waaseyaa\Engagement\EngagementRateLimiter($env->database, 'http-moderation-test', sys_get_temp_dir()),
                $env->reads, $audit, fn (): int => $env->now,
            );
            $router = new EngagementApiRouter($service);
            $response = $this->dispatch($router, 'moderateRemove', 'POST', $env->rootReference, ['reason' => 'reviewed'], $env->bob, $comment->uuid);
            self::assertSame(204, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            $this->assertError(404, 'TARGET_UNAVAILABLE', $this->dispatch($router, 'show', 'GET', $env->rootReference, null, $env->alice, $comment->uuid));
            $env->database->transactional(fn () => $env->scope->runMigration(function () use ($env, $comment): void {
                $stored = $env->entities->getRepository('comment')->findBy(['uuid' => $comment->uuid])[0];
                self::assertSame('', $stored->get('body'));
                self::assertSame(1, (int) $stored->get('user_id'));
                self::assertSame(2, (int) $stored->get('removal_actor_id'));
                self::assertSame('moderator', $stored->get('removal_kind'));
            }));
        }
    }

    #[Test]
    public function headResponsesHaveNoBodyForSuccessAndFailureStatuses(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        foreach ([
            [$this->request($router, 'summary', 'HEAD', $env->rootReference, null, $env->alice), 200],
            [$this->request($router, 'summary', 'HEAD', new RootReference('service_root', 9999), null, $env->alice), 404],
            [$this->request($router, 'comments', 'HEAD', $env->rootReference, null, $env->alice, ['cursor' => ['invalid']]), 422],
        ] as [$request, $status]) {
            $response = $router->handle($request);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('', $response->getContent());
        }
    }

    #[Test]
    public function csrfMiddlewareGuardsMatchedEngagementWritesAndKeepsPrivacyHeaders(): void
    {
        $env = new ServiceEnvironment();
        $router = new EngagementApiRouter($env->service);
        $token = 'synthetic-csrf-token';
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $previous = $_SESSION;
        $_SESSION['_csrf_token'] = $token;
        try {
            $invoke = function (?string $header, bool $anonymous = false) use ($router, $env): Response {
                $request = Request::create('/engagement/v1/targets/service_root/1/like', 'PUT', [], [], [], [], '{"liked":true}');
                $request->headers->set('Content-Type', 'application/json');
                if ($header !== null) { $request->headers->set('X-CSRF-Token', $header); }
                $request->attributes->set('_engagement_action', 'like');
                $request->attributes->set('_controller', EngagementApiRouter::class . '::handle');
                $request->attributes->set('type', 'service_root');
                $request->attributes->set('id', '1');
                $request->attributes->set('_route_object', new Route('/engagement/v1/targets/{type}/{id}/like', [], [], ['_engagement' => true, '_csrf' => true]));
                $principal = $anonymous ? new AuthorizationPrincipal(0, false, [], [], 'csrf-anonymous') : $env->alice;
                $request->attributes->set('_authorization_principal', $principal);
                $request->attributes->set('_account', $principal);
                $final = new class($router) implements HttpHandlerInterface {
                    public function __construct(private readonly EngagementApiRouter $router) {}
                    public function handle(Request $request): Response { return $this->router->handle($request); }
                };
                $csrf = new CsrfMiddleware();
                $inner = new class($csrf, $final) implements HttpHandlerInterface {
                    public function __construct(private readonly CsrfMiddleware $middleware, private readonly HttpHandlerInterface $next) {}
                    public function handle(Request $request): Response { return $this->middleware->process($request, $this->next); }
                };
                return (new \Waaseyaa\Api\Middleware\EngagementResponsePrivacyMiddleware())->process($request, $inner);
            };
            foreach ([null, 'wrong'] as $invalid) {
                $response = $invoke($invalid);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame(['no-store', 'private'], array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));
            }
            self::assertSame(403, $invoke(null, true)->getStatusCode(), 'Transport CSRF refusal precedes domain authentication.');
            $this->assertError(401, 'AUTH_REQUIRED', $invoke($token, true));
            self::assertSame(0, $env->service->summaries($env->alice, [$env->rootReference])[0]['likeCount']);
            $valid = $invoke($token);
            self::assertSame(200, $valid->getStatusCode());
            self::assertSame(['liked' => true, 'count' => 1], $this->data($valid));

            $read = Request::create('/engagement/v1/summaries', 'POST', [], [], [], [], '{"roots":[]}');
            $read->headers->set('Content-Type', 'application/vnd.api+json');
            $read->attributes->set('_engagement_action', 'summaries');
            $read->attributes->set('_controller', EngagementApiRouter::class . '::handle');
            $read->attributes->set('_route_object', new Route('/engagement/v1/summaries', [], [], ['_engagement' => true, '_csrf' => false]));
            $read->attributes->set('_authorization_principal', $env->alice);
            $read->attributes->set('_account', $env->alice);
            $terminal = new class($router) implements HttpHandlerInterface {
                public function __construct(private readonly EngagementApiRouter $router) {}
                public function handle(Request $request): Response { return $this->router->handle($request); }
            };
            $response = (new CsrfMiddleware())->process($read, $terminal);
            self::assertSame(200, $response->getStatusCode());
        } finally {
            $_SESSION = $previous;
        }
    }

    private function dispatch(EngagementApiRouter $router, string $action, string $method, RootReference $root, ?array $body, mixed $principal = null, ?string $uuid = null, array $query = [], string $contentType = 'application/vnd.api+json', ?string $raw = null): Response
    {
        return $router->handle($this->request($router, $action, $method, $root, $body, $principal, $query, $uuid, $contentType, $raw));
    }

    private function request(EngagementApiRouter $router, string $action, string $method, RootReference $root, ?array $body, mixed $principal = null, array $query = [], ?string $uuid = null, string $contentType = 'application/vnd.api+json', ?string $raw = null): Request
    {
        $path = '/engagement/v1/targets/' . $root->type . '/' . $root->id;
        if ($action === 'summary') { $path .= '/summary'; }
        elseif ($action === 'like') { $path .= '/like'; }
        elseif ($action === 'commentKey') { $path .= '/comment-keys'; }
        elseif (in_array($action, ['comments', 'createComment'], true)) { $path .= '/comments'; }
        elseif ($action === 'replies') { $path .= '/comments/' . $uuid . '/replies'; }
        elseif ($uuid !== null) { $path .= '/comments/' . $uuid; if ($action === 'moderateRemove') { $path .= '/moderation-removal'; } }
        $request = Request::create($path, $method, $query, [], [], [], $raw ?? ($body === null ? '' : json_encode((object) $body, JSON_THROW_ON_ERROR)));
        $request->headers->set('Content-Type', $contentType);
        $request->attributes->set('_engagement_action', $action);
        $request->attributes->set('_controller', EngagementApiRouter::class . '::handle');
        $request->attributes->set('type', $root->type);
        $request->attributes->set('id', (string) $root->id);
        if ($uuid !== null) { $request->attributes->set('uuid', $uuid); }
        if ($principal !== null) {
            $request->attributes->set('_authorization_principal', $principal);
            $request->attributes->set('_account', $principal);
        }
        return $request;
    }

    private function data(Response $response): array
    {
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $payload, (string) $response->getContent());
        return $payload['data'];
    }

    private function assertError(int $status, string $code, Response $response): void
    {
        self::assertSame($status, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((string) $status, $payload['errors'][0]['status']);
        self::assertSame($code, $payload['errors'][0]['code']);
        self::assertSame(['no-store', 'private'], array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));
    }
}
