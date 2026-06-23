<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Llms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
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
    public function collect_topics_enumerates_with_access_check_disabled(): void
    {
        // The public crawler inventory must bypass per-request access; assert the
        // query is built with accessCheck(false).
        $query = $this->createMock(EntityQueryInterface::class);
        $query->expects(self::once())->method('accessCheck')->with(false)->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([1]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class)]);
        $manager->method('getStorage')->willReturn($storage);

        $topics = new LlmsTxtGenerator()->collectTopics(
            $manager,
            static fn(string $type): array => ['title' => 'Articles', 'summary' => ''],
            static fn(string $type, int|string $id, string $label): array => ['title' => "n{$id}", 'url' => "/n/{$id}.md"],
        );

        self::assertCount(1, $topics);
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

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class);
        $def->method('getFieldDefinitions')->willReturn(['status' => true, 'title' => true]);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $def]);
        $manager->method('getStorage')->willReturn($storage);

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
        $manager = $this->createMock(EntityTypeManagerInterface::class);

        $definitions = [];
        foreach (array_keys($typeIds) as $type) {
            $definitions[$type] = $this->createStub(\Waaseyaa\Entity\EntityTypeInterface::class);
        }
        $manager->method('getDefinitions')->willReturn($definitions);

        $manager->method('getStorage')->willReturnCallback(function (string $type) use ($typeIds): EntityStorageInterface {
            $query = $this->createMock(EntityQueryInterface::class);
            $query->method('accessCheck')->willReturnSelf();
            $query->method('range')->willReturnSelf();
            $query->method('execute')->willReturn($typeIds[$type] ?? []);

            $storage = $this->createMock(EntityStorageInterface::class);
            $storage->method('getQuery')->willReturn($query);

            return $storage;
        });

        return $manager;
    }
}
