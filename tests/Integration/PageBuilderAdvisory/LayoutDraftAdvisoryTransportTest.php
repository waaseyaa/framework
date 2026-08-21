<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PageBuilderAdvisory;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AdminSurface\PageBuilder\GenericPageBuilderSurfaceHost;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;

/**
 * The whole shipped composition, transport included: Admin Surface host over a
 * page-builder surface over the publishing-backed layout gateway.
 *
 * The gateway advertises `AdvisoryAwareLayoutDraftGatewayInterface`, so the
 * layout dispatcher hands it receipts; the publisher underneath is frozen at
 * five arguments, so the publishing dispatcher refuses. That refusal is raised
 * in `waaseyaa/publishing`, and `waaseyaa/admin-surface` deliberately does not
 * depend on that package. Unless the gateway translates it, the refusal escapes
 * the host uncaught and the promised structured 501 never reaches the client.
 */
#[CoversNothing]
final class LayoutDraftAdvisoryTransportTest extends TestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function receipts_over_a_legacy_publisher_answer_a_structured_501(): void
    {
        [$host, $publisher, $fingerprint] = $this->composition();

        $result = $host->handleCommand($this->command($fingerprint, [self::TOKEN]), 'pages', '42');

        self::assertFalse($result['ok']);
        self::assertSame(501, $result['error']['status']);
        self::assertSame('SAVE_ADVISORY_UNSUPPORTED', $result['error']['code']);
        self::assertSame(
            'This layout draft surface cannot accept save advisory acknowledgements.',
            $result['error']['detail'],
        );
        self::assertSame(0, $publisher->updateCalls, 'The refusal must land before any write.');
    }

    #[Test]
    public function the_refusal_leaks_no_token_class_name_or_implementation_identity(): void
    {
        [$host, $publisher, $fingerprint] = $this->composition();

        $rendered = json_encode(
            $host->handleCommand($this->command($fingerprint, [self::TOKEN]), 'pages', '42'),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(self::TOKEN, $rendered);
        self::assertStringNotContainsString('save_advisories', $rendered);
        foreach ([
            'Waaseyaa',
            'Publishing',
            'PublishingLayoutDraftGateway',
            'ContentDraftMutationInterface',
            'Dispatcher',
            $publisher::class,
        ] as $identity) {
            self::assertStringNotContainsString($identity, $rendered, "refusal leaked {$identity}");
        }
    }

    #[Test]
    public function the_same_composition_without_receipts_calls_the_frozen_five_argument_publisher(): void
    {
        [$host, $publisher, $fingerprint] = $this->composition();

        $result = $host->handleCommand($this->command($fingerprint), 'pages', '42');

        self::assertTrue($result['ok'], $result['error']['detail'] ?? '');
        self::assertSame(8, $result['data']['entity_revision_id']);
        self::assertSame(1, $publisher->updateCalls);
        self::assertSame(
            5,
            $publisher->argumentCount,
            'A publisher frozen at five parameters must never receive a sixth argument.',
        );
    }

    /** @param list<string>|null $receipts */
    private function command(string $fingerprint, ?array $receipts = null): PageBuilderSurfaceRequest
    {
        $payload = [
            'expected_entity_revision_id' => 7,
            'expected_document_fingerprint' => $fingerprint,
            'idempotency_key' => 'edit-1',
            'command' => [
                'type' => 'configure_block',
                'block_id' => 'blk_body',
                'config' => ['html' => '<p>After</p>'],
            ],
        ];
        if ($receipts !== null) {
            $payload['save_advisory_acknowledgements'] = $receipts;
        }

        return new PageBuilderSurfaceRequest(
            new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test'),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{0: GenericPageBuilderSurfaceHost, 1: FrozenFiveArgumentPublisher, 2: string} */
    private function composition(): array
    {
        $registry = new DefinitionRegistry();
        $registry->registerBlock(new BlockDefinition(
            id: 'rich_text',
            version: 1,
            label: 'Rich text',
            renderer: 'content.rich_text',
            configSchema: [
                'type' => 'object',
                'required' => ['html'],
                'additionalProperties' => false,
                'properties' => ['html' => ['type' => 'string']],
            ],
        ));
        $registry->registerLayout(new LayoutDefinition('one_column', 1, ['main'], ['main'], ['rich_text']));
        $registry->registerTemplate(new TemplateDefinition('standard', 1, ['one_column'], ['rich_text']));

        $codec = new CanonicalLayoutCodec();
        $document = LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_body',
                'layout' => ['id' => 'one_column', 'version' => 1],
                'regions' => ['main' => [[
                    'id' => 'blk_body',
                    'type' => 'rich_text',
                    'version' => 1,
                    'config' => ['html' => '<p>Before</p>'],
                ]]],
            ]],
        ]);

        $publisher = new FrozenFiveArgumentPublisher($codec->encode($document));
        $editor = new LayoutEditor($codec, new LayoutValidator($registry), $registry);
        $surfaces = new PageBuilderSurfaceRegistry();
        $surfaces->register('pages', new PageBuilderSurface(
            'edit pages',
            $registry,
            new LayoutDraftManager(
                new PublishingLayoutDraftGateway($publisher, 'page_layout'),
                $codec,
                new LayoutValidator($registry),
                $editor,
            ),
            new UnusedPreviewGateway(),
        ));

        return [new GenericPageBuilderSurfaceHost($surfaces), $publisher, $editor->fingerprint($document)];
    }
}

/** A publisher that predates acknowledgements: exactly five parameters. */
final class FrozenFiveArgumentPublisher implements ContentDraftMutationInterface
{
    public int $updateCalls = 0;
    public int $argumentCount = 0;

    public function __construct(private readonly string $encodedLayout) {}

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7, 'page_layout' => $this->encodedLayout];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array {
        ++$this->updateCalls;
        $this->argumentCount = func_num_args();

        return ['id' => $id, 'revision_id' => $expectedRevisionId + 1] + $values;
    }
}

final class UnusedPreviewGateway implements RevisionPreviewGatewayInterface
{
    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant {
        throw new \LogicException('Preview is not exercised by this test.');
    }
}
