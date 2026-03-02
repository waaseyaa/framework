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
     */
    public function compileAndCache(): array
    {
        $data = $this->compile();

        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';' . "\n";
        file_put_contents($this->cachePath, $content);

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
