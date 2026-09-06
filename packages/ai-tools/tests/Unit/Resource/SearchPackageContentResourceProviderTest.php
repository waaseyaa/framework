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
use Waaseyaa\Search\SearchCataloguePage;
use Waaseyaa\Search\SearchCatalogueScanPosition;
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

            public function list(
                AuthorizationPrincipalInterface $principal,
                ?SearchCatalogueScanPosition $after = null,
            ): SearchCataloguePage {
                $this->seen[] = $principal;

                return new SearchCataloguePage([
                    new SearchCandidateProjection('node:9', 'node', 'Water', 'Safe public body', '/about/water', 'site'),
                ]);
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

    #[Test]
    public function provider_resume_tokens_round_trip_only_as_validated_scan_positions(): void
    {
        $seen = [];
        $catalogue = new class($seen) implements SearchContentCatalogueInterface {
            /** @param list<?SearchCatalogueScanPosition> $seen */
            public function __construct(private array &$seen) {}

            public function list(
                AuthorizationPrincipalInterface $principal,
                ?SearchCatalogueScanPosition $after = null,
            ): SearchCataloguePage {
                $this->seen[] = $after;

                return $after === null
                    ? new SearchCataloguePage([
                        new SearchCandidateProjection('node:1', 'node', 'First', 'Body', '/first', 'site'),
                    ], new SearchCatalogueScanPosition('2026-09-05T12:00:00Z', 'node:1'))
                    : new SearchCataloguePage([]);
            }

            public function readByPublicPath(string $publicPath, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return null;
            }
        };
        $provider = new SearchPackageContentResourceProvider(static fn(): object => $catalogue);

        $first = $provider->list($this->principal());
        self::assertNotNull($first->nextToken);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $first->nextToken);

        $second = $provider->list($this->principal(), $first->nextToken);
        self::assertSame([], $second->resources);
        self::assertNull($second->nextToken);
        self::assertNull($seen[0]);
        self::assertSame('2026-09-05T12:00:00Z', $seen[1]?->createdAt);
        self::assertSame('node:1', $seen[1]?->documentId);

        foreach ([
            'not.a.token',
            $this->resumeWire('srv0:2026-09-05T12:00:00Z' . "\0" . 'node:1'),
            $this->resumeWire('srv1:missing-separator'),
            $this->resumeWire('srv1:' . "\0" . 'node:1'),
        ] as $invalid) {
            try {
                $provider->list($this->principal(), $invalid);
                self::fail('Expected malformed provider resume refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function list_omits_unsafe_paths_and_uses_the_path_when_a_safe_title_is_empty(): void
    {
        $catalogue = $this->createStub(SearchContentCatalogueInterface::class);
        $catalogue->method('list')->willReturn(new SearchCataloguePage([
            new SearchCandidateProjection('node:1', 'node', 'Unsafe', 'Body', '//private', 'site'),
            new SearchCandidateProjection('node:2', 'node', '', 'Body', '/fallback', 'site'),
        ]));
        $provider = new SearchPackageContentResourceProvider(static fn(): object => $catalogue);

        $page = $provider->list($this->principal());

        self::assertCount(1, $page->resources);
        self::assertSame('/fallback', $page->resources[0]->title);
    }

    #[Test]
    public function read_rejects_non_content_uris_and_mismatched_canonical_projections(): void
    {
        $catalogue = $this->createStub(SearchContentCatalogueInterface::class);
        $catalogue->method('readByPublicPath')->willReturn(
            new SearchCandidateProjection('node:1', 'node', 'Other', 'Body', '/other', 'site'),
        );
        $provider = new SearchPackageContentResourceProvider(static fn(): object => $catalogue);

        self::assertNull($provider->read('waaseyaa://other/value', $this->principal()));
        self::assertNull($provider->read(
            SearchPackageContentResourceProvider::uriForPath('/expected'),
            $this->principal(),
        ));
    }

    #[Test]
    public function an_unavailable_catalogue_binding_fails_closed(): void
    {
        $provider = new SearchPackageContentResourceProvider(static fn(): object => new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding is unavailable');
        $provider->list($this->principal());
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

    private function resumeWire(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
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
