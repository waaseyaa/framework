<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\Path\PathAliasResolver;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Routing\Language\AcceptHeaderNegotiator;
use Waaseyaa\Routing\Language\LanguageNegotiator;
use Waaseyaa\Routing\Language\UrlPrefixNegotiator;
use Waaseyaa\Workflows\EditorialVisibilityResolver;

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

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly PdoDatabase $database,
        private readonly ?RenderCache $renderCache,
        private readonly CacheConfigResolver $cacheConfigResolver,
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly ?object $manifest = null,
        /** @var (\Closure(string): ?object)|null */
        private readonly ?\Closure $serviceResolver = null,
    ) {}

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
                error_log(sprintf('[Waaseyaa] Twig environment initialization failed: %s', $e->getMessage()));
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
            if ($normalizedPath === '' || $normalizedPath === '/') {
                $response = (new RenderController($twig))->renderPath('/');
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->statusCode, $response->content, $headers);
            }
            if (!str_starts_with($normalizedPath, '/')) {
                $normalizedPath = '/' . $normalizedPath;
            }

            $language = $this->resolveRenderLanguageAndAliasPath($normalizedPath, $httpRequest);
            $contentLangcode = $language['langcode'];
            $aliasLookupPath = $language['alias_path'];
            if ($aliasLookupPath === '/') {
                $response = (new RenderController($twig))->renderPath('/');
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->statusCode, $response->content, $headers);
            }

            $resolved = null;
            if (class_exists(PathAliasResolver::class) && $this->entityTypeManager->hasDefinition('path_alias')) {
                $aliasResolver = new PathAliasResolver($this->entityTypeManager->getStorage('path_alias'));
                $resolved = $aliasResolver->resolve($aliasLookupPath, $contentLangcode);
            }
            if ($resolved === null) {
                $renderController = new RenderController($twig);
                $pathResponse = $renderController->tryRenderPathTemplate($aliasLookupPath);
                if ($pathResponse !== null) {
                    $headers = $pathResponse->headers;
                    $headers['Cache-Control'] = $cacheControlHeader;
                    return $this->htmlResult($pathResponse->statusCode, $pathResponse->content, $headers);
                }
                $response = $renderController->renderNotFound($aliasLookupPath);
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->statusCode, $response->content, $headers);
            }

            $targetStorage = $this->entityTypeManager->getStorage($resolved->entityTypeId);
            $entity = $targetStorage->load($resolved->entityId);
            if ($entity === null) {
                $response = (new RenderController($twig))->renderNotFound($aliasLookupPath);
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->statusCode, $response->content, $headers);
            }

            $previewRequested = $this->isPreviewRequested($httpRequest);
            $visibilityResolver = new EditorialVisibilityResolver();
            $visibility = $visibilityResolver->canRender($entity, $account, $previewRequested);
            if ($visibility->isForbidden()) {
                $response = (new RenderController($twig))->renderForbidden($aliasLookupPath);
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                return $this->htmlResult($response->statusCode, $response->content, $headers);
            }

            $formatterRegistry = SsrServiceProvider::getFormatterRegistry()
                ?? new FieldFormatterRegistry($this->manifest?->formatters ?? []);
            $viewModeConfig = new ArrayViewModeConfig(
                is_array($this->config['view_modes'] ?? null) ? $this->config['view_modes'] : [],
            );
            $entityRenderer = new EntityRenderer($this->entityTypeManager, $formatterRegistry, $viewModeConfig);
            $safeViewMode = preg_replace('/[^a-z0-9_]+/i', '', strtolower($requestedViewMode)) ?: 'full';
            $viewMode = new ViewMode($safeViewMode);
            $relationshipContext = $this->buildRelationshipRenderContext($entity);
            $renderContext = $relationshipContext;
            $renderContext['workflow_visibility'] = $visibilityResolver->buildRenderContext($entity, $previewRequested);
            $cacheVariantLangcode = $this->buildSsrCacheVariantLangcode(
                $contentLangcode,
                $viewMode->name,
                $previewRequested,
                $renderContext,
            );
            $surrogateHeaders = (
                !$account->isAuthenticated()
                && !$previewRequested
                && $entity->id() !== null
            )
                ? $this->buildRenderSurrogateHeaders(
                    $resolved->entityTypeId,
                    (string) $entity->id(),
                    $viewMode->name,
                    $contentLangcode,
                    $cacheVariantLangcode,
                    $renderContext,
                )
                : [];

            if (
                !$account->isAuthenticated()
                && !$previewRequested
                && $this->renderCache !== null
                && $entity->id() !== null
            ) {
                $cached = $this->renderCache->get(
                    $resolved->entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $cacheVariantLangcode,
                );
                if ($cached !== null) {
                    $headers = array_merge($cached->headers, $surrogateHeaders);
                    $headers['Cache-Control'] = $cacheControlHeader;
                    return $this->htmlResult($cached->statusCode, $cached->content, $headers);
                }
            }

            $response = (new RenderController($twig, $entityRenderer))->renderEntity($entity, $viewMode, $renderContext);
            if (
                !$account->isAuthenticated()
                && !$previewRequested
                && $this->renderCache !== null
                && $entity->id() !== null
                && $response->statusCode === 200
            ) {
                $this->renderCache->set(
                    $resolved->entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $cacheVariantLangcode,
                    $response,
                    $cacheMaxAge,
                );
            }

            $headers = array_merge($response->headers, $surrogateHeaders);
            $headers['Cache-Control'] = $cacheControlHeader;
            return $this->htmlResult($response->statusCode, $response->content, $headers);
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] Render pipeline failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
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
     * resolved via reflection — supported types: EntityTypeManager,
     * \Twig\Environment, HttpRequest, AccountInterface.
     * The method receives ($params, $query, $account, $httpRequest).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     * @return array{type: string, status: int, content: string|array, headers: array<string, string>}|HttpResponse
     */
    public function dispatchAppController(
        string $controller,
        array $params,
        array $query,
        AccountInterface $account,
        HttpRequest $httpRequest,
    ): array|HttpResponse {
        [$class, $method] = explode('::', $controller, 2);

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            $twig = SsrServiceProvider::createTwigEnvironment($this->projectRoot, $this->config);
        }

        $instance = $this->resolveControllerInstance($class, $twig, $account, $httpRequest);
        $response = $instance->{$method}($params, $query, $account, $httpRequest);

        if (!$response instanceof HttpResponse) {
            $route = $httpRequest->attributes->get('_route_object');
            $isRenderRoute = $route instanceof \Symfony\Component\Routing\Route
                && $route->getOption('_render') === true;
            if (!$isRenderRoute) {
                error_log(sprintf(
                    '[Waaseyaa] Controller %s::%s returned SsrResponse on a non-render route. '
                    . 'Add ->render() to the RouteBuilder chain to fix SSR dispatch.',
                    $class,
                    $method,
                ));
            }
        }

        if ($response instanceof HttpResponse) {
            $cacheMaxAge = $this->cacheConfigResolver->resolveRenderCacheMaxAge();
            $response->headers->set('Cache-Control', $this->cacheConfigResolver->cacheControlHeaderForRender($account, $cacheMaxAge));
            return $response;
        }

        $cacheMaxAge = $this->cacheConfigResolver->resolveRenderCacheMaxAge();
        $headers = $response->headers;
        $headers['Cache-Control'] = $this->cacheConfigResolver->cacheControlHeaderForRender($account, $cacheMaxAge);
        return $this->htmlResult($response->statusCode, $response->content, $headers);
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
                $resolved = ($this->serviceResolver)($type->getName());
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
                    error_log(sprintf(
                        '[Waaseyaa] Cannot resolve constructor parameter $%s (%s) for controller %s',
                        $param->getName(),
                        $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed',
                        $class,
                    ));
                    $args[] = null;
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
                new RelationshipTraversalService($this->entityTypeManager, $this->database),
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

            $values = $entity->toArray();
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
            error_log(sprintf('[Waaseyaa] Relationship render context failed: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * @return array{langcode: string, alias_path: string}
     */
    public function resolveRenderLanguageAndAliasPath(string $path, HttpRequest $request): array
    {
        $manager = $this->buildLanguageManager();
        $availableLanguages = array_keys($manager->getLanguages());

        $headers = [];
        $acceptLanguage = $request->headers->get('Accept-Language');
        if (is_string($acceptLanguage) && trim($acceptLanguage) !== '') {
            $headers['accept-language'] = $acceptLanguage;
        }

        $context = (new LanguageNegotiator(
            negotiators: [new UrlPrefixNegotiator(), new AcceptHeaderNegotiator()],
            languageManager: $manager,
        ))->negotiate($path, $headers);
        $langcode = $context->getContentLanguage()->id;

        $aliasPath = $path;
        $prefixLangcode = $this->detectLanguagePrefixFromPath($path, $availableLanguages);
        if ($prefixLangcode !== null) {
            $aliasPath = $this->stripLanguagePrefix($path, $prefixLangcode);
        }

        return [
            'langcode' => $langcode,
            'alias_path' => $aliasPath,
        ];
    }

    public function buildLanguageManager(): LanguageManager
    {
        $configured = $this->config['i18n']['languages'] ?? null;
        if (!is_array($configured) || $configured === []) {
            return new LanguageManager([new Language(id: 'en', label: 'English', isDefault: true)]);
        }

        $languages = [];
        foreach ($configured as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $id = isset($candidate['id']) && is_string($candidate['id']) ? trim($candidate['id']) : '';
            if ($id === '') {
                continue;
            }

            $label = isset($candidate['label']) && is_string($candidate['label']) && trim($candidate['label']) !== ''
                ? trim($candidate['label'])
                : strtoupper($id);
            $direction = isset($candidate['direction']) && is_string($candidate['direction']) && in_array($candidate['direction'], ['ltr', 'rtl'], true)
                ? $candidate['direction']
                : 'ltr';
            $weight = isset($candidate['weight']) && is_numeric($candidate['weight']) ? (int) $candidate['weight'] : 0;
            $isDefault = ($candidate['is_default'] ?? false) === true;

            $languages[] = new Language(
                id: $id,
                label: $label,
                direction: $direction,
                weight: $weight,
                isDefault: $isDefault,
            );
        }

        if ($languages === []) {
            return new LanguageManager([new Language(id: 'en', label: 'English', isDefault: true)]);
        }

        $defaultCount = count(array_filter($languages, static fn(Language $language): bool => $language->isDefault));
        if ($defaultCount === 0) {
            $first = $languages[0];
            $languages[0] = new Language(
                id: $first->id,
                label: $first->label,
                direction: $first->direction,
                weight: $first->weight,
                isDefault: true,
            );
        }
        if ($defaultCount > 1) {
            $seenDefault = false;
            foreach ($languages as $index => $language) {
                if (!$language->isDefault) {
                    continue;
                }
                if (!$seenDefault) {
                    $seenDefault = true;
                    continue;
                }

                $languages[$index] = new Language(
                    id: $language->id,
                    label: $language->label,
                    direction: $language->direction,
                    weight: $language->weight,
                    isDefault: false,
                );
            }
        }

        return new LanguageManager($languages);
    }

    /**
     * @param list<string> $availableLanguages
     */
    public function detectLanguagePrefixFromPath(string $path, array $availableLanguages): ?string
    {
        $segments = explode('/', ltrim($path, '/'));
        if ($segments === [] || $segments[0] === '') {
            return null;
        }

        $prefix = $segments[0];
        if (in_array($prefix, $availableLanguages, true)) {
            return $prefix;
        }

        return null;
    }

    public function stripLanguagePrefix(string $path, string $langcode): string
    {
        $prefix = '/' . ltrim($langcode, '/');
        if ($path === $prefix) {
            return '/';
        }
        if (str_starts_with($path, $prefix . '/')) {
            $stripped = substr($path, strlen($prefix));
            return $stripped !== '' ? $stripped : '/';
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function buildSsrCacheVariantLangcode(
        string $langcode,
        string $viewMode,
        bool $previewRequested,
        array $renderContext,
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
                $graphHash = substr(sha1((string) $serialized), 0, 12);
            }
        }

        $variantPayload = [
            'contract_version' => self::DISCOVERY_CONTRACT_VERSION,
            'langcode' => strtolower(trim($langcode)),
            'view_mode' => strtolower(trim($viewMode)),
            'preview' => $previewRequested ? 1 : 0,
            'workflow_state' => $workflowState,
            'graph_hash' => $graphHash,
        ];
        $serializedVariantPayload = json_encode($this->discoveryHandler->normalizeForCacheKey($variantPayload), JSON_THROW_ON_ERROR);
        $variantHash = substr(sha1((string) $serializedVariantPayload), 0, 16);

        return sprintf(
            'v2:%s:%s:%s:%s:%s',
            $this->sanitizeCacheToken($langcode, 'unknown'),
            $this->sanitizeCacheToken($viewMode, 'full'),
            $previewRequested ? 'preview' : 'public',
            $this->sanitizeCacheToken($workflowState, 'unknown'),
            $variantHash,
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
            $graphHash = substr(sha1((string) $serialized), 0, 12);
        }

        $keys = array_values(array_unique([
            'waaseyaa:ssr',
            'waaseyaa:ssr:entity:' . $this->sanitizeCacheToken($entityType, 'entity'),
            'waaseyaa:ssr:entity:' . $this->sanitizeCacheToken($entityType, 'entity') . ':' . $this->sanitizeCacheToken($entityId, '0'),
            'waaseyaa:ssr:workflow:' . $this->sanitizeCacheToken($workflowState, 'unknown'),
            'waaseyaa:ssr:view:' . $this->sanitizeCacheToken($viewMode, 'full'),
            'waaseyaa:ssr:lang:' . $this->sanitizeCacheToken($langcode, 'unknown'),
            'waaseyaa:ssr:graph:' . $this->sanitizeCacheToken($graphHash, 'none'),
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
}
