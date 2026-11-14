<?php

declare(strict_types=1);

/**
 * Installed-consumer engagement journey. The runner installs and seeds this
 * synthetic app; this file deliberately depends only on its installed tree.
 */
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Http\Router\EngagementApiRouter;
use Waaseyaa\Api\Middleware\EngagementResponsePrivacyMiddleware;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Middleware\CsrfMiddleware;

function journeyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function journeyFailure(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (EngagementException $failure) {
        journeyAssert($failure->errorCode === $code, "Expected {$code}; received {$failure->errorCode}.");
        return;
    }
    throw new RuntimeException("Expected engagement refusal {$code}.");
}

/** Dispatch an HTTP request using the admitted kernel snapshot and provider router. */
function journeyHttp(HttpKernel $kernel, string $method, string $path, AuthorizationPrincipal $actor, ?string $body = null): Response
{
    $request = Request::create($path, $method, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    $snapshot = $kernel->getRouteSnapshot();
    $routes = new WaaseyaaRouter(snapshot: $snapshot);
    $matched = $routes->matchRequest($request);
    $request->attributes->add($matched);
    $request->attributes->set('_route_object', $routes->getRouteCollection()->get((string) $matched['_route']));
    $request->attributes->set('_authorization_principal', $actor);
    $request->attributes->set('_account', $actor);
    $request->headers->set('X-CSRF-Token', (string) $_SESSION['_csrf_token']);
    $definition = null;
    foreach ($snapshot->routes as $candidate) {
        if ($candidate->name === $matched['_route']) {
            $definition = $candidate;
            break;
        }
    }
    if ($definition === null) {
        throw new RuntimeException('Matched route lacks its canonical definition.');
    }
    $services = new \Waaseyaa\Foundation\Kernel\KernelHandlerContainer($kernel->getProviders(), [])->explicitServices($request);
    $handler = new \Waaseyaa\Routing\RouteHandlerResolver($definition, $services)->resolveMatched();
    $request->attributes->set('_controller', $handler);

    $terminal = new class implements HttpHandlerInterface {
        public function handle(Request $request): Response
        {
            return new \Waaseyaa\Foundation\Http\ControllerDispatcher([])->dispatch($request);
        }
    };
    $csrf = new class ($terminal) implements HttpHandlerInterface {
        public function __construct(private HttpHandlerInterface $terminal) {}
        public function handle(Request $request): Response
        {
            return new CsrfMiddleware()->process($request, $this->terminal);
        }
    };
    return new EngagementResponsePrivacyMiddleware()->process($request, $csrf);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['_csrf_token'] = 'synthetic-installed-journey';
    $statePath = __DIR__ . '/state.json';
    $state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    journeyAssert(is_array($state) && count($state['roots'] ?? []) >= 2, 'Fixture state must contain two seeded roots.');

    $kernel = new HttpKernel(__DIR__);
    $kernel->bootForCli();
    $resolver = $kernel->getHttpServiceResolver();
    $service = $resolver->resolve(EngagementService::class);
    $entities = $kernel->getEntityTypeManager();
    $database = $resolver->resolve(DatabaseInterface::class);
    journeyAssert($service instanceof EngagementService, 'Engagement service was not resolved from the installed kernel.');
    journeyAssert($entities instanceof EntityTypeManagerInterface, 'Kernel entity manager is unavailable.');
    journeyAssert($database instanceof DatabaseInterface, 'Kernel database is unavailable.');
    journeyAssert($resolver->resolve(EngagementPolicyInterface::class) instanceof EngagementPolicyInterface, 'Consumer policy provider was not activated.');
    $handlers = $kernel->buildHandlerContainer();
    $api = $handlers->get(EngagementApiRouter::class);
    journeyAssert($api instanceof EngagementApiRouter, 'Engagement HTTP router was not resolved through kernel providers.');

    $alice = new AuthorizationPrincipal((int) $state['users']['alice'], true, [], [], 'fixture-alice');
    $bob = new AuthorizationPrincipal((int) $state['users']['bob'], true, [], [], 'fixture-bob');
    $featured = new AuthorizationPrincipal((int) $state['users']['featured'], true, [], [], 'fixture-featured');
    $anonymous = new AuthorizationPrincipal(0, false, [], [], 'fixture-anonymous');
    $rootA = new RootReference((string) $state['roots'][0]['type'], (int) $state['roots'][0]['id']);
    $rootB = new RootReference((string) $state['roots'][1]['type'], (int) $state['roots'][1]['id']);

    $assertions = 0;
    $like = $service->setLiked($alice, $rootA, true);
    journeyAssert($like->liked && $like->count === 1, 'First desired-state like must produce count one.');
    $assertions++;
    $retry = $service->setLiked($alice, $rootA, true);
    journeyAssert($retry->liked && $retry->count === 1, 'Retrying a desired-state like must not duplicate it.');
    $assertions++;
    $off = $service->setLiked($alice, $rootA, false);
    journeyAssert(!$off->liked && $off->count === 0, 'Desired-state unlike must remove the actor like.');
    $assertions++;

    $key = $service->issueCommentKey($alice, $rootA);
    $parent = $service->createComment($alice, $rootA, "  hello\r\nworld  ", $key);
    journeyAssert($parent->text === "hello\nworld" && $parent->revision === 1 && !$parent->placeholder, 'Comment creation/normalization contract failed.');
    $assertions++;
    $replay = $service->createComment($alice, $rootA, "hello\nworld", $key);
    journeyAssert($replay->uuid === $parent->uuid && $replay->replayed, 'Same-key retry must replay the original comment.');
    $assertions++;
    journeyFailure(fn() => $service->createComment($alice, $rootA, 'different body', $key), 'SUBMISSION_CONFLICT');
    $assertions++;

    $replyKey = $service->issueCommentKey($bob, $rootA, $parent->uuid);
    $reply = $service->createComment($bob, $rootA, 'reply', $replyKey, $parent->uuid);
    journeyAssert(count($service->replies($bob, $rootA, $parent->uuid)->items) === 1, 'Reply should be visible beneath its parent.');
    $assertions++;
    journeyFailure(fn() => $service->issueCommentKey($alice, $rootA, $reply->uuid), 'TARGET_UNAVAILABLE');
    $assertions++;
    journeyFailure(fn() => $service->issueCommentKey($bob, $rootB, $parent->uuid), 'TARGET_UNAVAILABLE');
    $assertions++;

    $edited = $service->editComment($alice, $parent->uuid, 'edited', (int) $parent->revision);
    journeyAssert($edited->text === 'edited' && $edited->revision === $parent->revision + 1, 'Optimistic edit must advance revision.');
    $assertions++;
    $noop = $service->editComment($alice, $parent->uuid, 'edited', (int) $edited->revision);
    journeyAssert($noop->revision === $edited->revision, 'No-op edit must preserve revision.');
    $assertions++;
    journeyFailure(fn() => $service->editComment($alice, $parent->uuid, 'stale', (int) $parent->revision), 'REVISION_CONFLICT');
    $assertions++;

    $summary = $service->summaries($bob, [$rootA, $rootB, $rootA]);
    journeyAssert(count($summary) === 2, 'Summary must deduplicate roots and include both requested roots.');
    $assertions++;
    journeyAssert($summary[0]['likeCount'] === 0 && $summary[0]['commentCount'] === 2 && $summary[0]['topLevelCount'] === 1, 'Grouped summary counts must include the reply and one top-level comment.');
    $assertions++;
    journeyFailure(fn() => $service->setLiked($featured, $rootA, true), 'ACTION_FORBIDDEN');
    $assertions++;
    journeyFailure(fn() => $service->setLiked($anonymous, $rootA, true), 'AUTH_REQUIRED');
    $assertions++;

    $rootPath = '/engagement/v1/targets/' . rawurlencode($rootA->type) . '/' . $rootA->id;
    $spoofed = journeyHttp($kernel, 'POST', $rootPath . '/comments', $alice, json_encode([
        'text' => 'spoofed author', 'submissionKey' => $service->issueCommentKey($alice, $rootA), 'user_id' => (int) $state['users']['featured'],
    ], JSON_THROW_ON_ERROR));
    journeyAssert($spoofed->getStatusCode() === 422, 'HTTP comment input must reject a forged user_id.');
    $assertions++;
    journeyAssert(
        $spoofed->headers->hasCacheControlDirective('private')
            && $spoofed->headers->hasCacheControlDirective('no-store'),
        'Engagement HTTP responses must be private and non-cacheable.',
    );
    $assertions++;

    $httpKey = $service->issueCommentKey($alice, $rootA);
    $httpBody = json_encode(['text' => 'HTTP created', 'submissionKey' => $httpKey], JSON_THROW_ON_ERROR);
    $httpCreated = journeyHttp($kernel, 'POST', $rootPath . '/comments', $alice, $httpBody);
    journeyAssert($httpCreated->getStatusCode() === 201, 'First canonical HTTP comment submission must return 201.');
    $assertions++;
    $httpReplay = journeyHttp($kernel, 'POST', $rootPath . '/comments', $alice, $httpBody);
    journeyAssert($httpReplay->getStatusCode() === 200, 'Canonical HTTP submission retry must return 200.');
    $assertions++;
    $httpConflict = journeyHttp($kernel, 'POST', $rootPath . '/comments', $alice, json_encode(['text' => 'different', 'submissionKey' => $httpKey], JSON_THROW_ON_ERROR));
    journeyAssert($httpConflict->getStatusCode() === 409, 'Canonical HTTP key reuse with different text must return 409.');
    $assertions++;

    $csrfPath = $rootPath . '/like';
    $csrfRequest = Request::create($csrfPath, 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"liked":true}');
    $csrfRoutes = new WaaseyaaRouter(snapshot: $kernel->getRouteSnapshot());
    $csrfMatch = $csrfRoutes->matchRequest($csrfRequest);
    $csrfRequest->attributes->add($csrfMatch);
    $csrfRequest->attributes->set('_route_object', $csrfRoutes->getRouteCollection()->get((string) $csrfMatch['_route']));
    $csrfResponse = new CsrfMiddleware()->process($csrfRequest, new class implements HttpHandlerInterface {
        public function handle(Request $request): Response
        {
            return new Response('', 204);
        }
    });
    journeyAssert($csrfResponse->getStatusCode() === 403, 'Canonical mutation route must enforce CSRF despite JSON content type.');
    $assertions++;

    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET root_visible = 0, generation = generation + 1 WHERE id = 1');
    journeyFailure(fn() => $service->comments($alice, $rootA), 'TARGET_UNAVAILABLE');
    $assertions++;
    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET root_visible = 1, alice_blocked = 1, generation = generation + 1 WHERE id = 1');
    $blockedPage = $service->comments($bob, $rootA);
    journeyAssert(
        count($blockedPage->items) === 1 && $blockedPage->items[0]->placeholder
        && $blockedPage->items[0]->text === null && $blockedPage->items[0]->authorId === null,
        'A blocked parent must be neutral while a permitted reply survives.',
    );
    $assertions++;
    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET alice_blocked = 0, generation = generation + 1 WHERE id = 1');
    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET alice_suspended = 1, generation = generation + 1 WHERE id = 1');
    journeyFailure(fn() => $service->setLiked($alice, $rootA, true), 'ACTION_FORBIDDEN');
    $assertions++;
    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET alice_suspended = 0, generation = generation + 1 WHERE id = 1');

    $moderatedKey = $service->issueCommentKey($alice, $rootB);
    $moderated = $service->createComment($alice, $rootB, 'moderation target', $moderatedKey);
    $database->getConnection()->executeStatement('UPDATE engagement_fixture_policy SET bob_moderator = 1, generation = generation + 1 WHERE id = 1');
    $service->moderateRemove($bob, $moderated->uuid, 'fixture moderation');
    journeyFailure(fn() => $service->getComment($bob, $moderated->uuid), 'TARGET_UNAVAILABLE');
    $assertions++;

    $service->removeComment($alice, $parent->uuid);
    $removed = $service->getComment($bob, $parent->uuid);
    journeyAssert($removed->placeholder && $removed->text === null, 'Removed parent must become a text-free placeholder while preserving replies.');
    $assertions++;
    journeyAssert(count($service->replies($bob, $rootA, $parent->uuid)->items) === 1, 'Reply must remain visible after parent removal.');
    $assertions++;

    try {
        $reaction = $entities->getRepository('reaction')->create([
            'user_id' => (int) $state['users']['alice'], 'target_type' => $rootA->type,
            'target_id' => $rootA->id, 'reaction_type' => 'like', 'root_uuid' => (string) $state['roots'][0]['uuid'],
        ]);
        $entities->getRepository('reaction')->save($reaction);
        throw new RuntimeException('Generic reaction repository write bypassed the engagement mutation scope.');
    } catch (EngagementException $failure) {
        journeyAssert($failure->errorCode === 'ACTION_FORBIDDEN', 'Generic repository write must fail closed with ACTION_FORBIDDEN.');
    }
    $assertions++;

    $snapshot = $kernel->getRouteSnapshot();
    $routeNames = [];
    $routeMetadata = [];
    foreach ($snapshot->routes as $route) {
        if (str_starts_with($route->name, 'api.engagement.')) {
            $routeNames[] = $route->name;
            $routeMetadata[$route->name] = $route;
        }
    }
    foreach (['summary', 'like', 'comment_keys', 'comments', 'create_comment', 'replies', 'comment_show', 'comment_edit', 'comment_remove', 'moderation_remove', 'summaries'] as $suffix) {
        journeyAssert(isset($routeMetadata['api.engagement.' . $suffix]), 'Missing canonical engagement route: ' . $suffix);
        $assertions++;
    }
    journeyAssert($routeMetadata['api.engagement.like']->options['_csrf'] === true, 'Like route must retain CSRF metadata.');
    $assertions++;
    journeyAssert($routeMetadata['api.engagement.comment_remove']->options['_engagement'] === true, 'Comment mutation route must retain engagement privacy metadata.');
    $assertions++;

    echo json_encode(['result' => 'pass', 'assertions' => $assertions, 'routes' => count($routeNames)], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, 'engagement journey failed: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
