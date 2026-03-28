<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\SessionInterface;

#[CoversClass(OAuthStateManager::class)]
final class OAuthStateManagerTest extends TestCase
{
    public function testGenerateReturns64CharHexString(): void
    {
        $manager = new OAuthStateManager();
        $session = new InMemorySession();

        $state = $manager->generate($session);

        self::assertSame(64, strlen($state));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
    }

    public function testValidateReturnsTrueForValidState(): void
    {
        $manager = new OAuthStateManager();
        $session = new InMemorySession();

        $state = $manager->generate($session);

        self::assertTrue($manager->validate($session, $state));
    }

    public function testValidateConsumesState(): void
    {
        $manager = new OAuthStateManager();
        $session = new InMemorySession();

        $state = $manager->generate($session);
        $manager->validate($session, $state);

        self::assertFalse($manager->validate($session, $state));
    }

    public function testValidateReturnsFalseForWrongState(): void
    {
        $manager = new OAuthStateManager();
        $session = new InMemorySession();

        $manager->generate($session);

        self::assertFalse($manager->validate($session, 'wrong_state'));
    }

    public function testValidateReturnsFalseWhenNoStateInSession(): void
    {
        $manager = new OAuthStateManager();
        $session = new InMemorySession();

        self::assertFalse($manager->validate($session, 'any_state'));
    }

    public function testValidateReturnsFalseWhenExpired(): void
    {
        $manager = new OAuthStateManager(ttlSeconds: 0);
        $session = new InMemorySession();

        $state = $manager->generate($session);
        sleep(1);

        self::assertFalse($manager->validate($session, $state));
    }
}

final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
