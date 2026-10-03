<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** A deeply immutable route declaration, without execution state. @api */
final readonly class RouteDefinition
{
    /** @var list<string> */
    public array $methods;
    /** @var list<string> */
    public array $schemes;
    /** @var array<string, string> */
    public array $requirements;
    /** @var array<array-key, mixed> */
    public array $defaults;
    /** @var array<array-key, mixed> */
    public array $options;

    public function __construct(
        public string $name,
        public string $path,
        public HandlerReference $handler,
        array $methods = [],
        public string $host = '',
        array $schemes = [],
        public string $condition = '',
        array $requirements = [],
        array $defaults = [],
        array $options = [],
        public int $priority = 0,
        public string $sourceId = '',
        public int $ordinal = 0,
    ) {
        ScalarRouteMetadata::identifier($name);
        ScalarRouteMetadata::identifier($sourceId);
        ScalarRouteMetadata::copy([$path, $host, $condition]);
        if (!str_starts_with($path, '/') || $ordinal < 0) {
            throw new \InvalidArgumentException('Invalid route path or declaration ordinal.');
        }
        if (!array_is_list($methods) || !array_is_list($schemes)) {
            throw new \InvalidArgumentException('Route methods and schemes must be lists.');
        }
        $normalized = [];
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^[a-zA-Z]+$/D', $method) !== 1) {
                throw new \InvalidArgumentException('Invalid route HTTP method.');
            }
            $normalized[] = strtoupper($method);
        }
        foreach ($schemes as $scheme) {
            if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Unsupported route scheme.');
            }
        }
        foreach ($requirements as $parameter => $expression) {
            if (!is_string($parameter) || !is_string($expression)) {
                throw new \InvalidArgumentException('Route requirements must map names to strings.');
            }
        }
        if (array_key_exists('_controller', $defaults) || array_key_exists('_waaseyaa_priority', $options)) {
            throw new \InvalidArgumentException('Handler identity and priority have dedicated route fields.');
        }
        $this->methods = array_values(array_unique($normalized));
        // Schemes have already been validated against their closed scalar list.
        // A two-scheme list is not an untyped callable-array declaration.
        $this->schemes = array_map(static fn(string $scheme): string => $scheme, $schemes);
        $this->requirements = ScalarRouteMetadata::copy($requirements);
        $this->defaults = ScalarRouteMetadata::copy($defaults);
        $this->options = ScalarRouteMetadata::copy($options);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'path' => $this->path, 'handler' => $this->handler->id,
            'methods' => $this->methods, 'host' => $this->host, 'schemes' => $this->schemes,
            'condition' => $this->condition, 'requirements' => $this->requirements,
            'defaults' => $this->defaults, 'options' => $this->options,
            'priority' => $this->priority, 'source_id' => $this->sourceId, 'ordinal' => $this->ordinal,
        ];
    }
}
