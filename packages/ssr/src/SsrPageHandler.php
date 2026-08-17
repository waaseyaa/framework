<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\Route;
use Twig\Error\LoaderError;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\Markdown\EntityMarkdownPresenter;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Foundation\Http\BindingAwareHttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\ContentNegotiation\MediaTypeAcceptNegotiator;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaPageResultInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Path\PathAliasResolver;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper;
use Waaseyaa\SSR\Http\AppController\AppControllerArgumentResolver;
use Waaseyaa\SSR\Http\AppController\AppControllerMethodInvoker;
use Waaseyaa\SSR\Http\AppController\AppInvocationContext;
use Waaseyaa\SSR\PageComposition\EntityPageComposerInterface;
use Waaseyaa\SSR\PageComposition\EntityPageField;
use Waaseyaa\SSR\PageComposition\EntityPageRenderPayload;
use Waaseyaa\Workflows\EditorialVisibilityResolver;
use Waaseyaa\Workflows\WorkflowVisibilityFilter;

/**
 * Handles SSR page rendering: language negotiation, path alias resolution,
 * entity rendering, cache variant computation, and surrogate headers.
 *
 * Methods return structured results (arrays/strings) instead of sending
 * HTTP responses directly — the kernel is responsible for sending.
 */
final class SsrPageHandler
{
    private const string DISCOVERY_CONTRACT_VERSION = 'v1.0';
    private const string DISCOVERY_CONTRACT_STABILITY = 'stable';

    private readonly LoggerInterface $logger;
    private readonly LanguageResolver $languageResolver;
    private readonly AppControllerMethodInvoker $appControllerMethodInvoker;

    /**
     * @param list<AppControllerArgumentResolver> $appControllerArgumentResolvers
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?RenderCache $renderCache,
        private readonly CacheConfigResolver $cacheConfigResolver,
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly ?object $manifest = null,
        private readonly ?HttpServiceResolverInterface $serviceResolver = null,
        ?LoggerInterface $logger = null,
        private readonly ?\Waaseyaa\Access\Gate\GateInterface $gate = null,
        ?LanguageResolver $languageResolver = null,
        private readonly ?InertiaFullPageRendererInterface $inertiaFullPageRenderer = null,
        private readonly array $appControllerArgumentResolvers = [],
        ?AppControllerMethodInvoker $appControllerMethodInvoker = null,
        private readonly ?\Waaseyaa\Access\EntityAccessHandler $accessHandler = null,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private readonly ?AccountPrincipalFactoryInterface $principalFactory = null,
        private readonly ?InternalFieldVisibilityPolicy $internalFieldVisibility = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->languageResolver = $languageResolver ?? new LanguageResolver(serviceResolver: $this->serviceResolver);
        $this->appControllerMethodInvoker = $appControllerMethodInvoker ?? new AppControllerMethodInvoker(logger: $this->logger);
    }

    /**
     * Render a page for the given path.
     *
     * Returns an array with keys:
     *   'type' => 'html' or 'json'
     *   'status' => int
     *   'content' => string (for html) or array (for json)
     *   'headers' => array<string, string|list<string>>
     *
     * @return array{type: string, status: int, content: string|array, headers: array<string, string|list<string>>}
     */
    public function handleRenderPage(
        string $path,
        AccountInterface $account,
        HttpRequest $httpRequest,
        string $requestedViewMode = 'full',
    ): array {
        if ($this->fieldReadScope !== null
            && $this->principalFactory !== null
            && $this->fieldReadScope->current() === null
        ) {
            $principal = $this->principalFactory->fromAccount($account);

            return $this->fieldReadScope->run(
                $principal,
                fn(): array => $this->renderPage($path, $account, $httpRequest, $requestedViewMode),
            );
        }

        return $this->renderPage($path, $account, $httpRequest, $requestedViewMode);
    }

    /**
     * @return array{type: string, status: int, content: string|array, headers: array<string, string|list<string>>}
     */
    private function renderPage(
        string $path,
        AccountInterface $account,
        HttpRequest $httpRequest,
        string $requestedViewMode,
    ): array {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            try {
                $twig = SsrServiceProvider::createTwigEnvironment($this->projectRoot, $this->config);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Twig environment initialization failed: %s', $e->getMessage()));
                return $this->jsonResult(500, [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [[
                        'status' => '500',
                        'title' => 'Internal Server Error',
                        'detail' => 'SSR environment is unavailable.',
                    ]],
                ]);
            }
        }

        try {
            $cacheMaxAge = $this->cacheConfigResolver->resolveRenderCacheMaxAge();
            $cacheControlHeader = $this->cacheConfigResolver->cacheControlHeaderForRender($account, $cacheMaxAge);

            $normalizedPath = $path;
            if ($normalizedPath === '') {
                $normalizedPath = '/';
            }
            if (!str_starts_with($normalizedPath, '/')) {
                $normalizedPath = '/' . $normalizedPath;
            }

            // All paths (including '/') flow through language negotiation.
            $language = $this->languageResolver->resolveRenderLanguageAndAliasPath($normalizedPath, $httpRequest);
            $contentLangcode = $language['langcode'];
            $aliasLookupPath = $language['alias_path'];
            if ($aliasLookupPath === '/') {
                $response = new RenderController($twig)->renderPath('/', $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }

            $entityTypeId = null;
            $entityId = null;
            if (class_exists(PathAliasResolver::class) && $this->entityTypeManager->hasDefinition('path_alias')) {
                $aliasResolver = new PathAliasResolver(
                    $this->entityTypeManager->getRepository('path_alias'),
                );
                $resolved = $aliasResolver->resolve($aliasLookupPath, $contentLangcode);
                if ($resolved !== null) {
                    $entityTypeId = $resolved->entityTypeId;
                    $entityId = $resolved->entityId;
                }
            }
            // Canonical system path fallback: a published content entity is
            // reachable at /{entityTypeId}/{id} with no hand-created alias
            // (author-path FR-006). Scoped to the `content` group so non-content
            // types (user, taxonomy, …) are never served by guessing an id.
            if ($entityTypeId === null) {
                $canonical = $this->resolveCanonicalContentPath($aliasLookupPath);
                if ($canonical !== null) {
                    [$entityTypeId, $entityId] = $canonical;
                }
            }
            if ($entityTypeId === null || $entityId === null) {
                $renderController = new RenderController($twig);
                $pathResponse = $renderController->tryRenderPathTemplate($aliasLookupPath, $account);
                if ($pathResponse !== null) {
                    $headers = $this->extractHeaders($pathResponse);
                    $headers['Cache-Control'] = $cacheControlHeader;
                    return $this->htmlResult($pathResponse->getStatusCode(), (string) $pathResponse->getContent(), $headers);
                }
                $response = $renderController->renderNotFound($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }

            $entity = $this->loadByIdOrUuid($entityTypeId, $entityId);
            if ($entity === null) {
                $response = new RenderController($twig)->renderNotFound($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }

            $authorizationAccount = $this->authorizationAccount($account);
            $previewRequested = $this->isPreviewRequested($httpRequest);
            $visibilityResolver = new EditorialVisibilityResolver();
            $visibility = $authorizationAccount !== null
                ? $visibilityResolver->canRender($entity, $authorizationAccount, $previewRequested)
                : \Waaseyaa\Access\AccessResult::forbidden('Immutable authorization principal unavailable.');
            if ($visibility->isForbidden()) {
                $response = new RenderController($twig)->renderForbidden($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }
            // Canonical entity-level view-access gate for EVERY entity type
            // reachable via SSR render (audit M2 / R6 PR2, generalized to all
            // types in R8-a). Runs AFTER the editorial publish/preview gate
            // above: a node must be published-or-previewable AND pass the
            // per-entity AccessPolicy view check — neither gate alone is
            // sufficient. See {@see shouldDenyEntityRender()}.
            if ($authorizationAccount === null || $this->shouldDenyEntityRender($entityTypeId, $entity, $authorizationAccount)) {
                $response = new RenderController($twig)->renderForbidden($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }

            // CW-v1 option-1 (#1920 PR-3, design §4): render the WORKING COPY
            // instead of the find()/published-pointer $entity above ONLY when
            // BOTH previewRequested AND the requester is GENUINELY authorized
            // to preview a DRAFT — never merely because the two gates above
            // passed. Those gates answer "may this request render the entity
            // AT ALL" and — the load-bearing subtlety — `$visibility` above
            // short-circuits to Allowed for ANY account (including anonymous)
            // once `$entity` itself reports 'published' state, which, under
            // default-revision discipline, the find()-loaded $entity ALWAYS
            // does while a forward draft is in flight (the base row IS the
            // published revision). Gating the swap on `$visibility->isAllowed()`
            // alone would therefore hand the DRAFT to any anonymous visitor
            // who appends `?preview=1` to a published node's canonical URL.
            // Re-running `canRender()` against the WORKING COPY re-derives
            // state from the TIP instead: a tip still mid-review is NOT
            // 'published', so `canRender()` actually exercises its
            // PERMISSION-gated preview branch (administer nodes / own
            // content / moderation-queue permissions) rather than the
            // publicly-visible short-circuit. When no draft exists,
            // `loadWorkingCopy() === find()` and this second check trivially
            // agrees with the first (same content either way, no observable
            // difference) — the public (non-preview) path never evaluates
            // this block at all, so it is byte-untouched by construction
            // (pinned by test).
            if ($previewRequested) {
                $workingCopyRepository = $this->entityTypeManager->getRepository($entityTypeId);
                $workingCopy = $entity->id() !== null ? $workingCopyRepository->loadWorkingCopy((string) $entity->id()) : null;
                if ($workingCopy !== null
                    && $visibilityResolver->canRender($workingCopy, $authorizationAccount, $previewRequested)->isAllowed()
                ) {
                    $entity = $workingCopy;
                }
            }

            $formatterRegistry = SsrServiceProvider::getFormatterRegistry()
                ?? new FieldFormatterRegistry($this->manifest?->formatters ?? []);
            $viewModeConfig = new ArrayViewModeConfig(
                is_array($this->config['view_modes'] ?? null) ? $this->config['view_modes'] : [],
            );
            $entityRenderer = new EntityRenderer($this->entityTypeManager, $formatterRegistry, $viewModeConfig, $this->accessHandler);
            $safeViewMode = preg_replace('/[^a-z0-9_]+/i', '', strtolower($requestedViewMode)) ?: 'full';
            $viewMode = new ViewMode($safeViewMode);
            // Content negotiation on the SAME URL: text/markdown for agents (or the
            // ?raw / ?format=md toggle), text/html otherwise. The media type is woven
            // into the cache variant below so the two representations never share a
            // cache entry.
            $mediaType = $this->negotiateMediaType($httpRequest);
            $relationshipContext = $this->buildRelationshipRenderContext($entity, $authorizationAccount);
            $renderContext = $relationshipContext;
            $renderContext['workflow_visibility'] = $visibilityResolver->buildRenderContext($entity, $previewRequested);

            // Access-checked label/title for the entity (R7 WP1): the entity-level
            // view gate above and the fields-bag filter in EntityRenderer/
            // ResourceSerializer both leave a gap where the SSR <title>, the
            // schema.org JSON-LD `name`, and the Markdown H1 read
            // EntityInterface::label() directly, bypassing field-level access.
            $viewableLabel = $this->accessHandler?->viewableLabel($entity, $authorizationAccount, $this->entityTypeManager);
            $safeTitle = ($viewableLabel !== null && $viewableLabel !== '') ? $viewableLabel : $entityTypeId;

            $twigEntityContext = $renderContext;
            $twigEntityContext['account'] = $account;
            $twigEntityContext['title'] = $safeTitle;
            $twigEntityContext['schema_org_jsonld'] = $this->buildSchemaOrgScript($entity, $normalizedPath, $safeTitle);

            // Resolve only the code-owned binding after every route/entity
            // access gate. Field filtering and formatting remain lazy: an
            // unregistered production resolver must not defeat a cache hit or
            // format twice on a miss. The composer method itself is invoked
            // only after the authorized payload exists. Markdown never
            // resolves the composer.
            $pagePayload = null;
            $pageBag = null;
            [$pageComposer, $compositionResolutionFailed] = [null, false];
            if ($mediaType === MediaTypeAcceptNegotiator::HTML && $this->serviceResolver !== null) {
                $bindingAware = $this->serviceResolver instanceof BindingAwareHttpServiceResolverInterface;
                $bindingPresent = true;
                if ($bindingAware) {
                    try {
                        $bindingPresent = $this->serviceResolver->hasBinding(EntityPageComposerInterface::class);
                    } catch (\Throwable $e) {
                        $bindingPresent = false;
                        $compositionResolutionFailed = true;
                        $this->logger->error(sprintf(
                            'Entity page composer binding probe failed (%s); using framework rendering.',
                            $e::class,
                        ));
                    }
                }
                if ($bindingPresent) {
                    if ($this->accessHandler === null) {
                        throw new \LogicException('Entity page composer requires the field-access handler.');
                    }
                    $pageBag = $entityRenderer->render($entity, $viewMode, $authorizationAccount);
                    $pagePayload = $this->buildEntityPageRenderPayload(
                        $pageBag,
                        $twigEntityContext,
                        $normalizedPath,
                        $contentLangcode,
                    );
                    // For the production binding-aware resolver this factory
                    // executes only after the authorized/filtered payload has
                    // been built. The preceding hasBinding() probe never
                    // constructs application code.
                    [$pageComposer, $compositionResolutionFailed] = $this->resolveEntityPageComposer($bindingAware);
                }
            }
            $cacheVariantLangcode = $this->buildSsrCacheVariantLangcode(
                $contentLangcode,
                $viewMode->name,
                $previewRequested,
                $renderContext,
                $mediaType,
                $pageComposer !== null ? $pageComposer::class : null,
                $pageComposer !== null ? $normalizedPath : null,
            );
            $surrogateHeaders = (
                !$account->isAuthenticated()
                && !$previewRequested
                && $entity->id() !== null
            )
                ? $this->buildRenderSurrogateHeaders(
                    $entityTypeId,
                    (string) $entity->id(),
                    $viewMode->name,
                    $contentLangcode,
                    $cacheVariantLangcode,
                    $renderContext,
                    $mediaType,
                )
                : [];

            if (
                !$account->isAuthenticated()
                && !$previewRequested
                && !$compositionResolutionFailed
                && $this->renderCache !== null
                && $entity->id() !== null
            ) {
                $cached = $this->renderCache->get(
                    $entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $cacheVariantLangcode,
                );
                if ($cached !== null) {
                    $headers = array_merge($this->extractHeaders($cached), $surrogateHeaders);
                    $headers['Cache-Control'] = $cacheControlHeader;
                    $vary = $this->headerLine($headers['vary'] ?? $headers['Vary'] ?? '');
                    unset($headers['vary'], $headers['Vary']);
                    $headers['Vary'] = $this->mergeVaryHeader($vary, 'Accept');
                    return $this->htmlResult($cached->getStatusCode(), (string) $cached->getContent(), $headers);
                }
            }

            $compositionCacheable = true;
            $compositionRequiresPrivate = false;
            if ($mediaType === MediaTypeAcceptNegotiator::MARKDOWN) {
                $response = $this->renderEntityMarkdown(
                    $entity,
                    $viewMode,
                    $viewModeConfig,
                    $normalizedPath,
                    $authorizationAccount,
                );
            } else {
                [$response, $compositionCacheable, $compositionRequiresPrivate] = $this->renderEntityHtmlWithComposition(
                    $entity,
                    $viewMode,
                    $entityRenderer,
                    $twig,
                    $twigEntityContext,
                    $authorizationAccount,
                    $pageComposer,
                    $pagePayload,
                    $pageBag,
                );
                $compositionCacheable = $compositionCacheable && !$compositionResolutionFailed;
                $compositionRequiresPrivate = $compositionRequiresPrivate || $compositionResolutionFailed;
            }
            if (
                !$account->isAuthenticated()
                && !$previewRequested
                && $this->renderCache !== null
                && $entity->id() !== null
                && $response->getStatusCode() === 200
                && $compositionCacheable
            ) {
                $this->renderCache->set(
                    $entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $cacheVariantLangcode,
                    $response,
                    $cacheMaxAge,
                );
            }

            // A composed document or failure fallback cannot carry public
            // entity-only surrogate metadata: application chrome has opaque
            // dependencies, and transient failure output must never become a
            // shared CDN/browser representation.
            if ($compositionRequiresPrivate) {
                $surrogateHeaders = [];
            }
            $headers = array_merge($this->extractHeaders($response), $surrogateHeaders);
            $headers['Cache-Control'] = $compositionRequiresPrivate ? 'private, no-store' : $cacheControlHeader;
            $vary = $this->headerLine($headers['vary'] ?? $headers['Vary'] ?? '');
            unset($headers['vary'], $headers['Vary']);
            $headers['Vary'] = $this->mergeVaryHeader($vary, 'Accept');
            return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Render pipeline failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->jsonResult(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Failed to render page.',
                ]],
            ]);
        }
    }

    /**
     * Dispatch an app-level controller registered via ServiceProvider::routes().
     *
     * Controllers use Class::method format. Constructor dependencies are
     * resolved via reflection. Action methods use typed parameters only
     * (see docs/specs/app-controller-invocation.md).
     *
     * @return array{type: string, status: int, content: string|array, headers: array<string, string|list<string>>}|HttpResponse
     */
    public function dispatchAppController(
        string $controller,
        AccountInterface $account,
        HttpRequest $httpRequest,
    ): array|HttpResponse {
        [$class, $method] = explode('::', $controller, 2);

        $routeObject = $httpRequest->attributes->get('_route_object');
        if (!$routeObject instanceof Route) {
            throw new \Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerBindingException(
                'Request is missing _route_object; app controllers require a matched Route.',
            );
        }

        $routeName = $httpRequest->attributes->get('_route');
        $routeName = is_string($routeName) ? $routeName : null;

        /** @var array<string, mixed> $routeParams */
        $routeParams = array_filter(
            $httpRequest->attributes->all(),
            static fn(string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            $twig = SsrServiceProvider::createTwigEnvironment($this->projectRoot, $this->config);
        }

        $instance = $this->resolveControllerInstance($class, $twig, $account, $httpRequest);

        $ctx = new AppInvocationContext(
            request: $httpRequest,
            route: $routeObject,
            account: $account,
            decisionAccount: $this->authorizationAccount($account),
            entityTypeManager: $this->entityTypeManager,
            twig: $twig,
            routeParams: $routeParams,
            query: $httpRequest->query->all(),
            gate: $this->gate,
            serviceResolver: $this->serviceResolver,
            redirector: $this->redirectorFromRequest($httpRequest),
        );

        $response = $this->appControllerMethodInvoker->invoke(
            $instance,
            $method,
            $routeObject,
            $routeName,
            $ctx,
            $this->appControllerStrict(),
            $this->gate,
            $this->serviceResolver,
            $this->appControllerArgumentResolvers,
        );

        if (!$response instanceof HttpResponse && !$response instanceof InertiaPageResultInterface) {
            $isRenderRoute = $routeObject->getOption('_render') === true;
            if (!$isRenderRoute) {
                $this->logger->warning(sprintf(
                    'Controller %s::%s returned an unsupported value (expected HttpResponse or Inertia page). '
                    . 'For legacy Twig SSR, add the _render route option or return a Response.',
                    $class,
                    $method,
                ));
            }
        }

        if ($response instanceof InertiaPageResultInterface) {
            $pageObject = $response->toPageObject();
            $pageObject['url'] = $httpRequest->getRequestUri();

            if ($httpRequest->headers->get('X-Inertia') === 'true') {
                $json = new JsonResponse($pageObject, 200, [
                    'X-Inertia' => 'true',
                    'Vary' => 'X-Inertia',
                ]);
                $json->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $json->headers->set('Content-Type', 'application/vnd.api+json');
                $this->applyRenderCacheControlHeader($json, $account);

                return $json;
            }

            if ($this->inertiaFullPageRenderer === null) {
                $err = new JsonResponse([
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [[
                        'status' => '500',
                        'title' => 'Internal Server Error',
                        'detail' => 'Inertia full-page renderer is not configured.',
                    ]],
                ], 500);
                $err->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $err->headers->set('Content-Type', 'application/vnd.api+json');

                return $err;
            }

            $html = new HttpResponse(
                $this->inertiaFullPageRenderer->render($pageObject),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
            $this->applyRenderCacheControlHeader($html, $account);

            return $html;
        }

        if ($response instanceof HttpResponse) {
            $this->applyRenderCacheControlHeader($response, $account);

            return $response;
        }

        return $this->htmlResult(500, '<h1>Internal Server Error</h1>', []);
    }

    private function appControllerStrict(): bool
    {
        $env = getenv('WAASEYAA_APP_CONTROLLER_STRICT');
        if ($env !== false && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN)
                || $env === '1'
                || strtolower($env) === 'true';
        }

        $ac = $this->config['app_controller'] ?? null;
        if (is_array($ac) && array_key_exists('strict', $ac)) {
            return (bool) $ac['strict'];
        }

        return true;
    }

    private function applyRenderCacheControlHeader(HttpResponse $response, AccountInterface $account): void
    {
        $cacheMaxAge = $this->cacheConfigResolver->resolveRenderCacheMaxAge();
        $response->headers->set(
            'Cache-Control',
            $this->cacheConfigResolver->cacheControlHeaderForRender($account, $cacheMaxAge),
        );
    }

    /**
     * Resolve a controller instance by inspecting constructor parameter types.
     *
     * Falls back to the legacy (EntityTypeManager, $twig) contract when
     * reflection is unavailable or the constructor has no type hints.
     */
    public function resolveControllerInstance(
        string $class,
        \Twig\Environment $twig,
        AccountInterface $account,
        HttpRequest $httpRequest,
    ): object {
        // Check if the controller class itself is registered as a singleton
        if ($this->serviceResolver !== null) {
            $resolved = $this->serviceResolver->resolve($class);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $ref = new \ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $serviceMap = [
            \Waaseyaa\Entity\EntityTypeManager::class => $this->entityTypeManager,
            \Twig\Environment::class => $twig,
            HttpRequest::class => $httpRequest,
            AccountInterface::class => $account,
        ];

        $redirector = $this->redirectorFromRequest($httpRequest);
        if ($redirector !== null) {
            $serviceMap[Redirector::class] = $redirector;
        }

        if ($this->gate !== null) {
            $serviceMap[\Waaseyaa\Access\Gate\GateInterface::class] = $this->gate;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $matched = false;

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                foreach ($serviceMap as $serviceType => $serviceInstance) {
                    if ($typeName === $serviceType || is_a($serviceInstance, $typeName)) {
                        $args[] = $serviceInstance;
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched && $type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->serviceResolver !== null) {
                $resolved = $this->serviceResolver->resolve($type->getName());
                if ($resolved !== null) {
                    $args[] = $resolved;
                    $matched = true;
                }
            }

            if (!$matched) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $args[] = null;
                } else {
                    $paramType = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                    throw new \RuntimeException(sprintf(
                        '[Waaseyaa] Cannot resolve required parameter $%s (%s) for controller %s',
                        $param->getName(),
                        $paramType,
                        $class,
                    ));
                }
            }
        }

        return $ref->newInstanceArgs($args);
    }

    private function redirectorFromRequest(HttpRequest $request): ?Redirector
    {
        $redirector = $request->attributes->get(Redirector::REQUEST_ATTRIBUTE);

        return $redirector instanceof Redirector ? $redirector : null;
    }

    public function isPreviewRequested(HttpRequest $request): bool
    {
        $preview = $request->query->get('preview');
        if (is_bool($preview)) {
            return $preview;
        }
        if (is_int($preview)) {
            return $preview === 1;
        }
        if (is_string($preview)) {
            return in_array(strtolower(trim($preview)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    /**
     * Build the additive relationship-navigation render context for an entity.
     *
     * $account is threaded through so every related endpoint the context would
     * DISCLOSE (its type/id/path/label) is re-checked against the SAME
     * per-entity view AccessPolicy the primary-entity gate uses, then dropped
     * when not viewable — see {@see canViewRelatedEndpoint()} /
     * {@see filterBrowseEndpoints()}. Without this, the underlying
     * {@see WorkflowVisibilityFilter} gates related endpoints on PUBLISH STATUS
     * only, so a PUBLISHED-BUT-ACCESS-RESTRICTED related entity still leaks its
     * identity through the navigation context of a page the account can see
     * (audit m1, R6 PR2 — the same disclosure class the primary-entity gate
     * closes, on the same rendered page).
     *
     * @return array<string, mixed>
     */
    public function buildRelationshipRenderContext(EntityInterface $entity, AccountInterface $account): array
    {
        if (!$this->entityTypeManager->hasDefinition('relationship') || $entity->id() === null) {
            return [];
        }

        try {
            $discovery = new RelationshipDiscoveryService(
                // Pass the visibility filter so related entities are gated on
                // publication state — without it the traversal service fails
                // closed and would withhold every related label/path.
                new RelationshipTraversalService(
                    $this->entityTypeManager,
                    $this->database,
                    new WorkflowVisibilityFilter(),
                ),
            );
            $entityType = $entity->getEntityTypeId();
            $entityId = (string) $entity->id();

            if ($entityType === 'node') {
                return [
                    'relationship_navigation' => [
                        'contract' => [
                            'version' => self::DISCOVERY_CONTRACT_VERSION,
                            'stability' => self::DISCOVERY_CONTRACT_STABILITY,
                            'surface' => 'ssr_relationship_navigation',
                        ],
                        'entity' => $this->filterBrowseEndpoints(
                            $discovery->endpointPage($entityType, $entityId, [
                                'status' => 'published',
                                'limit' => 12,
                            ])['browse'],
                            $account,
                        ),
                    ],
                ];
            }

            if ($entityType !== 'relationship') {
                return [];
            }

            $values = EntityValues::toCastAwareMap($entity);
            $endpoint = $discovery->relationshipEntityPage($values, [
                'status' => 'published',
                'limit' => 8,
            ]);
            if ($endpoint === []) {
                return [];
            }

            $fromType = (string) ($endpoint['from_endpoint']['endpoint']['type'] ?? '');
            $fromId = (string) ($endpoint['from_endpoint']['endpoint']['id'] ?? '');
            $toType = (string) ($endpoint['to_endpoint']['endpoint']['type'] ?? '');
            $toId = (string) ($endpoint['to_endpoint']['endpoint']['id'] ?? '');
            // Fail closed, never partially: the rendered relationship edge
            // discloses BOTH endpoints' identities, so if the account cannot
            // view either one, withhold the whole navigation context.
            if (
                !$this->canViewRelatedEndpoint($fromType, $fromId, $account)
                || !$this->canViewRelatedEndpoint($toType, $toId, $account)
            ) {
                return [];
            }

            return [
                'relationship_navigation' => [
                    'contract' => [
                        'version' => self::DISCOVERY_CONTRACT_VERSION,
                        'stability' => self::DISCOVERY_CONTRACT_STABILITY,
                        'surface' => 'ssr_relationship_navigation',
                    ],
                    'from_endpoint' => [
                        'type' => $fromType,
                        'id' => $fromId,
                        'path' => (string) ($endpoint['from_endpoint']['endpoint']['path'] ?? ''),
                        'browse' => $this->filterBrowseEndpoints(
                            is_array($endpoint['from_endpoint']['browse'] ?? null) ? $endpoint['from_endpoint']['browse'] : [],
                            $account,
                        ),
                    ],
                    'to_endpoint' => [
                        'type' => $toType,
                        'id' => $toId,
                        'path' => (string) ($endpoint['to_endpoint']['endpoint']['path'] ?? ''),
                        'browse' => $this->filterBrowseEndpoints(
                            is_array($endpoint['to_endpoint']['browse'] ?? null) ? $endpoint['to_endpoint']['browse'] : [],
                            $account,
                        ),
                    ],
                    'edge_context' => $endpoint['edge_context'],
                ],
            ];
        } catch (\Throwable $e) {
            // Relationship navigation is additive and should not break render paths.
            $this->logger->warning(sprintf('Relationship render context failed: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Drop every related edge in a `browse` result whose disclosed endpoint the
     * account cannot view, and recompute the disclosed counts to match. The
     * source entity of the browse is the page being rendered (already gated by
     * the primary-entity gate), so only the OTHER endpoint of each edge —
     * `related_entity_type`/`related_entity_id` — is re-checked here.
     *
     * @param array<string, mixed> $browse
     * @return array<string, mixed>
     */
    private function filterBrowseEndpoints(array $browse, AccountInterface $account): array
    {
        foreach (['outbound', 'inbound'] as $direction) {
            if (!is_array($browse[$direction] ?? null)) {
                continue;
            }
            $browse[$direction] = array_values(array_filter(
                $browse[$direction],
                fn(mixed $edge): bool => is_array($edge) && $this->canViewRelatedEndpoint(
                    (string) ($edge['related_entity_type'] ?? ''),
                    (string) ($edge['related_entity_id'] ?? ''),
                    $account,
                ),
            ));
        }

        $outbound = is_array($browse['outbound'] ?? null) ? $browse['outbound'] : [];
        $inbound = is_array($browse['inbound'] ?? null) ? $browse['inbound'] : [];
        if (isset($browse['counts']) && is_array($browse['counts'])) {
            $browse['counts'] = [
                'outbound' => count($outbound),
                'inbound' => count($inbound),
                'total' => count($outbound) + count($inbound),
            ];
        }

        return $browse;
    }

    /**
     * Fail-closed per-account view gate for a related endpoint entity the
     * relationship-navigation context would disclose (audit m1, R6 PR2).
     *
     * Mirrors {@see \Waaseyaa\AI\Tools\Relationship\RelationshipTraverseTool}'s
     * `canViewEndpoint()` "prove NOTHING -> drop" contract at the SSR boundary:
     * returns false — so the caller drops the whole edge, never partially
     * disclosing it — when no access handler is wired, the type/id is empty,
     * the type is unregistered, the entity fails to load, or the per-entity
     * `AccessPolicy` denies 'view'. Only a positively-Allowed view keeps the
     * endpoint in the emitted context.
     *
     * N+1 note: each disclosed endpoint is re-loaded here (accepted tradeoff —
     * the leak is closed now; a future optimization could push the per-account
     * filter into RelationshipTraversalService's own visibility resolution).
     */
    private function canViewRelatedEndpoint(string $type, string $id, AccountInterface $account): bool
    {
        if ($this->accessHandler === null || $type === '' || $id === '') {
            return false;
        }
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return false;
        }

        try {
            $endpoint = $this->entityTypeManager->getRepository($type)->find($id);
        } catch (\Throwable) {
            return false;
        }
        if ($endpoint === null) {
            return false;
        }

        $authorizationAccount = $this->authorizationAccount($account);

        return $authorizationAccount !== null
            && $this->accessHandler->check($endpoint, 'view', $authorizationAccount)->isAllowed();
    }

    public function getLanguageResolver(): LanguageResolver
    {
        return $this->languageResolver;
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function buildSsrCacheVariantLangcode(
        string $langcode,
        string $viewMode,
        bool $previewRequested,
        array $renderContext,
        string $mediaType = MediaTypeAcceptNegotiator::HTML,
        ?string $pageComposerClass = null,
        ?string $pageCompositionPath = null,
    ): string {
        $workflowState = 'unknown';
        if (is_array($renderContext['workflow_visibility'] ?? null)) {
            $workflowStateCandidate = $renderContext['workflow_visibility']['state'] ?? null;
            if (is_string($workflowStateCandidate) && trim($workflowStateCandidate) !== '') {
                $workflowState = strtolower(trim($workflowStateCandidate));
            }
        }

        $graphHash = 'none';
        if (is_array($renderContext['relationship_navigation'] ?? null)) {
            $graphContext = $renderContext['relationship_navigation'];
            if ($graphContext !== []) {
                $serialized = json_encode($this->discoveryHandler->normalizeForCacheKey($graphContext), JSON_THROW_ON_ERROR);
                $graphHash = substr(sha1($serialized), 0, 12);
            }
        }

        $mediaToken = $this->mediaCacheToken($mediaType);
        $variantPayload = [
            'contract_version' => self::DISCOVERY_CONTRACT_VERSION,
            'langcode' => strtolower(trim($langcode)),
            'view_mode' => strtolower(trim($viewMode)),
            'preview' => $previewRequested ? 1 : 0,
            'workflow_state' => $workflowState,
            'graph_hash' => $graphHash,
            'media_type' => $mediaToken,
        ];
        if ($mediaToken === 'html' && $pageComposerClass !== null) {
            $variantPayload['page_composition'] = [
                'class' => hash('sha256', $pageComposerClass),
                'path' => hash('sha256', $pageCompositionPath ?? ''),
            ];
        }
        $serializedVariantPayload = json_encode($this->discoveryHandler->normalizeForCacheKey($variantPayload), JSON_THROW_ON_ERROR);
        $variantHash = substr(sha1((string) $serializedVariantPayload), 0, 16);

        // The media token is appended (not inserted) so the historical
        // `v2:lang:view:preview:workflow:` prefix is preserved, while HTML and
        // Markdown still resolve to distinct cache entries (FR-004 / NFR-001).
        return sprintf(
            'v2:%s:%s:%s:%s:%s:%s',
            $this->sanitizeCacheToken($langcode, 'unknown'),
            $this->sanitizeCacheToken($viewMode, 'full'),
            $previewRequested ? 'preview' : 'public',
            $this->sanitizeCacheToken($workflowState, 'unknown'),
            $variantHash,
            $mediaToken,
        );
    }

    /**
     * Short, stable cache token for a negotiated media type.
     */
    private function mediaCacheToken(string $mediaType): string
    {
        return $mediaType === MediaTypeAcceptNegotiator::MARKDOWN ? 'md' : 'html';
    }

    /**
     * Negotiate the response media type from the `?raw`/`?format` override or the
     * `Accept` header, restricted to the representations SSR can serve.
     */
    public function negotiateMediaType(HttpRequest $httpRequest): string
    {
        $negotiator = new MediaTypeAcceptNegotiator();
        $supported = [MediaTypeAcceptNegotiator::HTML, MediaTypeAcceptNegotiator::MARKDOWN];

        $override = $negotiator->resolveQueryOverride($httpRequest->query->all(), $supported);
        if ($override !== null) {
            return $override;
        }

        return $negotiator->negotiate(
            (string) $httpRequest->headers->get('Accept', ''),
            $supported,
            MediaTypeAcceptNegotiator::HTML,
        );
    }

    /**
     * Resolve a `/{entityTypeId}/{id}` system path to a content-entity reference
     * so a published content item is reachable without a hand-created path alias
     * (FR-006). Scoped to the `content` group — non-content types (user,
     * taxonomy, …) are never served by guessing an id. Published-gating happens
     * downstream via {@see EditorialVisibilityResolver}.
     *
     * @return array{0: string, 1: string}|null [entityTypeId, id], or null.
     */
    private function resolveCanonicalContentPath(string $path): ?array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '' || substr_count($trimmed, '/') !== 1) {
            return null;
        }

        [$type, $rawId] = explode('/', $trimmed, 2);
        $id = rawurldecode($rawId);
        if ($type === '' || $id === '' || !$this->entityTypeManager->hasDefinition($type)) {
            return null;
        }

        if ($this->entityTypeManager->getDefinition($type)->getGroup() !== 'content') {
            return null;
        }

        return [$type, $id];
    }

    /**
     * Resolve a content entity by its canonical numeric id OR — when the path
     * segment is UUID-shaped and the type has a `uuid` key — by uuid (#1686).
     *
     * The canonical public path stays numeric `/{type}/{id}`, but a valid entity
     * must never 404 purely on identifier shape: `GET /story/{uuid}` previously
     * fed the UUID to `load()`, which queries the numeric `id` column only, so it
     * never matched. We resolve the uuid to the numeric id via a query first;
     * `load()` itself stays numeric-keyed (the column is never dual-keyed).
     *
     * `accessCheck(false)` — identity resolution only. Authorization stays with
     * the SSR visibility gates in the caller (`EditorialVisibilityResolver` +
     * {@see shouldDenyEntityRender()}), exactly as on the numeric path
     * (`load()` does not access-check either), so the uuid and numeric paths are
     * symmetric and the uuid path cannot leak a draft.
     */
    private function loadByIdOrUuid(string $entityTypeId, int|string $id): ?EntityInterface
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $keys = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys();

        if (
            isset($keys['uuid'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id) === 1
        ) {
            $ids = $repository->getQuery()
                ->accessCheck(false)
                ->condition($keys['uuid'], (string) $id)
                ->execute();
            if ($ids === []) {
                return null;
            }

            return $repository->find((string) reset($ids));
        }

        return $repository->find((string) $id);
    }

    /**
     * The canonical entity-level view-access gate for EVERY entity type
     * reachable via SSR render (audit M2 / R6 PR2 for the `content` group;
     * generalized to all types in R8-a). Returns true when the entity must be
     * denied for ($account, 'view') per the SAME per-entity AccessPolicy the
     * MCP and JSON:API paths use — so HTML/Markdown can never serve an entity
     * the other surfaces deny.
     *
     * Nodes ARE included (audit M2, R6 PR2): a published-but-access-restricted
     * node (e.g. a legal-hold/insufficient-clearance classification policy
     * returning entity-level Forbidden for 'view') must still be denied here
     * even though {@see EditorialVisibilityResolver::canRender()} — run FIRST at
     * {@see SsrPageHandler::handleRenderPage()} — already allowed it on
     * publish-state alone. Both gates must pass for a node to render: the
     * editorial resolver owns publish/preview/workflow nuance, this gate owns
     * per-account entity-level view access (`NodeAccessPolicy`,
     * `ClassificationFieldAccessPolicy`, etc.). A standard published node with no
     * restrictive policy and an anonymous account holding `access content` is
     * unaffected — `NodeAccessPolicy::access()` grants it — so ordinary content
     * keeps rendering exactly as before.
     *
     * R8-a (generalized beyond the `content` group): this gate used to
     * early-return `false` (i.e. NOT deny) for any entity type outside the
     * `content` group, via a now-removed `isContentGroupEntity()` guard. That
     * left a whole-entity disclosure hole — {@see EditorialVisibilityResolver}
     * allows any non-`node` type outright, so a `path_alias` pointing at a
     * non-content-group type (e.g. `oidc_client`, whose `AccessPolicy` returns
     * Neutral/deny-by-default for anonymous 'view') rendered with NO
     * entity-level check at all once an admin-created alias to it existed. The
     * generalization is deny-by-default and INTENTIONALLY enforces the access
     * model uniformly (Option A): the SSR render path now matches what `/api`
     * already enforces for every type.
     *
     * INTENDED CONSEQUENCE (documented, tested, shipped — not an accident): a
     * `content`-group entity stays anonymously viewable when published, because
     * the framework-default {@see \Waaseyaa\Access\Policy\PublishedContentAccessPolicy}
     * grants that group's `view` with NO permission required. But the
     * non-content-group types whose only `view` grant is PERMISSION-gated —
     * `taxonomy_term` (`TermAccessPolicy` needs `access content`), `media`
     * (`MediaAccessPolicy` needs `access media`), `user` (`UserAccessPolicy`
     * needs `access user profiles`) — are now DENIED for a bare anonymous
     * account (which holds none of those), exactly as `/api` already denies
     * them. Types with no granting policy at all (admin/config entities via
     * `ConfigEntityAccessPolicy`, `oidc_client`) were never publicly reachable
     * and are likewise denied. No permission-free public render regresses.
     *
     * R9 (product decision, DEFERRED — not a bug): decide whether the framework
     * should ship permission-free published/public `view` grants for
     * `taxonomy_term` (and possibly `media`/`user`) so those SSR pages are
     * anonymously viewable BY DEFAULT, extending the `PublishedContentAccessPolicy`
     * pattern to those groups. Until that product decision is made, anonymous
     * visibility of those types requires a SITE-PROVIDED granting `AccessPolicy`
     * (the same requirement `/api` already imposes). See CHANGELOG R8-a / R9.
     *
     * Fails closed — when no access handler is wired, a render is denied rather
     * than risk leaking a draft (or a held/restricted entity) through a wiring
     * gap.
     */
    /** @param AuthorizationPrincipalInterface $account */
    private function shouldDenyEntityRender(string $entityTypeId, EntityInterface $entity, AccountInterface $account): bool
    {
        $authorizationAccount = $this->authorizationAccount($account);

        return $this->accessHandler === null
            || $authorizationAccount === null
            || !$this->accessHandler->check($entity, 'view', $authorizationAccount)->isAllowed();
    }

    private function authorizationAccount(AccountInterface $account): ?AuthorizationPrincipalInterface
    {
        $principal = $this->fieldReadScope?->current();
        if ($principal !== null) {
            return (string) $principal->id() === (string) $account->id() ? $principal : null;
        }

        return $account instanceof AuthorizationPrincipalInterface ? $account : null;
    }

    /**
     * Build the schema.org JSON-LD `<script>` block for an entity's HTML `<head>`.
     *
     * $safeLabel is the access-checked label resolved by the caller (see
     * {@see EntityAccessHandler::viewableLabel()} via `handleRenderPage()`) —
     * passed through to {@see EntitySchemaOrgMapper::map()}'s `$labelOverride`
     * so the JSON-LD `name` never leaks a field-access-restricted label (R7 WP1).
     */
    private function buildSchemaOrgScript(EntityInterface $entity, string $canonicalUrl, string $safeLabel): string
    {
        $mapper = new EntitySchemaOrgMapper();

        return $mapper->toScriptTag($mapper->map($entity, $canonicalUrl, labelOverride: $safeLabel));
    }

    /**
     * Render an entity as a Markdown HTTP response via the shared
     * {@see EntityMarkdownPresenter} — the same bytes the `?raw` toggle returns.
     *
     * $account is threaded through to {@see EntityMarkdownPresenter::present()}
     * together with $this->accessHandler so the per-account field-access filter
     * is always applied — Markdown must never expose a field the HTML/JSON:API
     * representations would hide for the same account.
     */
    /** @param AuthorizationPrincipalInterface $account */
    private function renderEntityMarkdown(
        EntityInterface $entity,
        ViewMode $viewMode,
        ArrayViewModeConfig $viewModeConfig,
        string $canonicalUrl,
        AccountInterface $account,
    ): HttpResponse {
        if ($this->accessHandler === null) {
            // Fail closed: EntityMarkdownPresenter::present() requires a
            // non-null access handler to apply the per-account field filter.
            // Without one we must refuse to render rather than fall back to
            // unfiltered output. Unreachable in production — SsrServiceProvider
            // always wires accessHandler from the kernel's non-nullable
            // getAccessHandler() — this guards direct/test construction of
            // SsrPageHandler without it.
            $this->logger->error('Markdown render requested with no access handler wired; refusing to render unfiltered content.');

            return new HttpResponse(
                'Internal Server Error',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $presenter = new EntityMarkdownPresenter(
            new ResourceSerializer($this->entityTypeManager, internalFieldVisibility: $this->internalFieldVisibility),
            $this->entityTypeManager,
            $viewModeConfig,
        );

        $markdown = $presenter->present($entity, $viewMode->name, $this->accessHandler, $account, $canonicalUrl);

        return new HttpResponse(
            $markdown,
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * Render an entity as an HTML HTTP response via {@see RenderController}.
     *
     * $account is threaded through to $entityRenderer (which was constructed
     * with $this->accessHandler) so the per-account field-access filter is
     * always applied to the FIELDS BAG — HTML must not expose a restricted
     * field's content in the field loop that the Markdown/JSON:API
     * representations would hide for the same account (audit M1 / R6 PR1).
     *
     * LABEL CHANNEL (R7 WP1): the label/title emitted via the template's
     * `<title>` block and the schema.org JSON-LD from
     * {@see buildSchemaOrgScript()} is NOT part of the `fields` bag this method
     * filters — both are populated by the caller ({@see handleRenderPage()})
     * from the access-checked `$safeTitle` (see
     * {@see \Waaseyaa\Access\EntityAccessHandler::viewableLabel()}) before this
     * method ever runs, so a policy forbidding the label field on 'view' is
     * honored identically to the Markdown H1 (see {@see renderEntityMarkdown()}).
     *
     * Mirrors {@see renderEntityMarkdown()}'s fail-closed discipline exactly:
     * when no access handler is wired we refuse to render rather than fall
     * back to unfiltered output. Unreachable in production —
     * SsrServiceProvider always wires accessHandler from the kernel's
     * non-nullable getAccessHandler() — this guards direct/test construction
     * of SsrPageHandler without it.
     *
     * @param array<string, mixed> $context
     * @param array{
     *   entity: EntityInterface,
     *   entity_type: string,
     *   bundle: string,
     *   view_mode: string,
     *   template_suggestions: list<string>,
     *   fields: array<string, array{raw: mixed, formatted: string, type: string}>
     * }|null $authorizedBag
     */
    /** @param AuthorizationPrincipalInterface $account */
    private function renderEntityHtml(
        EntityInterface $entity,
        ViewMode $viewMode,
        EntityRenderer $entityRenderer,
        \Twig\Environment $twig,
        array $context,
        AccountInterface $account,
        ?array $authorizedBag = null,
    ): HttpResponse {
        if ($this->accessHandler === null) {
            $this->logger->error('HTML render requested with no access handler wired; refusing to render unfiltered content.');

            return new HttpResponse(
                'Internal Server Error',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        if ($authorizedBag !== null) {
            return $this->renderAuthorizedEntityBagHtml($authorizedBag, $twig, $context);
        }

        return new RenderController($twig, $entityRenderer)->renderEntity($entity, $viewMode, $context, $account);
    }

    /**
     * Render the one request-local bag produced by EntityRenderer after field
     * access. This remains private so callers cannot supply formatted values
     * or template suggestions around the public entity/account boundary.
     *
     * @param array{
     *   entity: EntityInterface,
     *   entity_type: string,
     *   bundle: string,
     *   view_mode: string,
     *   template_suggestions: list<string>,
     *   fields: array<string, array{raw: mixed, formatted: string, type: string}>
     * } $bag
     * @param array<string, mixed> $context
     */
    private function renderAuthorizedEntityBagHtml(
        array $bag,
        \Twig\Environment $twig,
        array $context,
    ): HttpResponse {
        foreach ($context as $key => $value) {
            if ($key !== '') {
                $bag[$key] = $value;
            }
        }
        foreach ($bag['template_suggestions'] as $template) {
            try {
                return new HttpResponse($twig->render($template, $bag));
            } catch (LoaderError) {
                continue;
            }
        }

        return new HttpResponse('<h1>Render template missing</h1>', 500);
    }

    /**
     * @return array{0: EntityPageComposerInterface|null, 1: bool} Composer and
     *                                                               resolution-failed flag.
     */
    private function resolveEntityPageComposer(bool $bindingKnown = false): array
    {
        if ($this->serviceResolver === null) {
            return [null, false];
        }

        try {
            if ($this->serviceResolver instanceof BindingAwareHttpServiceResolverInterface) {
                if (!$bindingKnown && !$this->serviceResolver->hasBinding(EntityPageComposerInterface::class)) {
                    return [null, false];
                }
                $resolved = $this->serviceResolver->resolveBound(EntityPageComposerInterface::class);
            } else {
                $resolved = $this->serviceResolver->resolve(EntityPageComposerInterface::class);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Entity page composer resolution failed (%s); using framework rendering.',
                $e::class,
            ));

            return [null, true];
        }
        if ($resolved === null) {
            return [null, false];
        }
        if (!$resolved instanceof EntityPageComposerInterface) {
            $this->logger->error(sprintf(
                'Entity page composer binding must implement %s; using framework rendering.',
                EntityPageComposerInterface::class,
            ));

            return [null, true];
        }

        return [$resolved, false];
    }

    /**
     * @param array<string, mixed> $context
     * @param array{
     *   entity: EntityInterface,
     *   entity_type: string,
     *   bundle: string,
     *   view_mode: string,
     *   template_suggestions: list<string>,
     *   fields: array<string, array{raw: mixed, formatted: string, type: string}>
     * }|null $pageBag
     * @return array{0: HttpResponse, 1: bool, 2: bool} Response, render-cache
     *                                                         persistence, private/no-store.
     */
    private function renderEntityHtmlWithComposition(
        EntityInterface $entity,
        ViewMode $viewMode,
        EntityRenderer $entityRenderer,
        \Twig\Environment $twig,
        array $context,
        AuthorizationPrincipalInterface $account,
        ?EntityPageComposerInterface $pageComposer,
        ?EntityPageRenderPayload $pagePayload,
        ?array $pageBag,
    ): array {
        if ($pageComposer === null) {
            return [
                $this->renderEntityHtml($entity, $viewMode, $entityRenderer, $twig, $context, $account, $pageBag),
                true,
                false,
            ];
        }

        try {
            if ($pagePayload === null) {
                throw new \LogicException('Resolved entity page composer has no authorized render payload.');
            }

            $composed = $pageComposer->compose($pagePayload);
            if ($composed === null) {
                return [
                    $this->renderEntityHtml($entity, $viewMode, $entityRenderer, $twig, $context, $account, $pageBag),
                    true,
                    false,
                ];
            }

            $content = $composed->getContent();
            $contentType = strtolower(trim(explode(';', (string) $composed->headers->get('Content-Type', ''), 2)[0]));
            $declaresNonHtml = $contentType !== ''
                && $contentType !== 'text/html'
                && $contentType !== 'application/xhtml+xml';
            if (
                $composed->getStatusCode() !== HttpResponse::HTTP_OK
                || !is_string($content)
                || trim($content) === ''
                || $declaresNonHtml
            ) {
                $this->logger->error(sprintf(
                    'Entity page composer %s returned invalid output; using framework rendering.',
                    $pageComposer::class,
                ));

                return [
                    $this->renderEntityHtml($entity, $viewMode, $entityRenderer, $twig, $context, $account, $pageBag),
                    false,
                    true,
                ];
            }

            // The app shell can depend on navigation, theme config, or response
            // decorators that the entity-only RenderCache cannot tag or vary
            // safely. Until the public contract carries explicit dependency
            // metadata, accepted composed documents are never shared/persisted.
            return [$composed, false, true];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Entity page composer %s failed (%s); using framework rendering.',
                $pageComposer::class,
                $e::class,
            ));

            return [
                $this->renderEntityHtml($entity, $viewMode, $entityRenderer, $twig, $context, $account, $pageBag),
                false,
                true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildEntityPageRenderPayload(
        array $bag,
        array $context,
        string $requestPath,
        string $langcode,
    ): EntityPageRenderPayload {
        $fields = [];
        foreach ($bag['fields'] as $name => $field) {
            $fields[$name] = new EntityPageField(
                name: $name,
                type: $field['type'],
                formatted: $field['formatted'],
            );
        }
        $bodySource = $bag['fields']['body']['raw'] ?? null;

        return new EntityPageRenderPayload(
            title: is_string($context['title'] ?? null) ? $context['title'] : $bag['entity_type'],
            requestPath: $requestPath,
            entityType: $bag['entity_type'],
            bundle: $bag['bundle'],
            viewMode: $bag['view_mode'],
            langcode: $langcode,
            fields: $fields,
            schemaOrgJsonLd: is_string($context['schema_org_jsonld'] ?? null) ? $context['schema_org_jsonld'] : '',
            bodyCompositionHtml: is_string($bodySource) ? $this->sanitizeCompositionBodyHtml($bodySource) : '',
        );
    }

    private function sanitizeCompositionBodyHtml(string $html): string
    {
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceHttpsUrls();

        return new HtmlSanitizer($config)->sanitize($html);
    }

    private function mergeVaryHeader(string $current, string $required): string
    {
        $tokens = [];
        foreach (explode(',', $current . ',' . $required) as $token) {
            $trimmed = trim($token);
            if ($trimmed !== '') {
                $tokens[strtolower($trimmed)] = $trimmed;
            }
        }

        return implode(', ', array_values($tokens));
    }

    /** @param string|list<string> $value */
    private function headerLine(string|array $value): string
    {
        return is_array($value) ? implode(', ', $value) : $value;
    }

    public function sanitizeCacheToken(string $value, string $fallback): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-_');

        return $normalized === '' ? $fallback : $normalized;
    }

    /**
     * @param array<string, mixed> $renderContext
     * @return array<string, string>
     */
    public function buildRenderSurrogateHeaders(
        string $entityType,
        string $entityId,
        string $viewMode,
        string $langcode,
        string $variant,
        array $renderContext,
        string $mediaType = MediaTypeAcceptNegotiator::HTML,
    ): array {
        $workflowState = 'unknown';
        if (is_array($renderContext['workflow_visibility'] ?? null)) {
            $workflowStateCandidate = $renderContext['workflow_visibility']['state'] ?? null;
            if (is_string($workflowStateCandidate) && trim($workflowStateCandidate) !== '') {
                $workflowState = strtolower(trim($workflowStateCandidate));
            }
        }

        $graphHash = 'none';
        if (is_array($renderContext['relationship_navigation'] ?? null) && $renderContext['relationship_navigation'] !== []) {
            $serialized = json_encode(
                $this->discoveryHandler->normalizeForCacheKey($renderContext['relationship_navigation']),
                JSON_THROW_ON_ERROR,
            );
            $graphHash = substr(sha1($serialized), 0, 12);
        }

        $keys = array_values(array_unique([
            'waaseyaa:ssr',
            'waaseyaa:ssr:entity:' . $this->sanitizeCacheToken($entityType, 'entity'),
            'waaseyaa:ssr:entity:' . $this->sanitizeCacheToken($entityType, 'entity') . ':' . $this->sanitizeCacheToken($entityId, '0'),
            'waaseyaa:ssr:workflow:' . $this->sanitizeCacheToken($workflowState, 'unknown'),
            'waaseyaa:ssr:view:' . $this->sanitizeCacheToken($viewMode, 'full'),
            'waaseyaa:ssr:lang:' . $this->sanitizeCacheToken($langcode, 'unknown'),
            'waaseyaa:ssr:graph:' . $this->sanitizeCacheToken($graphHash, 'none'),
            'waaseyaa:ssr:media:' . $this->mediaCacheToken($mediaType),
        ]));

        return [
            'Surrogate-Key' => implode(' ', $keys),
            'X-Waaseyaa-Render-Variant' => $variant,
            'X-Waaseyaa-Render-Workflow' => $this->sanitizeCacheToken($workflowState, 'unknown'),
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array{type: 'html', status: int, content: string, headers: array<string, string|list<string>>}
     */
    private function htmlResult(int $status, string $content, array $headers = []): array
    {
        return ['type' => 'html', 'status' => $status, 'content' => $content, 'headers' => $headers];
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array{type: 'json', status: int, content: array, headers: array<string, string|list<string>>}
     */
    private function jsonResult(int $status, array $content, array $headers = []): array
    {
        return ['type' => 'json', 'status' => $status, 'content' => $content, 'headers' => $headers];
    }

    /**
     * Extract headers without flattening repeatable values such as Set-Cookie.
     *
     * @return array<string, string|list<string>>
     */
    private function extractHeaders(HttpResponse $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            // Symfony assigns Date when this internal response is created, but
            // SsrRouter constructs the real outbound response afterwards. Do
            // not persist or compare a wall-clock transport header at this
            // intermediate render boundary; the outbound response owns it.
            if (strtolower($name) === 'date') {
                continue;
            }

            $presentValues = array_values(array_filter(
                $values,
                static fn(?string $value): bool => $value !== null,
            ));
            if ($presentValues !== []) {
                $headers[$name] = count($presentValues) === 1 ? $presentValues[0] : $presentValues;
            }
        }

        return $headers;
    }
}
