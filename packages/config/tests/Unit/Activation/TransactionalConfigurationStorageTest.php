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

final class TransactionalConfigurationStorageTest extends TestCase
{
    #[Test]
    public function retainedAdapterDelegatesReadsWithoutConsultingActivation(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $reader = new MemoryStorage();
        $reader->write('system.site', ['name' => 'Old']);
        $activator = new CapturingActivator($token);
        $storage = new TransactionalConfigurationStorage($reader, $activator, $token);

        self::assertTrue($storage->exists('system.site'));
        self::assertSame(['name' => 'Old'], $storage->read('system.site'));
        self::assertSame(['system.site'], $storage->listAll());
        self::assertNull($activator->request);
    }

    #[Test]
    public function everyLegacyMutationRefusesBeforeActivation(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $reader = new MemoryStorage();
        $reader->write('system.site', ['name' => 'Old']);
        $activator = new CapturingActivator($token);
        $storage = new TransactionalConfigurationStorage($reader, $activator, $token);

        $mutations = [
            static fn(): bool => $storage->write('system.site', ['name' => 'New']),
            static fn(): bool => $storage->delete('system.site'),
            static fn(): bool => $storage->rename('system.site', 'system.renamed'),
            static fn(): bool => $storage->deleteAll(),
            static fn(): bool => $storage->createCollection('language')->write('system.site', []),
        ];
        foreach ($mutations as $mutation) {
            try {
                $mutation();
                self::fail('Legacy mutation reached activation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('cannot authenticate a complete CFG-03 bundle', $exception->getMessage());
            }
        }
        self::assertNull($activator->request);
        self::assertSame(['name' => 'Old'], $reader->read('system.site'));
    }
}

final class CapturingActivator implements ConfigurationActivatorInterface
{
    public ?ConfigurationActivationRequest $request = null;
    public function __construct(private ConfigurationActiveToken $token) {}

    public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
    {
        $this->request = $request;
        throw new \LogicException('legacy mutation reached activation');
    }
    public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
    {
        throw new \LogicException('not used');
    }
    public function committedResult(string $requestId): ?ConfigurationActivationResult
    {
        return null;
    }
    public function currentToken(): ?ConfigurationActiveToken
    {
        return $this->token;
    }
    public function readGeneration(ConfigurationActiveToken $token): iterable
    {
        return [];
    }
}
