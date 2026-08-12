<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Config\StorageInterface;

/** Legacy StorageInterface view of the resolver-selected sync artifact. */
final class SyncArtifactStorageAdapter implements StorageInterface
{
    private FileStorage $storage;

    /** @var array{dev: int, ino: int}|null */
    private ?array $openedSyncDirectoryIdentity = null;

    public function __construct(private readonly ConfigurationAuthorityContext $context)
    {
        $this->storage = new FileStorage($context->syncPath);
    }

    public function exists(string $name): bool
    {
        return $this->guard(fn(): bool => $this->storage->exists($name), [$name]);
    }
    public function read(string $name): array|false
    {
        return $this->guard(fn(): array|false => $this->storage->read($name), [$name]);
    }
    public function readMultiple(array $names): array
    {
        return $this->guard(fn(): array => $this->storage->readMultiple($names), $names);
    }
    public function write(string $name, array $data): bool
    {
        return $this->guard(fn(): bool => $this->storage->write($name, $data), [$name]);
    }
    public function delete(string $name): bool
    {
        return $this->guard(fn(): bool => $this->storage->delete($name), [$name]);
    }
    public function rename(string $name, string $newName): bool
    {
        return $this->guard(fn(): bool => $this->storage->rename($name, $newName), [$name, $newName]);
    }
    public function listAll(string $prefix = ''): array
    {
        return $this->guard(fn(): array => $this->storage->listAll($prefix));
    }
    public function deleteAll(string $prefix = ''): bool
    {
        return $this->guard(fn(): bool => $this->storage->deleteAll($prefix));
    }
    public function createCollection(string $collection): static
    {
        throw new \LogicException('Legacy sync collections are not part of configuration.authority.v1.');
    }
    public function getCollectionName(): string
    {
        return '';
    }
    public function getAllCollectionNames(): array
    {
        return [];
    }

    /** @param list<string> $names */
    private function guard(callable $operation, array $names = []): mixed
    {
        $this->assertPathIdentity();
        if ($names === []) {
            $this->assertAllMembers();
        } else {
            foreach ($names as $name) {
                $this->assertMember($name);
            }
        }
        $result = $operation();
        $this->assertPathIdentity();
        if ($names === []) {
            $this->assertAllMembers();
        } else {
            foreach ($names as $name) {
                $this->assertMember($name);
            }
        }

        return $result;
    }

    private function assertPathIdentity(): void
    {
        $this->context->assertSyncPathIdentity();
        if (!file_exists($this->context->syncPath) && !is_link($this->context->syncPath)) {
            return;
        }
        $real = realpath($this->context->syncPath);
        $stat = $real === false ? false : @stat($real);
        if (is_link($this->context->syncPath) || $real === false || $stat === false || !is_dir($real)) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path is not an inspectable non-link directory.');
        }
        $identity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
        if ($this->openedSyncDirectoryIdentity === null) {
            $this->openedSyncDirectoryIdentity = $identity;
        } elseif ($this->openedSyncDirectoryIdentity !== $identity) {
            throw new ConfigurationAuthorityConflictException(
                'Configuration sync directory identity changed after its first authorized use.',
            );
        }
        $this->context->assertSyncPathIdentity();
    }

    private function assertMember(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new ConfigurationAuthorityConflictException('Configuration sync member name is not a flat canonical identifier.');
        }
        $path = rtrim($this->context->syncPath, '/') . '/' . $name . '.yml';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ConfigurationAuthorityConflictException(sprintf(
                'Configuration sync member "%s" is not a regular non-link file.',
                $name,
            ));
        }
    }

    private function assertAllMembers(): void
    {
        if (!is_dir($this->context->syncPath)) {
            return;
        }
        foreach (new \DirectoryIterator($this->context->syncPath) as $member) {
            if ($member->isDot() || $member->getExtension() !== 'yml') {
                continue;
            }
            if ($member->isLink() || !$member->isFile()) {
                throw new ConfigurationAuthorityConflictException(sprintf(
                    'Configuration sync member "%s" is not a regular non-link file.',
                    $member->getFilename(),
                ));
            }
        }
    }
}
