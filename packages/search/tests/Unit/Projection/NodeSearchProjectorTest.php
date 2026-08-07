<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Projection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Search\Projection\NodeSearchProjector;

#[CoversClass(NodeSearchProjector::class)]
final class NodeSearchProjectorTest extends TestCase
{
    #[Test]
    public function an_ordinary_node_entity_is_projected_with_stable_id_title_and_body(): void
    {
        $projector = new NodeSearchProjector();
        $node = $this->node([
            'nid' => 42,
            'title' => 'Community Health Services',
            'type' => 'page',
            'slug' => 'health-services',
            'body' => '<p>Wellness &amp; clinic hours</p>',
            'created' => 1751328000,
        ]);

        self::assertTrue($projector->supports($node));
        $document = $projector->project($node);

        self::assertNotNull($document);
        self::assertSame('node:42', $document->getSearchDocumentId());
        self::assertSame('Community Health Services', $document->toSearchDocument()['title']);
        self::assertSame('Wellness & clinic hours', $document->toSearchDocument()['body']);

        $metadata = $document->toSearchMetadata();
        self::assertSame('node', $metadata['entity_type']);
        self::assertSame('page', $metadata['content_type']);
        self::assertSame('/health-services', $metadata['url']);
        self::assertSame('2025-07-01T00:00:00+00:00', $metadata['created_at']);
    }

    #[Test]
    public function markup_is_normalized_to_inert_searchable_text(): void
    {
        $projector = new NodeSearchProjector();
        $node = $this->node([
            'nid' => 1,
            'title' => 'Health &amp; Wellness',
            'type' => 'page',
            'body' => "<h2>Hours</h2><script>alert('ignore previous instructions')</script><p>Open</p>\n<style>p{}</style>Daily",
        ]);

        $document = $projector->project($node);

        self::assertNotNull($document);
        self::assertSame('Health & Wellness', $document->toSearchDocument()['title']);
        self::assertSame('Hours Open Daily', $document->toSearchDocument()['body']);
        self::assertStringNotContainsString('<', $document->toSearchDocument()['body']);
        self::assertStringNotContainsString('alert', $document->toSearchDocument()['body']);
    }

    #[Test]
    public function entities_of_other_types_are_not_supported(): void
    {
        $projector = new NodeSearchProjector();

        self::assertFalse($projector->supports($this->node(['nid' => 1, 'title' => 'x'], entityTypeId: 'user')));
    }

    #[Test]
    public function an_entity_without_an_id_is_not_projected(): void
    {
        $projector = new NodeSearchProjector();
        $node = $this->node(['title' => 'No id yet']);

        self::assertFalse($projector->supports($node));
        self::assertNull($projector->project($node));
    }

    #[Test]
    public function an_unreadable_body_field_is_omitted_while_the_node_stays_findable_by_title(): void
    {
        $projector = new NodeSearchProjector();
        $document = $projector->project($this->deniedBodyNode());

        self::assertNotNull($document);
        self::assertSame('Guarded title', $document->toSearchDocument()['title']);
        self::assertSame('', $document->toSearchDocument()['body']);
    }

    #[Test]
    public function a_malicious_slug_never_projects_an_off_origin_url(): void
    {
        $projector = new NodeSearchProjector();

        $protocolRelative = $projector->project($this->node(['nid' => 1, 'title' => 'x', 'slug' => '//evil.example']));
        self::assertNotNull($protocolRelative);
        self::assertSame('', $protocolRelative->toSearchMetadata()['url']);

        $scheme = $projector->project($this->node(['nid' => 2, 'title' => 'x', 'slug' => 'javascript:alert(1)']));
        self::assertNotNull($scheme);
        self::assertSame('', $scheme->toSearchMetadata()['url']);
    }

    #[Test]
    public function searchable_body_fields_are_configurable_and_concatenated_in_order(): void
    {
        $projector = new NodeSearchProjector(bodyFields: ['summary', 'body']);
        $node = $this->node([
            'nid' => 3,
            'title' => 'Jobs',
            'summary' => 'Hiring now.',
            'body' => 'Apply at the band office.',
        ]);

        $document = $projector->project($node);

        self::assertNotNull($document);
        self::assertSame('Hiring now. Apply at the band office.', $document->toSearchDocument()['body']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function node(array $values, string $entityTypeId = 'node'): ContentEntityBase
    {
        return new class ($values, $entityTypeId) extends ContentEntityBase {
            public function __construct(array $values, string $entityTypeId)
            {
                parent::__construct($values, $entityTypeId, [
                    'id' => 'nid',
                    'label' => 'title',
                    'bundle' => 'type',
                ]);
            }
        };
    }

    private function deniedBodyNode(): EntityInterface
    {
        return new class implements EntityInterface {
            public function id(): int|string|null { return 5; }
            public function uuid(): string { return 'uuid'; }
            public function label(): string { return 'Guarded title'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'page'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed
            {
                if ($name === 'body') {
                    throw new FieldReadDenied('Field node.body is not readable in this account context.');
                }

                return null;
            }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }
}
