<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Cache;

use Waaseyaa\Config\StorageInterface;

final class ConfigCacheCompiler
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $cachePath,
    ) {}

    /**
     * Compile all config into a single cached PHP file.
     *
     * @return array<string, array<string, mixed>> The compiled config data
     */
    public function compile(): array
    {
        $all = [];

        foreach ($this->storage->listAll() as $name) {
            $data = $this->storage->read($name);
            if ($data !== false) {
                $all[$name] = $data;
            }
        }

        return $all;
    }

    /**
     * Compile and write cache file.
     *
     * @throws \RuntimeException If the cache directory or file cannot be written
     */
    public function compileAndCache(): array
    {
        $data = $this->compile();

        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new \RuntimeException(sprintf('Failed to create cache directory: %s', $dir));
        }

        $content = '<?php return ' . var_export($data, true) . ';' . "\n";
        $tmpPath = $this->cachePath . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write config cache to %s', $this->cachePath));
        }

        if (!rename($tmpPath, $this->cachePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('Failed to atomically replace config cache at %s', $this->cachePath));
        }

        return $data;
    }

    /**
     * Check if cache file exists.
     */
    public function isCached(): bool
    {
        return is_file($this->cachePath);
    }

    /**
     * Delete the cache file.
     */
    public function clear(): bool
    {
        if (is_file($this->cachePath)) {
            return unlink($this->cachePath);
        }

        return false;
    }
}
