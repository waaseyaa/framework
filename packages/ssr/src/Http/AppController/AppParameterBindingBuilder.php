<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http\AppController;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Route;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Attribute\ContentEntityTypeReader;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteFingerprint;
use Waaseyaa\SSR\Attribute\FromRoute;
use Waaseyaa\SSR\Attribute\MapQuery;
use Waaseyaa\SSR\Attribute\MapRoute;
use Waaseyaa\SSR\Http\AppController\Exception\AppControllerTypeMismatchException;
use Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerBindingException;

/**
 * Builds immutable {@see AppParameterBindingSpec} lists for a controller action.
 */
final class AppParameterBindingBuilder
{
    /** @var list<class-string> */
    private const array SERVICE_TYPES = [
        HttpRequest::class,
        AccountInterface::class,
        EntityTypeManagerInterface::class,
        EntityTypeManager::class,
        Environment::class,
        \Waaseyaa\Access\Gate\GateInterface::class,
        Redirector::class,
    ];

    private readonly LoggerInterface $logger;

    /** @var array<string, true> */
    private array $emittedKeys = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param list<AppControllerArgumentResolver> $customResolvers
     * @return list<AppParameterBindingSpec>
     */
    public function build(
        \ReflectionMethod $method,
        Route $route,
        bool $strict,
        ?\Waaseyaa\Access\Gate\GateInterface $gate,
        ?HttpServiceResolverInterface $serviceResolver,
        array $customResolvers,
    ): array {
        $routeParameters = $route->getOption('parameters');
        if (!is_array($routeParameters)) {
            $routeParameters = [];
        }
        $bindings = $route->getOption(RouteFingerprint::BINDINGS_OPTION);
        if (!is_array($bindings)) {
            $bindings = [];
        }

        $compiled = $route->compile();
        /** @var list<string> $pathVariables */
        $pathVariables = $compiled->getVariables();
        $defaults = $route->getDefaults();

        $specs = [];
        $seenServiceTypes = [];

        foreach ($method->getParameters() as $index => $parameter) {
            $specs[] = $this->buildForParameter(
                $index,
                $parameter,
                $method,
                $route,
                $strict,
                $gate,
                $serviceResolver,
                $customResolvers,
                $routeParameters,
                $bindings,
                $pathVariables,
                $defaults,
                $seenServiceTypes,
            );
        }

        return $specs;
    }

    /**
     * @param array<string, mixed> $routeParameters
     * @param array<string, class-string> $bindings
     * @param list<string> $pathVariables
     * @param array<string, mixed> $defaults
     * @param array<string, true> $seenServiceTypes
     */
    private function buildForParameter(
        int $index,
        \ReflectionParameter $parameter,
        \ReflectionMethod $method,
        Route $route,
        bool $strict,
        ?\Waaseyaa\Access\Gate\GateInterface $gate,
        ?HttpServiceResolverInterface $serviceResolver,
        array $customResolvers,
        array $routeParameters,
        array $bindings,
        array $pathVariables,
        array $defaults,
        array &$seenServiceTypes,
    ): AppParameterBindingSpec {
        $name = $parameter->getName();

        foreach ($parameter->getAttributes(MapRoute::class) as $_) {
            $this->assertArrayParameter($parameter, 'MapRoute');
            return new AppParameterBindingSpec(
                index: $index,
                kind: AppParameterKind::MapRoute,
            );
        }

        foreach ($parameter->getAttributes(MapQuery::class) as $_) {
            $this->assertArrayParameter($parameter, 'MapQuery');
            return new AppParameterBindingSpec(
                index: $index,
                kind: AppParameterKind::MapQuery,
            );
        }

        $fromRouteName = null;
        foreach ($parameter->getAttributes(FromRoute::class) as $attr) {
            $fromRouteName = $attr->newInstance()->name;
            break;
        }

        $named = $this->primaryNamedType($parameter);
        if ($named === null) {
            throw new InvalidAppControllerBindingException(sprintf(
                'Parameter $%s of %s::%s() must have a type hint for app-controller binding.',
                $name,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        $typeName = $named->getName();

        if ($named->isBuiltin()) {
            if ($typeName === 'array') {
                if ($name === 'params') {
                    $this->emitDeprecation(
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                        $name,
                        'MapRoute',
                    );

                    return new AppParameterBindingSpec(
                        index: $index,
                        kind: AppParameterKind::MapRoute,
                    );
                }
                if ($name === 'query') {
                    $this->emitDeprecation(
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                        $name,
                        'MapQuery',
                    );

                    return new AppParameterBindingSpec(
                        index: $index,
                        kind: AppParameterKind::MapQuery,
                    );
                }

                $this->emitUnboundDeprecation(
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $name,
                );

                return new AppParameterBindingSpec(
                    index: $index,
                    kind: AppParameterKind::ImplicitEmptyArray,
                );
            }

            return $this->buildScalarSpec(
                $index,
                $parameter,
                $route,
                $strict,
                $named,
                $fromRouteName,
                $routeParameters,
                $pathVariables,
                $defaults,
            );
        }

        if (enum_exists($typeName)) {
            $reflEnum = new \ReflectionEnum($typeName);
            if (!$reflEnum->isBacked()) {
                throw new InvalidAppControllerBindingException(sprintf(
                    'Parameter $%s: only backed enums are supported for route binding.',
                    $name,
                ));
            }

            $routeKey = $fromRouteName ?? $this->resolveRouteParameterName($name, $route, $pathVariables, $defaults);

            return new AppParameterBindingSpec(
                index: $index,
                kind: AppParameterKind::RouteEnum,
                routeKey: $routeKey,
                enumClass: $typeName,
            );
        }

        if (is_a($typeName, EntityInterface::class, true)) {
            return $this->buildEntitySpec(
                $index,
                $parameter,
                $route,
                $strict,
                $typeName,
                $fromRouteName,
                $routeParameters,
                $bindings,
                $pathVariables,
                $defaults,
            );
        }

        $serviceSpec = $this->tryServiceSpec(
            $index,
            $typeName,
            $gate,
            $serviceResolver,
            $seenServiceTypes,
        );
        if ($serviceSpec !== null) {
            return $serviceSpec;
        }

        $resolverIndex = 0;
        foreach ($customResolvers as $resolver) {
            if ($resolver->supports($parameter, $route)) {
                return new AppParameterBindingSpec(
                    index: $index,
                    kind: AppParameterKind::Custom,
                    customResolverIndex: $resolverIndex,
                );
            }
            ++$resolverIndex;
        }

        throw new InvalidAppControllerBindingException(sprintf(
            'Parameter $%s of type %s cannot be bound (not a service, entity, scalar, enum, or custom resolver).',
            $name,
            $typeName,
        ));
    }

    /**
     * @param array<string, mixed> $routeParameters
     * @param list<string> $pathVariables
     * @param array<string, mixed> $defaults
     */
    private function buildScalarSpec(
        int $index,
        \ReflectionParameter $parameter,
        Route $route,
        bool $strict,
        \ReflectionNamedType $named,
        ?string $fromRouteName,
        array $routeParameters,
        array $pathVariables,
        array $defaults,
    ): AppParameterBindingSpec {
        $name = $parameter->getName();
        $typeName = $named->getName();
        if (!in_array($typeName, ['string', 'int', 'float', 'bool'], true)) {
            throw new InvalidAppControllerBindingException(sprintf(
                'Parameter $%s: unsupported scalar type %s for route binding.',
                $name,
                $typeName,
            ));
        }

        $routeKey = $fromRouteName ?? $this->resolveRouteParameterName($name, $route, $pathVariables, $defaults);

        if ($strict) {
            $declared = $routeParameters[$routeKey]['type'] ?? null;
            if ($declared !== null && is_string($declared) && str_starts_with($declared, 'entity:')) {
                throw new InvalidAppControllerBindingException(sprintf(
                    'Parameter $%s: route declares entity for key %s but action parameter is scalar %s.',
                    $name,
                    $routeKey,
                    $typeName,
                ));
            }
        }

        return new AppParameterBindingSpec(
            index: $index,
            kind: AppParameterKind::RouteScalar,
            routeKey: $routeKey,
            scalarKind: $typeName,
        );
    }

    /**
     * @param array<string, mixed> $routeParameters
     * @param array<string, class-string> $bindings
     * @param list<string> $pathVariables
     * @param array<string, mixed> $defaults
     */
    private function buildEntitySpec(
        int $index,
        \ReflectionParameter $parameter,
        Route $route,
        bool $strict,
        string $entityPhpClass,
        ?string $fromRouteName,
        array $routeParameters,
        array $bindings,
        array $pathVariables,
        array $defaults,
    ): AppParameterBindingSpec {
        $name = $parameter->getName();
        $routeKey = $fromRouteName ?? $this->resolveRouteParameterName($name, $route, $pathVariables, $defaults);

        $declared = $routeParameters[$routeKey]['type'] ?? null;
        if (!is_string($declared) || !str_starts_with($declared, 'entity:')) {
            throw new InvalidAppControllerBindingException(sprintf(
                'Parameter $%s: route must declare entity:{type} for key %s (use RouteBuilder::entityParameter).',
                $name,
                $routeKey,
            ));
        }

        $entityTypeId = substr($declared, strlen('entity:'));
        if ($entityTypeId === '') {
            throw new InvalidAppControllerBindingException(sprintf('Invalid entity route declaration for key %s.', $routeKey));
        }

        $attrId = ContentEntityTypeReader::entityTypeIdForClass($entityPhpClass);
        if ($strict) {
            if ($attrId === null) {
                throw new InvalidAppControllerBindingException(sprintf(
                    'Parameter $%s: class %s must declare #[ContentEntityType] in strict mode.',
                    $name,
                    $entityPhpClass,
                ));
            }
            if ($attrId !== $entityTypeId) {
                throw new AppControllerTypeMismatchException(sprintf(
                    'Parameter $%s: #[ContentEntityType(%s)] does not match route entity:%s.',
                    $name,
                    $attrId,
                    $entityTypeId,
                ));
            }
        }

        $boundClass = $bindings[$routeKey] ?? null;
        if ($boundClass !== null && !is_a($entityPhpClass, $boundClass, true) && $strict) {
            throw new AppControllerTypeMismatchException(sprintf(
                'Parameter $%s: Route::bind expects %s but parameter type is %s.',
                $name,
                $boundClass,
                $entityPhpClass,
            ));
        }

        return new AppParameterBindingSpec(
            index: $index,
            kind: AppParameterKind::RouteEntity,
            routeKey: $routeKey,
            entityTypeId: $entityTypeId,
            entityPhpClass: $entityPhpClass,
            boundClass: is_string($boundClass) ? $boundClass : null,
        );
    }

    /**
     * @param array<string, true> $seenServiceTypes
     */
    private function tryServiceSpec(
        int $index,
        string $typeName,
        ?\Waaseyaa\Access\Gate\GateInterface $gate,
        ?HttpServiceResolverInterface $serviceResolver,
        array &$seenServiceTypes,
    ): ?AppParameterBindingSpec {
        if ($typeName === \Waaseyaa\Access\Gate\GateInterface::class && $gate === null) {
            throw new InvalidAppControllerBindingException('GateInterface was requested but no gate is configured.');
        }

        foreach (self::SERVICE_TYPES as $serviceType) {
            if ($serviceType === \Waaseyaa\Access\Gate\GateInterface::class && $gate === null) {
                continue;
            }
            if ($typeName !== $serviceType && !is_a($serviceType, $typeName, true)) {
                continue;
            }
            if (isset($seenServiceTypes[$typeName])) {
                throw new InvalidAppControllerBindingException(sprintf(
                    'Duplicate service type %s in the same action signature.',
                    $typeName,
                ));
            }
            $seenServiceTypes[$typeName] = true;

            return new AppParameterBindingSpec(
                index: $index,
                kind: AppParameterKind::FrameworkService,
                serviceClass: $typeName,
            );
        }

        if ($serviceResolver !== null) {
            $resolved = $serviceResolver->resolve($typeName);
            if ($resolved !== null) {
                if (!is_a($resolved, $typeName, true)) {
                    throw new AppControllerTypeMismatchException(sprintf(
                        'HTTP service resolver returned %s which is not a %s.',
                        $resolved::class,
                        $typeName,
                    ));
                }
                if (isset($seenServiceTypes[$typeName])) {
                    throw new InvalidAppControllerBindingException(sprintf(
                        'Duplicate service type %s in the same action signature.',
                        $typeName,
                    ));
                }
                $seenServiceTypes[$typeName] = true;

                return new AppParameterBindingSpec(
                    index: $index,
                    kind: AppParameterKind::FrameworkService,
                    serviceClass: $typeName,
                );
            }
        }

        return null;
    }

    /**
     * Emit the structured `implicit_array_shim` deprecation signal.
     *
     * Schema (locked by post-#1390 dispatcher contract §5):
     * - `channel` — `'dispatcher.deprecation'`
     * - `event` — `'implicit_array_shim'`
     * - `controller_class` (FQCN) — declaring class of the action
     * - `method` — action method name
     * - `parameter_name` (`'params'`|`'query'`) — which implicit parameter fired the shim
     * - `recommended_attribute` (`'MapRoute'`|`'MapQuery'`) — bare attribute name to add
     *
     * Dedup key is `controllerClass::method::parameterName` for the lifetime
     * of this binding-builder instance. Effective scope under FPM is once per
     * triple per worker lifetime, because `AppControllerMethodInvoker::$specCache`
     * (private static) caches the built spec and bypasses this method on
     * subsequent requests for the same route. See contract §7 erratum (#1392).
     */
    private function emitDeprecation(
        string $controllerClass,
        string $method,
        string $parameterName,
        string $recommendedAttribute,
    ): void {
        $key = $controllerClass . '::' . $method . '::' . $parameterName;
        if (isset($this->emittedKeys[$key])) {
            return;
        }
        $this->emittedKeys[$key] = true;

        $this->logger->notice(
            sprintf(
                'Controller %s::%s parameter $%s relies on the implicit-array shim; add #[%s] to suppress this notice.',
                $controllerClass,
                $method,
                $parameterName,
                $recommendedAttribute,
            ),
            [
                'channel' => 'dispatcher.deprecation',
                'event' => 'implicit_array_shim',
                'controller_class' => $controllerClass,
                'method' => $method,
                'parameter_name' => $parameterName,
                'recommended_attribute' => $recommendedAttribute,
            ],
        );
    }

    /**
     * Emit the structured `implicit_array_unbound` deprecation signal.
     *
     * Fired when an unannotated `array $X` parameter is neither named `params`
     * nor `query`. The dispatcher injects `[]` for this parameter (see
     * {@see AppParameterKind::ImplicitEmptyArray}). `recommended_attribute`
     * is the empty string; the operator must choose `MapRoute`, `MapQuery`,
     * or remove the parameter.
     */
    private function emitUnboundDeprecation(
        string $controllerClass,
        string $method,
        string $parameterName,
    ): void {
        $key = $controllerClass . '::' . $method . '::' . $parameterName;
        if (isset($this->emittedKeys[$key])) {
            return;
        }
        $this->emittedKeys[$key] = true;

        $this->logger->notice(
            sprintf(
                'Controller %s::%s parameter $%s declares array without a binding attribute; the dispatcher injects []. Add an explicit binding or remove the parameter.',
                $controllerClass,
                $method,
                $parameterName,
            ),
            [
                'channel' => 'dispatcher.deprecation',
                'event' => 'implicit_array_unbound',
                'controller_class' => $controllerClass,
                'method' => $method,
                'parameter_name' => $parameterName,
                'recommended_attribute' => '',
            ],
        );
    }

    private function assertArrayParameter(\ReflectionParameter $parameter, string $attribute): void
    {
        $named = $this->primaryNamedType($parameter);
        if ($named === null || $named->getName() !== 'array' || !$named->isBuiltin()) {
            throw new InvalidAppControllerBindingException(sprintf(
                'Parameter $%s with #[%s] must be typed as array.',
                $parameter->getName(),
                $attribute,
            ));
        }
    }

    private function primaryNamedType(\ReflectionParameter $parameter): ?\ReflectionNamedType
    {
        $t = $parameter->getType();
        if ($t === null) {
            return null;
        }
        if ($t instanceof \ReflectionNamedType) {
            return $t;
        }
        if ($t instanceof \ReflectionUnionType) {
            $named = [];
            foreach ($t->getTypes() as $inner) {
                if ($inner instanceof \ReflectionNamedType) {
                    if ($inner->getName() !== 'null') {
                        $named[] = $inner;
                    }

                    continue;
                }
                throw new InvalidAppControllerBindingException('Intersection types are not supported for app controllers.');
            }
            if (count($named) > 1) {
                throw new InvalidAppControllerBindingException('Union types with more than one non-null member are not supported.');
            }

            return $named[0] ?? null;
        }
        if ($t instanceof \ReflectionIntersectionType) {
            throw new InvalidAppControllerBindingException('Intersection types are not supported for app controllers.');
        }

        return null;
    }

    /**
     * @param list<string> $pathVariables
     * @param array<string, mixed> $defaults
     */
    private function resolveRouteParameterName(string $paramName, Route $route, array $pathVariables, array $defaults): string
    {
        foreach ($this->routeKeyCandidates($paramName) as $candidate) {
            if (in_array($candidate, $pathVariables, true)) {
                return $candidate;
            }
        }

        foreach ($this->routeKeyCandidates($paramName) as $candidate) {
            if (array_key_exists($candidate, $defaults) && !str_starts_with($candidate, '_')) {
                return $candidate;
            }
        }

        throw new InvalidAppControllerBindingException(sprintf(
            'Cannot infer route key for parameter $%s on path %s (try #[FromRoute]).',
            $paramName,
            $route->getPath(),
        ));
    }

    /**
     * @return list<string>
     */
    private function routeKeyCandidates(string $paramName): array
    {
        $candidates = [$paramName];
        $snake = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $paramName));
        if ($snake !== $paramName) {
            $candidates[] = $snake;
        }

        return array_values(array_unique($candidates));
    }
}
