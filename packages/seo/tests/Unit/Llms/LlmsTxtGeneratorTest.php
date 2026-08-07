<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Llms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\Seo\Llms\LlmsTopic;
use Waaseyaa\Seo\Llms\LlmsTxtGenerator;

#[CoversClass(LlmsTxtGenerator::class)]
#[CoversClass(LlmsTopic::class)]
final class LlmsTxtGeneratorTest extends TestCase
{
    #[Test]
    public function generates_an_index_with_title_summary_and_topic_links(): void
    {
        $md = new LlmsTxtGenerator()->generate('My Site', 'The summary.', [
            new LlmsTopic('node', 'Articles', 'All articles.', [
                ['title' => 'First', 'url' => '/articles/first.md'],
                ['title' => 'Second', 'url' => '/articles/second.md'],
            ]),
        ]);

        self::assertStringContainsString("# My Site\n", $md);
        self::assertStringContainsString("> The summary.\n", $md);
        self::assertStringContainsString("## Articles\n", $md);
        self::assertStringContainsString('All articles.', $md);
        self::assertStringContainsString('- [First](/articles/first.md)', $md);
        self::assertStringContainsString('- [Second](/articles/second.md)', $md);
        // It is an index of .md URLs, not an inlined corpus.
        self::assertStringNotContainsString('llms-full', $md);
    }

    #[Test]
    public function topics_with_no_links_are_skipped(): void
    {
        $md = new LlmsTxtGenerator()->generate('S', null, [
            new LlmsTopic('empty', 'Empty', 'none', []),
            new LlmsTopic('node', 'Articles', '', [['title' => 'A', 'url' => '/a.md']]),
        ]);

        self::assertStringNotContainsString('## Empty', $md);
        self::assertStringContainsString('## Articles', $md);
    }

    #[Test]
    public function omits_summary_block_when_absent(): void
    {
        $md = new LlmsTxtGenerator()->generate('S', null, []);
        self::assertSame("# S\n", $md);
    }

    #[Test]
    public function collect_topics_builds_one_topic_per_public_content_type(): void
    {
        $generator = new LlmsTxtGenerator();

        // describeType: 'node' is public content; 'user' is not -> skipped.
        $describeType = static fn(string $type): ?array => $type === 'node'
            ? ['title' => 'Articles', 'summary' => 'All articles.']
            : null;

        $buildLink = static fn(string $type, int|string $id, string $label): array
            => ['title' => "node {$id}", 'url' => "/node/{$id}.md"];

        $topics = $generator->collectTopics($this->managerWith(['node' => [1, 2], 'user' => [9]]), $describeType, $buildLink);

        self::assertCount(1, $topics);
        self::assertSame('node', $topics[0]->key);
        self::assertSame('Articles', $topics[0]->title);
        self::assertSame(
            [['title' => 'node 1', 'url' => '/node/1.md'], ['title' => 'node 2', 'url' => '/node/2.md']],
            $topics[0]->links,
        );
    }

    #[Test]
    public function collect_topics_binds_the_given_account_instead_of_bypassing_access(): void
    {
        // R6/M3 (audit M3): the public crawler inventory must be
        // ACCESS-AWARE — setAccount() (not accessCheck(false)) is the
        // mechanism, so a published-but-access-restricted entity is excluded
        // via the same EntityAccessHandler pipeline the rest of the
        // framework uses.
        $query = (new RecordingEntityQuery())->withResults([1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class)]);
        $manager->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $manager->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $account = $this->createStub(AccountInterface::class);

        $topics = new LlmsTxtGenerator()->collectTopics(
            $manager,
            static fn(string $type): array => ['title' => 'Articles', 'summary' => ''],
            static fn(string $type, int|string $id, string $label): array => ['title' => "n{$id}", 'url' => "/n/{$id}.md"],
            account: $account,
        );

        self::assertCount(1, $topics);
        self::assertSame([], $query->accessChecks, 'must not call accessCheck(false) when an account is supplied');
        self::assertSame($account, $query->boundAccount, 'must bind the caller-supplied account via setAccount()');
    }

    #[Test]
    public function collect_topics_does_not_bind_an_account_when_none_is_supplied(): void
    {
        // Fail-closed default: no account => no setAccount(), no
        // accessCheck(false) — callers building a public crawler surface
        // MUST pass an account.
        $query = (new RecordingEntityQuery())->withResults([1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class)]);
        $manager->method('getStorage')->willReturn($storage);
        $manager->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        new LlmsTxtGenerator()->collectTopics(
            $manager,
            static fn(string $type): array => ['title' => 'Articles', 'summary' => ''],
            static fn(string $type, int|string $id, string $label): array => ['title' => "n{$id}", 'url' => "/n/{$id}.md"],
        );

        self::assertSame([], $query->accessChecks);
        self::assertNull($query->boundAccount);
    }

    // --- Injection hardening tests (§2.8 residual-security-sweep) ---

    #[Test]
    public function topic_title_with_embedded_newline_does_not_forge_a_new_heading_line(): void
    {
        // A title containing "\n## Injected" must NOT produce a standalone `## Injected` line.
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', "News\n## Injected", '', [
                ['title' => 'A', 'url' => 'https://example.test/a.md'],
            ]),
        ]);

        $lines = explode("\n", $md);
        foreach ($lines as $line) {
            self::assertDoesNotMatchRegularExpression('/^## Injected/', $line, 'Newline in topic title forged a heading line');
        }
        // The sanitized value should appear — collapsed to a single ## line.
        self::assertStringContainsString('## News', $md);
    }

    #[Test]
    public function topic_summary_with_embedded_newline_does_not_forge_a_link_line(): void
    {
        // A summary containing "\n- [evil](http://evil.test)" must NOT produce a standalone link.
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', 'Articles', "ok\n- [evil](http://evil.test)", [
                ['title' => 'A', 'url' => 'https://example.test/a.md'],
            ]),
        ]);

        $lines = explode("\n", $md);
        foreach ($lines as $line) {
            self::assertDoesNotMatchRegularExpression('/^- \[evil\]/', $line, 'Newline in topic summary forged a link line');
        }
    }

    #[Test]
    public function site_summary_with_embedded_newline_is_collapsed_to_one_blockquote_line(): void
    {
        $md = new LlmsTxtGenerator()->generate('My Site', "First line\nSecond line", []);

        // There must be exactly one line starting with '>' — the second line must not appear standalone.
        $quotedLines = array_filter(explode("\n", $md), static fn(string $line): bool => str_starts_with($line, '>'));
        self::assertCount(1, array_values($quotedLines), 'Site summary newline forged a second blockquote line');
    }

    #[Test]
    public function link_with_javascript_scheme_is_skipped(): void
    {
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', 'Articles', '', [
                ['title' => 'Safe', 'url' => 'https://example.test/page'],
                ['title' => 'Evil', 'url' => 'javascript:alert(1)'],
            ]),
        ]);

        self::assertStringContainsString('https://example.test/page', $md);
        self::assertStringNotContainsString('javascript:', $md);
    }

    #[Test]
    public function link_with_data_scheme_is_skipped(): void
    {
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', 'Articles', '', [
                ['title' => 'Safe', 'url' => 'https://example.test/page'],
                ['title' => 'Evil', 'url' => 'data:text/html,<script>alert(1)</script>'],
            ]),
        ]);

        self::assertStringContainsString('https://example.test/page', $md);
        self::assertStringNotContainsString('data:text/html', $md);
    }

    #[Test]
    public function link_with_relative_url_is_present(): void
    {
        // The generator is routing-agnostic — a site-relative path (no scheme)
        // produced by the caller's buildLink callback is a valid llms.txt link
        // and must NOT be dropped by the scheme gate. Pins the relative-URL
        // contract so the injection hardening cannot silently re-break it.
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', 'Articles', '', [
                ['title' => 'First', 'url' => '/articles/first.md'],
            ]),
        ]);

        self::assertStringContainsString('- [First](/articles/first.md)', $md);
    }

    #[Test]
    public function link_with_safe_https_url_is_present_byte_identical(): void
    {
        $md = new LlmsTxtGenerator()->generate('My Site', null, [
            new LlmsTopic('node', 'Articles', '', [
                ['title' => 'Page', 'url' => 'https://example.test/a?b=c#d'],
            ]),
        ]);

        self::assertStringContainsString('- [Page](https://example.test/a?b=c#d)', $md);
    }

    #[Test]
    public function collect_topics_filters_to_published_for_status_bearing_types(): void
    {
        // A public llms.txt must only advertise PUBLISHED content. A type that
        // declares a `status` field must have condition('status', 1) applied so
        // unpublished/draft URLs are not enumerated for anonymous crawlers.
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([1]);
        $query->expects(self::once())->method('condition')->with('status', 1)->willReturnSelf();

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class);
        $def->method('getFieldDefinitions')->willReturn(['status' => true, 'title' => true]);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $def]);
        $manager->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $manager->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        new LlmsTxtGenerator()->collectTopics(
            $manager,
            static fn(string $type): array => ['title' => 'Articles', 'summary' => ''],
            static fn(string $type, int|string $id, string $label): array => ['title' => "n{$id}", 'url' => "/n/{$id}.md"],
        );
    }

    /**
     * @param array<string, list<int|string>> $typeIds
     */
    private function managerWith(array $typeIds): EntityTypeManagerInterface
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);

        $definitions = [];
        foreach (array_keys($typeIds) as $type) {
            $definitions[$type] = $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class);
        }
        $manager->method('getDefinitions')->willReturn($definitions);

        $manager->method('getStorage')->willReturnCallback(function (string $type) use ($typeIds): EntityStorageInterface {
            $query = $this->createStub(EntityQueryInterface::class);
            $query->method('accessCheck')->willReturnSelf();
            $query->method('range')->willReturnSelf();
            $query->method('execute')->willReturn($typeIds[$type] ?? []);

            $storage = $this->createStub(EntityStorageInterface::class);
            $storage->method('getQuery')->willReturn($query);

            return $storage;
        });
        // C-22: the query builder now lives on the repository.
        $manager->method('getRepository')->willReturnCallback(function (string $type) use ($typeIds): EntityRepositoryInterface {
            $query = $this->createStub(EntityQueryInterface::class);
            $query->method('accessCheck')->willReturnSelf();
            $query->method('range')->willReturnSelf();
            $query->method('execute')->willReturn($typeIds[$type] ?? []);

            return new QueryOnlyStubRepository($query);
        });

        return $manager;
    }
}
