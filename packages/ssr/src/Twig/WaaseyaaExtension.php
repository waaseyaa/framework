<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Waaseyaa\Config\ConfigFactoryInterface;

final class WaaseyaaExtension extends AbstractExtension
{
    /** @var string[] */
    private readonly array $envWhitelist;

    /** @var \Closure(string): (string|false) */
    private readonly \Closure $envResolver;

    /**
     * @param string[] $envWhitelist
     * @param ?\Closure(string): (string|false) $envResolver
     */
    public function __construct(
        private readonly string $assetBasePath = '',
        private readonly ?ConfigFactoryInterface $configFactory = null,
        array $envWhitelist = [],
        ?\Closure $envResolver = null,
    ) {
        $this->envWhitelist = $envWhitelist;
        $this->envResolver = $envResolver ?? static fn(string $name): string|false => getenv($name);
    }

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('config', $this->config(...)),
            new TwigFunction('env', $this->env(...)),
        ];
    }

    /**
     * Resolve a public asset URL.
     */
    public function asset(string $path): string
    {
        $base = rtrim($this->assetBasePath, '/');
        $path = ltrim($path, '/');

        return $base . '/' . $path;
    }

    /**
     * Read a config value (read-only). Key format: "config_name.key.path".
     */
    public function config(string $dotKey): string
    {
        if ($this->configFactory === null) {
            return '';
        }

        $dotPos = strpos($dotKey, '.');
        if ($dotPos === false) {
            return '';
        }

        $configName = substr($dotKey, 0, $dotPos);
        $key = substr($dotKey, $dotPos + 1);

        $value = $this->configFactory->get($configName)->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Expose a whitelisted environment variable.
     */
    public function env(string $name, string $default = ''): string
    {
        if (!in_array($name, $this->envWhitelist, true)) {
            return '';
        }

        $value = ($this->envResolver)($name);

        return $value !== false ? $value : $default;
    }
}
