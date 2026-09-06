<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\Resource\ContentResourceContent;
use Waaseyaa\AI\Tools\Resource\ContentResourceDescriptor;
use Waaseyaa\AI\Tools\Resource\ContentResourceListPage;
use Waaseyaa\AI\Tools\Resource\ContentResourceListResume;
use Waaseyaa\AI\Tools\Resource\ContentResourceProviderInterface;
use Waaseyaa\AI\Tools\Resource\ContentResourceRegistry;
use Waaseyaa\AI\Tools\Resource\ContentResourceRegistryListPage;

#[CoversClass(ContentResourceListPage::class)]
#[CoversClass(ContentResourceListResume::class)]
#[CoversClass(ContentResourceRegistry::class)]
#[CoversClass(ContentResourceRegistryListPage::class)]
final class ContentResourcePaginationValueTest extends TestCase
{
    #[Test]
    public function legacy_list_composes_resources_from_every_registered_provider(): void
    {
        $registry = new ContentResourceRegistry();
        $registry->register('alpha', $this->provider([
            new ContentResourceDescriptor('waaseyaa://alpha/1', 'alpha:1', 'Alpha'),
            new ContentResourceDescriptor('waaseyaa://alpha/1', 'alpha:duplicate', 'Duplicate'),
        ]));
        $registry->register('beta', $this->provider([
            new ContentResourceDescriptor('waaseyaa://beta/1', 'beta:1', 'Beta'),
        ]));

        self::assertSame(
            ['waaseyaa://alpha/1', 'waaseyaa://beta/1'],
            array_map(static fn(ContentResourceDescriptor $resource): string => $resource->uri, $registry->list($this->principal())),
        );
    }

    #[Test]
    public function legacy_list_keeps_its_global_fifty_resource_bound(): void
    {
        $resources = [];
        for ($index = 0; $index < 51; ++$index) {
            $resources[] = new ContentResourceDescriptor(
                'waaseyaa://alpha/' . $index,
                'alpha:' . $index,
                'Alpha ' . $index,
            );
        }
        $registry = new ContentResourceRegistry();
        $registry->register('alpha', $this->provider($resources));
        $registry->register('beta', $this->provider([
            new ContentResourceDescriptor('waaseyaa://beta/1', 'beta:1', 'Beta'),
        ]));

        $listed = $registry->list($this->principal());

        self::assertCount(50, $listed);
        self::assertSame('waaseyaa://alpha/49', $listed[49]->uri);
        self::assertNotContains('waaseyaa://beta/1', array_map(
            static fn(ContentResourceDescriptor $resource): string => $resource->uri,
            $listed,
        ));
    }

    #[Test]
    public function registry_pages_skip_empty_providers_and_resume_only_the_owning_provider(): void
    {
        $seenTokens = [];
        $registry = new ContentResourceRegistry();
        $registry->register('empty', $this->provider([]));
        $registry->register('search', new class($seenTokens) implements ContentResourceProviderInterface {
            /** @param list<?string> $seenTokens */
            public function __construct(private array &$seenTokens) {}

            public function list(AuthorizationPrincipalInterface $principal, ?string $resumeToken = null): ContentResourceListPage
            {
                $this->seenTokens[] = $resumeToken;

                return $resumeToken === null
                    ? new ContentResourceListPage([
                        new ContentResourceDescriptor('waaseyaa://search/1', 'search:1', 'First'),
                    ], 'page_2')
                    : new ContentResourceListPage([
                        new ContentResourceDescriptor('waaseyaa://search/2', 'search:2', 'Second'),
                    ]);
            }

            public function templates(): array { return []; }
            public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent { return null; }
        });

        $first = $registry->listPage($this->principal());
        self::assertSame('waaseyaa://search/1', $first->resources[0]->uri);
        self::assertSame('search', $first->next?->provider);
        self::assertSame('page_2', $first->next?->token);

        $second = $registry->listPage($this->principal(), $first->next);
        self::assertSame('waaseyaa://search/2', $second->resources[0]->uri);
        self::assertNull($second->next);
        self::assertSame([null, 'page_2'], $seenTokens);

        $this->assertInvalidArgument(
            fn() => $registry->listPage(
                $this->principal(),
                new ContentResourceListResume('missing', 'page_2'),
            ),
        );
    }

    #[Test]
    public function registry_pages_deduplicate_and_cap_untrusted_provider_results(): void
    {
        $resources = [
            new ContentResourceDescriptor('waaseyaa://search/0', 'search:0', 'Result 0'),
            new ContentResourceDescriptor('waaseyaa://search/0', 'search:duplicate', 'Duplicate'),
        ];
        for ($index = 1; $index < 51; ++$index) {
            $resources[] = new ContentResourceDescriptor(
                'waaseyaa://search/' . $index,
                'search:' . $index,
                'Result ' . $index,
            );
        }
        $registry = new ContentResourceRegistry();
        $registry->register('search', $this->provider($resources, 'next_page'));

        $page = $registry->listPage($this->principal());

        self::assertCount(50, $page->resources);
        self::assertSame('next_page', $page->next?->token);
        self::assertCount(50, array_unique(array_map(
            static fn(ContentResourceDescriptor $resource): string => $resource->uri,
            $page->resources,
        )));
    }

    #[Test]
    public function pagination_values_reject_malformed_collections_and_resume_tokens(): void
    {
        $descriptor = new ContentResourceDescriptor('waaseyaa://search/1', 'search:1', 'First');
        $resume = new ContentResourceListResume('search_provider', 'opaque_-1');
        self::assertSame([$descriptor], new ContentResourceListPage([$descriptor], $resume->token)->resources);
        self::assertSame([$descriptor], new ContentResourceRegistryListPage([$descriptor], $resume)->resources);

        foreach ([
            static fn() => new ContentResourceListPage(['resource' => $descriptor]),
            static fn() => new ContentResourceListPage([new \stdClass()]),
            static fn() => new ContentResourceListPage([], ''),
            static fn() => new ContentResourceListPage([], 'not opaque'),
            static fn() => new ContentResourceListPage([], str_repeat('a', 513)),
            static fn() => new ContentResourceListResume('Search', 'token'),
            static fn() => new ContentResourceListResume('search', 'not opaque'),
            static fn() => new ContentResourceListResume(str_repeat('a', 65), 'token'),
            static fn() => new ContentResourceRegistryListPage(['resource' => $descriptor]),
            static fn() => new ContentResourceRegistryListPage([new \stdClass()]),
        ] as $case) {
            $this->assertInvalidArgument($case);
        }

        $registry = new ContentResourceRegistry();
        try {
            $registry->register('Not-Wire-Safe', $this->provider([]));
            self::fail('Expected invalid provider-name refusal.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    /** @param list<ContentResourceDescriptor> $resources */
    private function provider(array $resources, ?string $nextToken = null): ContentResourceProviderInterface
    {
        return new class($resources, $nextToken) implements ContentResourceProviderInterface {
            /** @param list<ContentResourceDescriptor> $resources */
            public function __construct(
                private readonly array $resources,
                private readonly ?string $nextToken,
            ) {}

            public function list(AuthorizationPrincipalInterface $principal, ?string $resumeToken = null): ContentResourceListPage
            {
                return new ContentResourceListPage($this->resources, $this->nextToken);
            }

            public function templates(): array { return []; }
            public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent { return null; }
        };
    }

    private function assertInvalidArgument(\Closure $case): void
    {
        try {
            $case();
            self::fail('Expected invalid pagination value refusal.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private function principal(): AuthorizationPrincipalInterface
    {
        return new class implements AuthorizationPrincipalInterface {
            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'pagination-test-v1'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }
}
