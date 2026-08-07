<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http\AppController;

use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Routing\RouteFingerprint;
use Waaseyaa\SSR\Http\AppController\Exception\AppControllerTypeMismatchException;
use Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerArgumentException;
use Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerBindingException;

final class AppControllerMethodInvoker
{
    /** @var array<string, list<AppParameterBindingSpec>> */
    private static array $specCache = [];

    private readonly AppParameterBindingBuilder $bindingBuilder;

    public function __construct(
        ?AppParameterBindingBuilder $bindingBuilder = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->bindingBuilder = $bindingBuilder ?? new AppParameterBindingBuilder($logger ?? new NullLogger());
    }

    /**
     * @param list<AppControllerArgumentResolver> $customResolvers
     */
    public function invoke(
        object $instance,
        string $methodName,
        Route $route,
        ?string $routeName,
        AppInvocationContext $ctx,
        bool $strict,
        ?\Waaseyaa\Access\Gate\GateInterface $gate,
        ?HttpServiceResolverInterface $serviceResolver,
        array $customResolvers,
    ): mixed {
        $class = $instance::class;
        $fp = RouteFingerprint::hash($route);
        $cacheKey = $class . '::' . $methodName . "\0" . ($routeName ?? '') . "\0" . $fp;

        if (!isset(self::$specCache[$cacheKey])) {
            $ref = new \ReflectionMethod($instance, $methodName);
            self::$specCache[$cacheKey] = $this->bindingBuilder->build(
                $ref,
                $route,
                $strict,
                $gate,
                $serviceResolver,
                $customResolvers,
            );
        }

        $specs = self::$specCache[$cacheKey];
        $ref = new \ReflectionMethod($instance, $methodName);
        $reflectionParams = $ref->getParameters();
        $args = [];
        foreach ($specs as $i => $spec) {
            $args[] = $this->resolveArgument(
                $spec,
                $reflectionParams[$i],
                $ctx,
                $customResolvers,
            );
        }

        return $ref->invokeArgs($instance, $args);
    }

    /**
     * @param list<AppControllerArgumentResolver> $customResolvers
     */
    private function resolveArgument(
        AppParameterBindingSpec $spec,
        \ReflectionParameter $reflectionParameter,
        AppInvocationContext $ctx,
        array $customResolvers,
    ): mixed {
        return match ($spec->kind) {
            AppParameterKind::FrameworkService => $this->resolveService($spec->serviceClass, $ctx),
            AppParameterKind::MapRoute => $ctx->routeParams,
            AppParameterKind::MapQuery => $ctx->query,
            AppParameterKind::ImplicitEmptyArray => [],
            AppParameterKind::RouteScalar => $this->resolveScalar($reflectionParameter, $spec, $ctx),
            AppParameterKind::RouteEnum => $this->resolveEnum($reflectionParameter, $spec, $ctx),
            AppParameterKind::RouteEntity => $this->resolveEntity($reflectionParameter, $spec, $ctx),
            AppParameterKind::Custom => $this->resolveCustom($spec, $reflectionParameter, $ctx, $customResolvers),
        };
    }

    private function resolveService(?string $serviceClass, AppInvocationContext $ctx): object
    {
        if ($serviceClass === null) {
            throw new InvalidAppControllerBindingException('Missing serviceClass on binding spec.');
        }

        if (is_a($ctx->request, $serviceClass, true)) {
            return $ctx->request;
        }
        if ($ctx->decisionAccount !== null && is_a($ctx->decisionAccount, $serviceClass, true)) {
            return $ctx->decisionAccount;
        }
        if (is_a($ctx->account, $serviceClass, true)) {
            return $ctx->account;
        }
        if (is_a($ctx->entityTypeManager, $serviceClass, true)) {
            return $ctx->entityTypeManager;
        }
        if (is_a($ctx->twig, $serviceClass, true)) {
            return $ctx->twig;
        }
        if ($ctx->gate !== null && is_a($ctx->gate, $serviceClass, true)) {
            return $ctx->gate;
        }
        if ($ctx->redirector !== null && is_a($ctx->redirector, $serviceClass, true)) {
            return $ctx->redirector;
        }
        if ($ctx->serviceResolver !== null) {
            $resolved = $ctx->serviceResolver->resolve($serviceClass);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new InvalidAppControllerBindingException(sprintf('Could not resolve service parameter type %s.', $serviceClass));
    }

    private function resolveScalar(
        \ReflectionParameter $reflectionParameter,
        AppParameterBindingSpec $spec,
        AppInvocationContext $ctx,
    ): mixed {
        $key = $spec->routeKey;
        $raw = $key !== null ? ($ctx->routeParams[$key] ?? null) : null;

        if ($reflectionParameter->allowsNull() && ($raw === null || $raw === '')) {
            return null;
        }

        $kind = $spec->scalarKind;
        if ($kind === null) {
            throw new InvalidAppControllerBindingException('Missing scalarKind on binding spec.');
        }

        try {
            return match ($kind) {
                'string' => $this->castRouteString($raw),
                'int' => $this->castRouteInt($raw),
                'float' => $this->castRouteFloat($raw),
                'bool' => $this->castRouteBool($raw),
                default => throw new InvalidAppControllerBindingException('Unsupported scalar kind.'),
            };
        } catch (\InvalidArgumentException $e) {
            throw new InvalidAppControllerArgumentException($e->getMessage(), 0, $e);
        }
    }

    private function resolveEnum(
        \ReflectionParameter $reflectionParameter,
        AppParameterBindingSpec $spec,
        AppInvocationContext $ctx,
    ): mixed {
        $enumClass = $spec->enumClass;
        $key = $spec->routeKey;
        if ($enumClass === null || $key === null) {
            throw new InvalidAppControllerBindingException('Invalid enum binding spec.');
        }

        $raw = $ctx->routeParams[$key] ?? null;
        if ($reflectionParameter->allowsNull() && ($raw === null || $raw === '')) {
            return null;
        }

        if (!is_string($raw) && !is_int($raw)) {
            throw new InvalidAppControllerArgumentException(sprintf('Invalid value for enum parameter bound to route key %s.', $key));
        }

        $refl = new \ReflectionEnum($enumClass);
        $backing = $refl->getBackingType();
        $tryFrom = null;
        if ($backing instanceof \ReflectionNamedType) {
            if ($backing->getName() === 'int' && is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
                $tryFrom = $enumClass::tryFrom((int) $raw);
            } elseif ($backing->getName() === 'int' && is_int($raw)) {
                $tryFrom = $enumClass::tryFrom($raw);
            } else {
                $tryFrom = $enumClass::tryFrom((string) $raw);
            }
        }
        if ($tryFrom === null) {
            throw new InvalidAppControllerArgumentException(sprintf('Invalid enum value for route key %s.', $key));
        }

        return $tryFrom;
    }

    private function resolveEntity(
        \ReflectionParameter $reflectionParameter,
        AppParameterBindingSpec $spec,
        AppInvocationContext $ctx,
    ): mixed {
        $key = $spec->routeKey;
        $entityTypeId = $spec->entityTypeId;
        $phpClass = $spec->entityPhpClass;
        if ($key === null || $entityTypeId === null || $phpClass === null) {
            throw new InvalidAppControllerBindingException('Invalid entity binding spec.');
        }

        $raw = $ctx->routeParams[$key] ?? null;

        if ($reflectionParameter->allowsNull() && ($raw === null || $raw === '')) {
            return null;
        }

        if ($raw instanceof EntityInterface) {
            // HttpKernel's EntityParamConverter normally upcasts before app
            // controller dispatch. Accept that canonical boundary object
            // instead of loading the same row a second time.
            $entity = $raw;
        } else {
            if (!is_int($raw) && (!is_string($raw) || $raw === '')) {
                throw new InvalidAppControllerArgumentException(sprintf('Missing or invalid id for entity route key %s.', $key));
            }

            // Direct invoker callers still resolve through the canonical repository.
            $entity = $ctx->entityTypeManager->getRepository($entityTypeId)->find((string) $raw);
        }
        if ($entity === null) {
            throw new ResourceNotFoundException(sprintf('No %s entity for id %s.', $entityTypeId, (string) $raw));
        }

        if (!is_a($entity, $phpClass, true)) {
            throw new AppControllerTypeMismatchException(sprintf(
                'Loaded entity is not an instance of %s.',
                $phpClass,
            ));
        }

        if ($spec->boundClass !== null && !is_a($entity, $spec->boundClass, true)) {
            throw new AppControllerTypeMismatchException(sprintf(
                'Loaded entity does not satisfy Route::bind(%s, %s).',
                $key,
                $spec->boundClass,
            ));
        }

        // Entity injection is a read boundary, not merely type conversion.
        // Fail closed when no gate is wired, and collapse a denied row to the
        // same 404 as a missing row so binding cannot become an existence oracle.
        if ($ctx->gate === null
            || $ctx->decisionAccount === null
            || !$ctx->gate->allows(GateInterface::VIEW, $entity, $ctx->decisionAccount)
        ) {
            throw new ResourceNotFoundException(sprintf('No %s entity for route key %s.', $entityTypeId, $key));
        }

        return $entity;
    }

    /**
     * @param list<AppControllerArgumentResolver> $customResolvers
     */
    private function resolveCustom(
        AppParameterBindingSpec $spec,
        \ReflectionParameter $reflectionParameter,
        AppInvocationContext $ctx,
        array $customResolvers,
    ): mixed {
        $idx = $spec->customResolverIndex;
        if ($idx < 0 || !isset($customResolvers[$idx])) {
            throw new InvalidAppControllerBindingException('Invalid custom resolver index.');
        }

        return $customResolvers[$idx]->resolve($reflectionParameter, $ctx);
    }

    private function castRouteString(mixed $raw): string
    {
        if ($raw === null) {
            throw new \InvalidArgumentException('Missing route value for string parameter.');
        }
        if (is_string($raw) || is_int($raw) || is_float($raw) || is_bool($raw)) {
            return (string) $raw;
        }

        throw new \InvalidArgumentException('Invalid string route argument.');
    }

    private function castRouteInt(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '' && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        throw new \InvalidArgumentException('Invalid integer route argument.');
    }

    private function castRouteFloat(mixed $raw): float
    {
        if (is_float($raw) || is_int($raw)) {
            return (float) $raw;
        }
        if (is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }

        throw new \InvalidArgumentException('Invalid float route argument.');
    }

    private function castRouteBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 1 || $raw === 0) {
            return (bool) $raw;
        }
        if ($raw === '1' || $raw === '0' || $raw === 'true' || $raw === 'false') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        throw new \InvalidArgumentException('Invalid boolean route argument.');
    }
}
