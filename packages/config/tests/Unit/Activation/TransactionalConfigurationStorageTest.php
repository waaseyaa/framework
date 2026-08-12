<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Activation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Activation\TransactionalConfigurationStorage;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\Sync\ConfigSyncFile;

final class TransactionalConfigurationStorageTest extends TestCase
{
    #[Test]
    public function updateCarriesTheObservedTokenAndEntryHashInOneActivation(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $file = $this->file('system', 'site', ['name' => 'Old']);
        $reader = new MemoryStorage();
        $reader->write($file->ref(), $file->fields);
        $activator = new CapturingActivator($token, [$file]);
        $storage = new TransactionalConfigurationStorage($reader, $activator, $token);

        self::assertTrue($storage->write('system.site', ['name' => 'New']));

        self::assertNotNull($activator->request);
        self::assertEquals($token, $activator->request->expectedToken);
        self::assertSame(['system.site' => $file->contentHash()], $activator->request->expectedEntryHashes());
        self::assertSame(['name' => 'New'], $activator->request->files()[0]->fields);
    }

    #[Test]
    public function deleteUsesAContentBoundTombstoneAndMissingDeleteReturnsFalse(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $file = $this->file('system', 'site', ['name' => 'Old']);
        $reader = new MemoryStorage();
        $reader->write($file->ref(), $file->fields);
        $activator = new CapturingActivator($token, [$file]);
        $storage = new TransactionalConfigurationStorage($reader, $activator, $token);

        self::assertTrue($storage->delete('system.site'));
        self::assertSame(['system.site' => $file->contentHash()], $activator->request?->tombstones());
        self::assertFalse($storage->delete('role.missing'));
    }

    #[Test]
    public function collectionAndMalformedRefsRefuseBeforeActivation(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $activator = new CapturingActivator($token, []);
        $storage = new TransactionalConfigurationStorage(new MemoryStorage(), $activator, $token);

        foreach (['not-a-ref', 'collection'] as $case) {
            try {
                $target = $case === 'collection' ? $storage->createCollection('language') : $storage;
                $target->write($case === 'collection' ? 'system.site' : $case, []);
                self::fail('Unsupported config identity reached activation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('canonical root config ref', $exception->getMessage());
            }
        }
        self::assertNull($activator->request);
    }

    #[Test]
    public function sequentialMutationsAdvanceTheObservedToken(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $site = $this->file('system', 'site', ['name' => 'Old']);
        $role = $this->file('role', 'editor', ['label' => 'Editor']);
        $activator = new CapturingActivator($token, [$site, $role]);
        $storage = new TransactionalConfigurationStorage(new MemoryStorage(), $activator, $token);

        self::assertTrue($storage->write('system.site', ['name' => 'New']));
        self::assertTrue($storage->rename('role.editor', 'role.author'));
        self::assertTrue($storage->deleteAll('role.'));

        self::assertCount(3, $activator->requests);
        self::assertSame([4, 5, 6], array_map(
            static fn(ConfigurationActivationRequest $request): int => $request->expectedToken?->activationSequence ?? -1,
            $activator->requests,
        ));
        self::assertTrue($activator->requests[2]->completeReplacement);
        self::assertArrayHasKey('role.author', $activator->requests[2]->tombstones());
    }

    /** @param array<string, mixed> $fields */
    private function file(string $type, string $id, array $fields): ConfigSyncFile
    {
        return new ConfigSyncFile(
            $type,
            $id,
            ConfigSyncFile::deterministicUuid($type, $id),
            [],
            'en',
            $fields,
        );
    }
}

final class CapturingActivator implements ConfigurationActivatorInterface
{
    public ?ConfigurationActivationRequest $request = null;
    /** @var list<ConfigurationActivationRequest> */
    public array $requests = [];

    /** @param list<ConfigSyncFile> $files */
    public function __construct(
        private ConfigurationActiveToken $token,
        private array $files,
    ) {}

    public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
    {
        $this->request = $request;
        $this->requests[] = $request;
        $byRef = [];
        foreach ($this->files as $file) {
            $byRef[$file->ref()] = $file;
        }
        if ($request->completeReplacement) {
            $byRef = [];
        }
        foreach ($request->files() as $file) {
            $byRef[$file->ref()] = $file;
        }
        foreach ($request->tombstones() as $ref => $_hash) {
            unset($byRef[$ref]);
        }
        $this->files = array_values($byRef);
        $this->token = new ConfigurationActiveToken(
            hash('sha256', $request->inputHash()),
            $this->token->activationSequence + 1,
        );

        return new ConfigurationActivationResult('committed', $this->token, $request->requestId, str_repeat('f', 64));
    }
    public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult { throw new \LogicException('not used'); }
    public function committedResult(string $requestId): ?ConfigurationActivationResult { return null; }
    public function currentToken(): ?ConfigurationActiveToken { return $this->token; }
    public function readGeneration(ConfigurationActiveToken $token): iterable { yield from $this->files; }
}
