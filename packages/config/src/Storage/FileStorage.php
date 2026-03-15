<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Storage;

use Symfony\Component\Yaml\Yaml;
use Waaseyaa\Config\StorageInterface;

final class FileStorage implements StorageInterface
{
    private readonly string $directory;

    /**
     * @var array<string, self>
     */
    private array $collections = [];

    public function __construct(
        string $directory,
        private readonly string $collection = '',
    ) {
        if ($collection !== '') {
            $this->directory = $directory . DIRECTORY_SEPARATOR . $collection;
        } else {
            $this->directory = $directory;
        }
    }

    public function exists(string $name): bool
    {
        return file_exists($this->getFilePath($name));
    }

    public function read(string $name): array|false
    {
        $filePath = $this->getFilePath($name);

        if (!file_exists($filePath)) {
            return false;
        }

        $contents = file_get_contents($filePath);
        if ($contents === false || $contents === '') {
            return [];
        }

        $data = Yaml::parse($contents);

        return is_array($data) ? $data : [];
    }

    public function readMultiple(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $data = $this->read($name);
            if ($data !== false) {
                $result[$name] = $data;
            }
        }

        return $result;
    }

    public function write(string $name, array $data): bool
    {
        $this->ensureDirectory();

        $yaml = Yaml::dump($data, 8, 2);

        return file_put_contents($this->getFilePath($name), $yaml) !== false;
    }

    public function delete(string $name): bool
    {
        $filePath = $this->getFilePath($name);

        if (!file_exists($filePath)) {
            return false;
        }

        return unlink($filePath);
    }

    public function rename(string $name, string $newName): bool
    {
        $oldPath = $this->getFilePath($name);
        $newPath = $this->getFilePath($newName);

        if (!file_exists($oldPath)) {
            return false;
        }

        return rename($oldPath, $newPath);
    }

    public function listAll(string $prefix = ''): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $names = [];
        $iterator = new \DirectoryIterator($this->directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) {
                continue;
            }

            $extension = $fileInfo->getExtension();
            if ($extension !== 'yml') {
                continue;
            }

            $name = $fileInfo->getBasename('.yml');

            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public function deleteAll(string $prefix = ''): bool
    {
        $names = $this->listAll($prefix);
        $success = true;

        foreach ($names as $name) {
            if (!$this->delete($name)) {
                $success = false;
            }
        }

        return $success;
    }

    public function createCollection(string $collection): static
    {
        if (!isset($this->collections[$collection])) {
            $baseDir = $this->collection !== ''
                ? dirname($this->directory)
                : $this->directory;

            $this->collections[$collection] = new self($baseDir, $collection);
        }

        return $this->collections[$collection];
    }

    public function getCollectionName(): string
    {
        return $this->collection;
    }

    public function getAllCollectionNames(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $collections = [];
        $iterator = new \DirectoryIterator($this->directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isDir()) {
                continue;
            }

            $collections[] = $fileInfo->getFilename();
        }

        sort($collections);

        return $collections;
    }

    private function getFilePath(string $name): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $name . '.yml';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }
}
