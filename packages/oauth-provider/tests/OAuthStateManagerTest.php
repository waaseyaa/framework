<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\SessionInterface;

final class OAuthStateManagerTest extends TestCase
{
    public function testGenerateCreatesHexState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();
        $state = $manager->generate($session);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
    }

    public function testValidateReturnsTrueForMatchingState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();
        $state = $manager->generate($session);
        self::assertTrue($manager->validate($session, $state));
    }

    public function testValidateConsumesState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();
        $state = $manager->generate($session);
        $manager->validate($session, $state);
        self::assertFalse($manager->validate($session, $state));
    }

    public function testValidateReturnsFalseForWrongState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();
        $manager->generate($session);
        self::assertFalse($manager->validate($session, 'wrong-state'));
    }

    public function testValidateReturnsFalseWhenNoStateGenerated(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();
        self::assertFalse($manager->validate($session, 'any-state'));
    }
}

/** @internal */
final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}
