<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\ServiceProviderInterface;

/** Bootstrap-only source provenance. Never called by metadata inspection. @internal */
final class RouteParticipationCompiler
{
    /** @param array<array-key, mixed> $roster Untrusted bootstrap input. */
    public function compile(array $roster): array
    {
        if (!array_is_list($roster)) {
            throw new RouteCompositionException('inventory-unavailable', 'Route participation roster is invalid.');
        }
        foreach ($roster as $provider) {
            if (!is_string($provider)) {
                throw new RouteCompositionException('inventory-unavailable', 'Route participation roster is invalid.');
            }
        }
        if (count(array_unique($roster)) !== count($roster)) {
            throw new RouteCompositionException('inventory-unavailable', 'Route participation roster contains duplicates.');
        }
        $records = [];
        foreach ($roster as $provider) {
            $class = new \ReflectionClass($provider);
            if (!$class->implementsInterface(ServiceProviderInterface::class) || $class->isAbstract() || $class->isAnonymous()) {
                throw new RouteCompositionException('inventory-unavailable', 'Route participation requires concrete service providers.');
            }
            $method = $class->getMethod('routes');
            $kind = $class->implementsInterface(ContributesRouteMetadataInterface::class)
                ? 'declarative'
                : ($method->getDeclaringClass()->getName() === ServiceProvider::class ? 'none' : 'legacy');
            $provenance = [];
            $this->sources($class, $provenance);
            ksort($provenance);
            $records[] = [
                'provider' => $provider,
                'kind' => $kind,
                'method_owner' => $kind === 'declarative' ? $class->getMethod('routeDefinitions')->getDeclaringClass()->getName() : $method->getDeclaringClass()->getName(),
                'provenance' => $provenance,
                'source_digest' => hash('sha256', json_encode($provenance, JSON_THROW_ON_ERROR)),
            ];
        }
        $code = [];
        foreach (['RouteParticipationCompiler', 'ValidatedRouteParticipation', 'RouteCompositionEpoch', 'RouteCompositionException', 'RouteDefinition', 'RouteContributionContext', 'HandlerReference', 'RouteSnapshot', 'ScalarRouteMetadata'] as $symbol) {
            $code[$symbol] = $this->digest(__DIR__ . '/' . $symbol . '.php');
        }
        $code['CanonicalJson'] = $this->digest(__DIR__ . '/../../Schema/Diff/CanonicalJson.php');
        $code['ContributesRouteMetadataInterface'] = $this->digest(__DIR__ . '/../../ServiceProvider/Capability/ContributesRouteMetadataInterface.php');
        foreach (['Discovery/PackageManifest', 'Discovery/PackageManifestCompiler', 'Kernel/Bootstrap/ManifestBootstrapper', 'Kernel/AbstractKernel', 'Kernel/RouteInputProjector'] as $bootstrap) {
            $code[$bootstrap] = $this->digest(__DIR__ . '/../../' . $bootstrap . '.php');
        }
        return ['schema' => 1, 'compiler_identity' => hash('sha256', json_encode($code, JSON_THROW_ON_ERROR)), 'records' => $records];
    }

    private function sources(\ReflectionClass $class, array &$sources): void
    {
        $name = $class->getName();
        if (isset($sources[$name])) {
            return;
        }
        $file = $class->getFileName();
        if ($file === false || !is_file($file)) {
            throw new RouteCompositionException('inventory-unavailable', 'Provider provenance is unavailable.');
        }
        $aliases = $class->getTraitAliases();
        ksort($aliases);
        $sources[$name] = ['digest' => $this->digest($file), 'aliases' => $aliases];
        if (($parent = $class->getParentClass()) !== false) {
            $this->sources($parent, $sources);
        }
        foreach ([...$class->getTraits(), ...$class->getInterfaces()] as $dependency) {
            $this->sources($dependency, $sources);
        }
    }

    private function digest(string $file): string
    {
        $digest = hash_file('sha256', $file);
        if (!is_string($digest)) {
            throw new RouteCompositionException('inventory-unavailable', 'Route provenance is unreadable.');
        }
        return $digest;
    }
}
