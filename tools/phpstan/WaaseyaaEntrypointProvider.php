<?php declare(strict_types=1);

namespace Waaseyaa\Tools\PHPStan;

use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

use function class_exists;
use function file_get_contents;
use function glob;
use function in_array;
use function interface_exists;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function preg_match;
use function str_contains;
use function str_ends_with;

/**
 * Marks Waaseyaa-specific entrypoints as used so the dead-code detector does
 * not flag them. Covers the six discovery patterns Waaseyaa relies on that
 * are invisible to AST-level call graph analysis:
 *
 *  1. Classes carrying the access-policy attribute (resolved by
 *     AbstractKernel::discoverAccessPolicies via reflection).
 *  2. Classes carrying the middleware attribute (resolved by
 *     PackageManifestCompiler attribute scanning).
 *  3. Classes listed in any package composer.json under
 *     extra.waaseyaa.providers (instantiated by the kernel at boot).
 *  4. Classes whose FQCN sits under a `\Ingestion\EntityMapper\` namespace
 *     segment (resolved by ingestion runtime by namespace convention).
 *  5. Implementations of RouteProviderInterface (resolved by RouteBuilder
 *     when the interface exists in this checkout).
 *  6. Subclasses of EntityBase or ContentEntityBase, plus traits they `use`
 *     (populated by SqlEntityStorage via ReflectionProperty::setValue and
 *     ContentEntityBase::set() string-keyed field magic — both invisible to
 *     call-graph analysis).
 */
final class WaaseyaaEntrypointProvider extends ReflectionBasedMemberUsageProvider
{
    private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
    private const MIDDLEWARE_ATTRIBUTE = 'Waaseyaa\\Foundation\\Attribute\\AsMiddleware';
    private const ROUTE_PROVIDER_INTERFACE = 'Waaseyaa\\Routing\\RouteProviderInterface';
    private const ENTITY_MAPPER_NAMESPACE_SEGMENT = '\\Ingestion\\EntityMapper\\';
    private const ENTITY_BASE_CLASSES = [
        'Waaseyaa\\Entity\\EntityBase',
        'Waaseyaa\\Entity\\ContentEntityBase',
    ];

    /** @var array<string, true> FQCN set of declared service providers. */
    private array $declaredProviders;

    /** @var array<string, true> FQCN set of traits used by entity subclasses. */
    private array $entitySupportingTraits;

    /** @var array<string, true> Set of "FQCN::method" strings wired via ->controller('…') in route providers. */
    private array $controllerMethodRefs;

    public function __construct(string $projectRoot)
    {
        $this->declaredProviders = self::loadDeclaredProviders($projectRoot);
        $this->entitySupportingTraits = self::loadEntitySupportingTraits($projectRoot);
        $this->controllerMethodRefs = self::loadControllerMethodRefs($projectRoot);
    }

    /** @return list<ClassMemberUsage> */
    public function getUsages(Node $node, Scope $scope): array
    {
        $usages = parent::getUsages($node, $scope);
        if (!$node instanceof Node\Expr\StaticCall
            || !$node->class instanceof Node\Name
            || !$node->name instanceof Node\Identifier
            || $scope->resolveName($node->class) !== 'Waaseyaa\\Foundation\\Routing\\Metadata\\HandlerReference'
            || $node->name->toString() !== 'fromString'
            || !isset($node->args[0])
            || !$node->args[0] instanceof Node\Arg
        ) {
            return $usages;
        }

        // PHPStan supplies finite strings, including table-driven declarations.
        // Keep the caller edge: an unused declaration must not make its handler
        // a global entrypoint. Unknown strings never mark arbitrary methods.
        foreach ($scope->getType($node->args[0]->value)->getConstantStrings() as $identifier) {
            // Service IDs are opaque; a class-shaped ID need not return that class.
            if (preg_match('/^class:([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)$/D', $identifier->getValue(), $match) !== 1) {
                continue;
            }
            $usages[] = new ClassMethodUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassMethodRef(ltrim($match[1], '\\'), $match[2], possibleDescendant: false),
            );
        }
        return $usages;
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        $ref = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset($this->controllerMethodRefs[$ref])) {
            return VirtualUsageData::withNote('Waaseyaa route controller (string ref)');
        }

        return $this->isEntrypointClass($method->getDeclaringClass()->getName(), $method->getDeclaringClass())
            ? VirtualUsageData::withNote('Waaseyaa entrypoint (policy/middleware/provider/mapper/route-provider)')
            : null;
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        return $this->isEntrypointClass($constant->getDeclaringClass()->getName(), $constant->getDeclaringClass())
            ? VirtualUsageData::withNote('Waaseyaa entrypoint constant')
            : null;
    }

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        return $this->isEntrypointClass($property->getDeclaringClass()->getName(), $property->getDeclaringClass())
            ? VirtualUsageData::withNote('Waaseyaa entrypoint property')
            : null;
    }

    protected function shouldMarkPropertyAsWritten(ReflectionProperty $property): ?VirtualUsageData
    {
        return $this->shouldMarkPropertyAsRead($property);
    }

    private function isEntrypointClass(string $fqcn, \ReflectionClass $reflection): bool
    {
        if (isset($this->declaredProviders[$fqcn])) {
            return true;
        }

        if (str_contains($fqcn, self::ENTITY_MAPPER_NAMESPACE_SEGMENT)) {
            return true;
        }

        if (isset($this->entitySupportingTraits[$fqcn])) {
            return true;
        }

        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if ($name === self::POLICY_ATTRIBUTE || $name === self::MIDDLEWARE_ATTRIBUTE) {
                return true;
            }
        }

        if (interface_exists(self::ROUTE_PROVIDER_INTERFACE)
            && in_array(self::ROUTE_PROVIDER_INTERFACE, $reflection->getInterfaceNames(), true)
        ) {
            return true;
        }

        if (self::isEntitySubclass($reflection)) {
            return true;
        }

        if (self::hasApiPhpDoc($reflection)) {
            return true;
        }

        return false;
    }

    /**
     * Treat any class whose own docblock carries an `@api` tag as a fully-public
     * entrypoint — i.e. mark every method, property, and constant on it as
     * used. This duplicates shipmonk's ApiPhpDocUsageProvider intent but extends
     * coverage to declaration shapes shipmonk's built-in misses in this checkout
     * (notably trait member-level findings, where `@api` on the trait's own
     * docblock should logically propagate to its members but does not).
     */
    private static function hasApiPhpDoc(\ReflectionClass $reflection): bool
    {
        $docComment = $reflection->getDocComment();
        if ($docComment === false) {
            return false;
        }
        return str_contains($docComment, '@api');
    }

    private static function isEntitySubclass(\ReflectionClass $reflection): bool
    {
        $parent = $reflection->getParentClass();
        while ($parent !== false) {
            if (in_array($parent->getName(), self::ENTITY_BASE_CLASSES, true)) {
                return true;
            }
            $parent = $parent->getParentClass();
        }
        return false;
    }

    /**
     * Scan route-provider files for `->controller('FQCN::method')` references.
     * shipmonk's AST-level call graph can't see method names that are passed
     * as strings, so route handlers wired this way get flagged as dead.
     *
     * @return array<string, true> Set of "FQCN::method" strings.
     */
    private static function loadControllerMethodRefs(string $projectRoot): array
    {
        $refs = [];
        $packagesDir = $projectRoot . '/packages';
        if (!is_dir($packagesDir)) {
            return $refs;
        }
        $candidates = [];
        // Route registration lives in *RouteProvider.php, *RouteRegistrar.php, *Routes.php — plus
        // packages/foundation/src/Kernel/BuiltinRouteRegistrar.php and a handful of nested cases.
        foreach ([
            $packagesDir . '/*/src/*RouteProvider.php',
            $packagesDir . '/*/src/*RouteRegistrar.php',
            $packagesDir . '/*/src/*Routes.php',
            $packagesDir . '/*/src/**/*RouteProvider.php',
            $packagesDir . '/*/src/**/*RouteRegistrar.php',
            $packagesDir . '/*/src/**/*Routes.php',
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $candidates[] = $file;
            }
        }
        foreach ($candidates as $file) {
            if (!is_file($file) || !str_ends_with($file, '.php')) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match_all('/->controller\(\s*[\'\"]([\\\\A-Za-z0-9_]+)::(\w+)[\'\"]\s*\)/', $content, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $fqcn = ltrim(str_replace('\\\\', '\\', $match[1]), '\\');
                $refs[$fqcn . '::' . $match[2]] = true;
            }
        }
        return $refs;
    }

    /**
     * @return array<string, true>
     */
    private static function loadEntitySupportingTraits(string $projectRoot): array
    {
        $traits = [];
        $packagesDir = $projectRoot . '/packages';
        if (!is_dir($packagesDir)) {
            return $traits;
        }
        // Entity classes live at packages/<pkg>/src/<Name>.php or packages/<pkg>/src/Entity/<Name>.php.
        // Scan both patterns; deeper nests are rare and not worth a full recursive walk on every PHPStan run.
        $candidates = [];
        foreach (glob($packagesDir . '/*/src/*.php') ?: [] as $file) {
            $candidates[] = $file;
        }
        foreach (glob($packagesDir . '/*/src/Entity/*.php') ?: [] as $file) {
            $candidates[] = $file;
        }
        foreach ($candidates as $file) {
            if (!is_file($file) || !str_ends_with($file, '.php')) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/\bextends\s+(EntityBase|ContentEntityBase)\b/', $content) !== 1) {
                continue;
            }
            if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch) !== 1) {
                continue;
            }
            if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $content, $classMatch) !== 1) {
                continue;
            }
            $fqcn = trim($nsMatch[1]) . '\\' . $classMatch[1];
            if (!class_exists($fqcn)) {
                continue;
            }
            try {
                $reflection = new \ReflectionClass($fqcn);
            } catch (\Throwable) {
                continue;
            }
            foreach ($reflection->getTraitNames() as $traitName) {
                $traits[$traitName] = true;
            }
            $parent = $reflection->getParentClass();
            while ($parent !== false) {
                foreach ($parent->getTraitNames() as $traitName) {
                    $traits[$traitName] = true;
                }
                $parent = $parent->getParentClass();
            }
        }
        return $traits;
    }

    /**
     * @return array<string, true>
     */
    private static function loadDeclaredProviders(string $projectRoot): array
    {
        $providers = [];
        $manifests = glob($projectRoot . '/packages/*/composer.json') ?: [];
        foreach ($manifests as $manifest) {
            if (!is_file($manifest)) {
                continue;
            }
            $contents = file_get_contents($manifest);
            if ($contents === false) {
                continue;
            }
            $data = json_decode($contents, true);
            if (!is_array($data)) {
                continue;
            }
            $declared = $data['extra']['waaseyaa']['providers'] ?? null;
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $fqcn) {
                if (is_string($fqcn) && $fqcn !== '' && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $fqcn) === 1) {
                    $providers[$fqcn] = true;
                }
            }
        }
        return $providers;
    }
}
