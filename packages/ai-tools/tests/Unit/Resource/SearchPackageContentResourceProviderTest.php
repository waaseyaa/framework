<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\Resource\ContentResourceRegistry;
use Waaseyaa\AI\Tools\Resource\MalformedContentResourceUriException;
use Waaseyaa\AI\Tools\Resource\SearchPackageContentResourceProvider;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchContentCatalogueInterface;

#[CoversClass(SearchPackageContentResourceProvider::class)]
#[CoversClass(ContentResourceRegistry::class)]
final class SearchPackageContentResourceProviderTest extends TestCase
{
    #[Test]
    public function listed_uri_round_trips_to_the_same_principal_safe_content(): void
    {
        $seen = [];
        $catalogue = new class($seen) implements SearchContentCatalogueInterface {
            /** @param list<AuthorizationPrincipalInterface> $seen */
            public function __construct(private array &$seen) {}

            public function list(AuthorizationPrincipalInterface $principal): array
            {
                $this->seen[] = $principal;

                return [new SearchCandidateProjection('node:9', 'node', 'Water', 'Safe public body', '/about/water', 'site')];
            }

            public function readByPublicPath(string $publicPath, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                $this->seen[] = $principal;

                return $publicPath === '/about/water'
                    ? new SearchCandidateProjection('node:9', 'node', 'Water', 'Safe public body', '/about/water', 'site')
                    : null;
            }
        };
        $principal = $this->principal();
        $provider = new SearchPackageContentResourceProvider(static fn(): object => $catalogue);
        $registry = new ContentResourceRegistry();
        $registry->register('search', $provider);

        $resources = $registry->list($principal);
        $content = $registry->read($resources[0]->uri, $principal);

        self::assertCount(1, $resources);
        self::assertSame('Water', $resources[0]->title);
        self::assertSame('Safe public body', $content?->text);
        self::assertSame($resources[0]->uri, $content?->uri);
        self::assertCount(2, $seen);
        self::assertSame($principal, $seen[0]);
        self::assertSame($principal, $seen[1]);
        self::assertStringNotContainsString('node:9', json_encode($resources[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function canonical_uri_validation_rejects_aliases_and_unsafe_paths(): void
    {
        $uri = SearchPackageContentResourceProvider::uriForPath('/about/water');
        self::assertSame('/about/water', SearchPackageContentResourceProvider::pathFromUri($uri));

        foreach ([
            $uri . '=',
            'waaseyaa://content/',
            'waaseyaa://content/!!!!',
        ] as $invalid) {
            try {
                SearchPackageContentResourceProvider::pathFromUri($invalid);
                self::fail('Expected malformed URI refusal.');
            } catch (MalformedContentResourceUriException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['//secret', '/a//b', '/a/../b', '/a%2Fb', "/a\nb", '/a?b', '/a#b', '/a\\b'] as $path) {
            $this->expectPathRefusal($path);
        }
    }

    #[Test]
    public function denied_and_unknown_catalogue_reads_both_return_null(): void
    {
        $catalogue = $this->createStub(SearchContentCatalogueInterface::class);
        $catalogue->method('readByPublicPath')->willReturn(null);
        $provider = new SearchPackageContentResourceProvider(static fn(): object => $catalogue);

        self::assertNull($provider->read(SearchPackageContentResourceProvider::uriForPath('/private'), $this->principal()));
        self::assertNull($provider->read(SearchPackageContentResourceProvider::uriForPath('/missing'), $this->principal()));
    }

    private function expectPathRefusal(string $path): void
    {
        try {
            SearchPackageContentResourceProvider::uriForPath($path);
            self::fail('Expected unsafe path refusal.');
        } catch (MalformedContentResourceUriException) {
            self::addToAssertionCount(1);
        }
    }

    private function principal(): AuthorizationPrincipalInterface
    {
        return new class implements AuthorizationPrincipalInterface {
            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return $permission === 'resource.content.read'; }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return false; }
            public function claimsGeneration(): string { return 'resource-test-v1'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }
}
