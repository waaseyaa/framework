<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Markdown\EntityMarkdownPresenter;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
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
use Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper;
use Waaseyaa\SSR\Http\AppController\AppControllerArgumentResolver;
use Waaseyaa\SSR\Http\AppController\AppControllerMethodInvoker;
use Waaseyaa\SSR\Http\AppController\AppInvocationContext;
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
     *   'headers' => array<string, string>
     *
     * @return array{type: string, status: int, content: string|array, headers: array<string, string>}
     */
    public function handleRenderPage(
        string $path,
        AccountInterface $account,
        HttpRequest $httpRequest,
        string $requestedViewMode = 'full',
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
                    $this->entityTypeManager->getStorage('path_alias'),
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

            $previewRequested = $this->isPreviewRequested($httpRequest);
            $visibilityResolver = new EditorialVisibilityResolver();
            $visibility = $visibilityResolver->canRender($entity, $account, $previewRequested);
            if ($visibility->isForbidden()) {
                $response = new RenderController($twig)->renderForbidden($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }
            // Canonical published gate for generic content the node-centric
            // EditorialVisibilityResolver does not cover (it allows any non-`node`
            // type outright). See {@see shouldDenyContentGroupRender()}.
            if ($this->shouldDenyContentGroupRender($entityTypeId, $entity, $account)) {
                $response = new RenderController($twig)->renderForbidden($aliasLookupPath, $account);
                $headers = $this->extractHeaders($response);
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->getStatusCode(), (string) $response->getContent(), $headers);
            }

            $formatterRegistry = SsrServiceProvider::getFormatterRegistry()
                ?? new FieldFormatterRegistry($this->manifest?->formatters ?? []);
            $viewModeConfig = new ArrayViewModeConfig(
                is_array($this->config['view_modes'] ?? null) ? $this->config['view_modes'] : [],
            );
            $entityRenderer = new EntityRenderer($this->entityTypeManager, $formatterRegistry, $viewModeConfig);
            $safeViewMode = preg_replace('/[^a-z0-9_]+/i', '', strtolower($requestedViewMode)) ?: 'full';
            $viewMode = new ViewMode($safeViewMode);
            // Content negotiation on the SAME URL: text/markdown for agents (or the
            // ?raw / ?format=md toggle), text/html otherwise. The media type is woven
            // into the cache variant below so the two representations never share a
            // cache entry.
            $mediaType = $this->negotiateMediaType($httpRequest);
            $relationshipContext = $this->buildRelationshipRenderContext($entity);
            $renderContext = $relationshipContext;
            $renderContext['workflow_visibility'] = $visibilityResolver->buildRenderContext($entity, $previewRequested);
            $cacheVariantLangcode = $this->buildSsrCacheVariantLangcode(
                $contentLangcode,
                $viewMode->name,
                $previewRequested,
                $renderContext,
                $mediaType,
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
                    $headers['Vary'] = 'Accept';
                    return $this->htmlResult($cached->getStatusCode(), (string) $cached->getContent(), $headers);
                }
            }

            $twigEntityContext = $renderContext;
            $twigEntityContext['account'] = $account;
            // schema.org JSON-LD for the HTML <head> (FR-014); ignored by the
            // Markdown branch.
            $twigEntityContext['schema_org_jsonld'] = $this->buildSchemaOrgScript($entity, $normalizedPath);

            $response = $mediaType === MediaTypeAcceptNegotiator::MARKDOWN
                ? $this->renderEntityMarkdown($entity, $viewMode, $viewModeConfig, $normalizedPath)
                : new RenderController($twig, $entityRenderer)->renderEntity($entity, $viewMode, $twigEntityContext);
            if (
                !$account->isAuthenticated()
                && !$previewRequested
                && $this->renderCache !== null
                && $entity->id() !== null
                && $response->getStatusCode() === 200
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

            $headers = array_merge($this->extractHeaders($response), $surrogateHeaders);
            $headers['Cache-Control'] = $cacheControlHeader;
            $headers['Vary'] = 'Accept';
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
     * @return array{type: string, status: int, content: string|array, headers: array<string, string>}|HttpResponse
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
            entityTypeManager: $this->entityTypeManager,
            twig: $twig,
            routeParams: $routeParams,
            query: $httpRequest->query->all(),
            gate: $this->gate,
            serviceResolver: $this->serviceResolver,
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
     * @return array<string, mixed>
     */
    public function buildRelationshipRenderContext(EntityInterface $entity): array
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
                        'entity' => $discovery->endpointPage($entityType, $entityId, [
                            'status' => 'published',
                            'limit' => 12,
                        ])['browse'],
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

            return [
                'relationship_navigation' => [
                    'contract' => [
                        'version' => self::DISCOVERY_CONTRACT_VERSION,
                        'stability' => self::DISCOVERY_CONTRACT_STABILITY,
                        'surface' => 'ssr_relationship_navigation',
                    ],
                    'from_endpoint' => [
                        'type' => (string) ($endpoint['from_endpoint']['endpoint']['type'] ?? ''),
                        'id' => (string) ($endpoint['from_endpoint']['endpoint']['id'] ?? ''),
                        'path' => (string) ($endpoint['from_endpoint']['endpoint']['path'] ?? ''),
                        'browse' => $endpoint['from_endpoint']['browse'] ?? [],
                    ],
                    'to_endpoint' => [
                        'type' => (string) ($endpoint['to_endpoint']['endpoint']['type'] ?? ''),
                        'id' => (string) ($endpoint['to_endpoint']['endpoint']['id'] ?? ''),
                        'path' => (string) ($endpoint['to_endpoint']['endpoint']['path'] ?? ''),
                        'browse' => $endpoint['to_endpoint']['browse'] ?? [],
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
     * {@see shouldDenyContentGroupRender()}), exactly as on the numeric path
     * (`load()` does not access-check either), so the uuid and numeric paths are
     * symmetric and the uuid path cannot leak a draft.
     */
    private function loadByIdOrUuid(string $entityTypeId, int|string $id): ?EntityInterface
    {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $keys = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys();

        if (
            isset($keys['uuid'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id) === 1
        ) {
            // C-22 WP2: the query builder now lives on the repository.
            $ids = $this->entityTypeManager->getRepository($entityTypeId)->getQuery()
                ->accessCheck(false)
                ->condition($keys['uuid'], (string) $id)
                ->execute();
            if ($ids === []) {
                return null;
            }

            return $storage->load(reset($ids));
        }

        return $storage->load($id);
    }

    /**
     * The canonical published gate for generic content the node-centric
     * {@see EditorialVisibilityResolver} does not cover (it allows any non-`node`
     * type outright). Returns true when a content-group entity that is not a
     * `node` must be denied for ($account, 'view') per the SAME per-entity
     * AccessPolicy the MCP and JSON:API paths use — PublishedContentAccessPolicy
     * grants anonymous `view` only for published items — so HTML/Markdown can
     * never serve an unpublished draft the other surfaces deny.
     *
     * Nodes are excluded: their published/preview/workflow nuance stays with the
     * editorial resolver, preserving the shipped node behavior. Fails closed —
     * when no access handler is wired, a content render is denied rather than
     * risk leaking a draft through a wiring gap.
     */
    private function shouldDenyContentGroupRender(string $entityTypeId, EntityInterface $entity, AccountInterface $account): bool
    {
        if ($entityTypeId === 'node' || !$this->isContentGroupEntity($entityTypeId)) {
            return false;
        }

        return $this->accessHandler === null
            || !$this->accessHandler->check($entity, 'view', $account)->isAllowed();
    }

    /**
     * Whether an entity type belongs to the `content` group — the set of
     * publishable, anonymously-readable-when-published types. Used to scope the
     * canonical published gate so non-content types (user, taxonomy, …) keep
     * their existing visibility behavior.
     */
    private function isContentGroupEntity(string $entityTypeId): bool
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return false;
        }

        return $this->entityTypeManager->getDefinition($entityTypeId)->getGroup() === 'content';
    }

    /**
     * Build the schema.org JSON-LD `<script>` block for an entity's HTML `<head>`.
     */
    private function buildSchemaOrgScript(EntityInterface $entity, string $canonicalUrl): string
    {
        $mapper = new EntitySchemaOrgMapper();

        return $mapper->toScriptTag($mapper->map($entity, $canonicalUrl));
    }

    /**
     * Render an entity as a Markdown HTTP response via the shared
     * {@see EntityMarkdownPresenter} — the same bytes the `?raw` toggle returns.
     */
    private function renderEntityMarkdown(
        EntityInterface $entity,
        ViewMode $viewMode,
        ArrayViewModeConfig $viewModeConfig,
        string $canonicalUrl,
    ): HttpResponse {
        $presenter = new EntityMarkdownPresenter(
            new ResourceSerializer($this->entityTypeManager),
            $this->entityTypeManager,
            $viewModeConfig,
        );

        $markdown = $presenter->present($entity, $viewMode->name, null, null, $canonicalUrl);

        return new HttpResponse(
            $markdown,
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
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
     * @return array{type: 'html', status: int, content: string, headers: array<string, string>}
     */
    private function htmlResult(int $status, string $content, array $headers = []): array
    {
        return ['type' => 'html', 'status' => $status, 'content' => $content, 'headers' => $headers];
    }

    /**
     * @return array{type: 'json', status: int, content: array, headers: array<string, string>}
     */
    private function jsonResult(int $status, array $content, array $headers = []): array
    {
        return ['type' => 'json', 'status' => $status, 'content' => $content, 'headers' => $headers];
    }

    /**
     * Extract headers from a Symfony Response as a flat string-keyed array.
     *
     * @return array<string, string>
     */
    private function extractHeaders(HttpResponse $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            if (is_array($values) && $values !== []) {
                $headers[$name] = $values[0];
            }
        }

        return $headers;
    }
}
