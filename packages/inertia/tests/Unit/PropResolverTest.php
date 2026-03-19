<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Inertia\PropResolver;

#[CoversClass(PropResolver::class)]
final class PropResolverTest extends TestCase
{
    public function testResolvesClosureProps(): void
    {
        $resolver = new PropResolver();
        $props = [
            'static' => 'value',
            'lazy' => fn () => 'computed',
        ];

        $resolved = $resolver->resolve($props);

        $this->assertSame('value', $resolved['static']);
        $this->assertSame('computed', $resolved['lazy']);
    }

    public function testOptionalPropsExcludedByDefault(): void
    {
        $resolver = new PropResolver();
        $props = [
            'always' => 'here',
            'optional' => PropResolver::optional(fn () => 'expensive'),
        ];

        $resolved = $resolver->resolve($props);

        $this->assertSame('here', $resolved['always']);
        $this->assertArrayNotHasKey('optional', $resolved);
    }

    public function testOptionalPropsIncludedWhenRequested(): void
    {
        $resolver = new PropResolver();
        $props = [
            'always' => 'here',
            'optional' => PropResolver::optional(fn () => 'expensive'),
        ];

        $resolved = $resolver->resolve($props, only: ['always', 'optional']);

        $this->assertSame('here', $resolved['always']);
        $this->assertSame('expensive', $resolved['optional']);
    }

    public function testPartialReloadOnlyIncludesRequestedProps(): void
    {
        $resolver = new PropResolver();
        $props = [
            'users' => [1, 2, 3],
            'posts' => [4, 5, 6],
            'comments' => [7, 8, 9],
        ];

        $resolved = $resolver->resolve($props, only: ['users', 'posts']);

        $this->assertSame([1, 2, 3], $resolved['users']);
        $this->assertSame([4, 5, 6], $resolved['posts']);
        $this->assertArrayNotHasKey('comments', $resolved);
    }

    public function testPartialReloadExceptExcludesProps(): void
    {
        $resolver = new PropResolver();
        $props = [
            'users' => [1, 2, 3],
            'posts' => [4, 5, 6],
            'comments' => [7, 8, 9],
        ];

        $resolved = $resolver->resolve($props, except: ['comments']);

        $this->assertSame([1, 2, 3], $resolved['users']);
        $this->assertSame([4, 5, 6], $resolved['posts']);
        $this->assertArrayNotHasKey('comments', $resolved);
    }

    public function testExceptTakesPrecedenceOverOnly(): void
    {
        $resolver = new PropResolver();
        $props = [
            'users' => [1, 2, 3],
            'posts' => [4, 5, 6],
        ];

        $resolved = $resolver->resolve($props, only: ['users', 'posts'], except: ['posts']);

        $this->assertSame([1, 2, 3], $resolved['users']);
        $this->assertArrayNotHasKey('posts', $resolved);
    }
}
