<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\PageBuilder;

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
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

final class GenericPageBuilderSurfaceHostTest extends TestCase
{
    #[Test]
    public function authenticated_clients_share_definitions_drafts_commands_and_exact_preview(): void
    {
        [$host, $gateway] = $this->host();
        $actor = $this->actor(['edit pages']);

        $definitions = $host->handleDefinitions($this->request('GET', '', $actor), 'pages');
        self::assertTrue($definitions['ok']);
        self::assertSame('rich_text', $definitions['data']['definitions']['blocks'][0]['id']);

        $draft = $host->handleDraft($this->request('GET', '', $actor), 'pages', '42');
        self::assertTrue($draft['ok']);
        self::assertSame(7, $draft['data']['entity_revision_id']);

        $command = $host->handleCommand($this->request('POST', json_encode([
            'expected_entity_revision_id' => 7,
            'expected_document_fingerprint' => $draft['data']['document_fingerprint'],
            'idempotency_key' => 'anokii-edit-1',
            'command' => [
                'type' => 'configure_block',
                'block_id' => 'blk_body',
                'config' => ['html' => '<p>After</p>'],
            ]], JSON_THROW_ON_ERROR), $actor), 'pages', '42');
        self::assertTrue($command['ok']);
        self::assertSame(8, $command['data']['entity_revision_id']);
        self::assertSame(1, $gateway->updates);

        $preview = $host->handlePreview($this->request('POST', json_encode([
            'expected_entity_revision_id' => 8,
        ], JSON_THROW_ON_ERROR), $actor), 'pages', '42');
        self::assertTrue($preview['ok']);
        self::assertSame(8, $preview['data']['revision_id']);
        self::assertSame('/preview/42?revision=8', $preview['data']['preview_url']);
    }

    #[Test]
    public function permission_and_wire_failures_are_closedAndTyped(): void
    {
        [$host] = $this->host();

        $denied = $host->handleDraft($this->request('GET', '', $this->actor([])), 'pages', '42');
        self::assertSame(403, $denied['error']['status']);

        $invalid = $host->handleCommand($this->request('POST', json_encode([
            'expected_entity_revision_id' => 7,
            'expected_document_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => 'edit-1',
            'command' => ['type' => 'remove_block', 'block_id' => 'blk_body', 'force' => true],
        ], JSON_THROW_ON_ERROR), $this->actor(['edit pages'])), 'pages', '42');
        self::assertSame(400, $invalid['error']['status']);

        $unknown = $host->handleDraft($this->request('GET', '', $this->actor(['edit pages'])), 'unknown', '42');
        self::assertSame(404, $unknown['error']['status']);
    }

    /** @return array{GenericPageBuilderSurfaceHost, SurfaceGateway} */
    private function host(): array
    {
        $definitions = new DefinitionRegistry();
        $definitions->registerBlock(new BlockDefinition('rich_text', 1, 'Rich text', 'content.rich_text', [
            'type' => 'object',
            'required' => ['html'],
            'additionalProperties' => false,
            'properties' => ['html' => ['type' => 'string']],
        ]));
        $definitions->registerLayout(new LayoutDefinition('one_column', 1, ['main'], ['main'], ['rich_text']));
        $definitions->registerTemplate(new TemplateDefinition('standard', 1, ['one_column'], ['rich_text']));

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
        $gateway = new SurfaceGateway(new LayoutDraftSnapshot('42', 7, $codec->encode($document)));
        $validator = new LayoutValidator($definitions);
        $surface = new PageBuilderSurface(
            'edit pages',
            $definitions,
            new LayoutDraftManager($gateway, $codec, $validator, new LayoutEditor($codec, $validator, $definitions)),
            new SurfacePreviewGateway(),
        );
        $surfaces = new PageBuilderSurfaceRegistry();
        $surfaces->register('pages', $surface);

        return [new GenericPageBuilderSurfaceHost($surfaces), $gateway];
    }

    private function actor(array $permissions): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], $permissions, 'test');
    }

    private function request(string $method, string $content, AuthorizationPrincipalInterface $actor): PageBuilderSurfaceRequest
    {
        return new PageBuilderSurfaceRequest($actor, $content);
    }
}

final class SurfaceGateway implements LayoutDraftGatewayInterface
{
    public int $updates = 0;

    public function __construct(private LayoutDraftSnapshot $snapshot) {}

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        return $this->snapshot;
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        ++$this->updates;

        return $this->snapshot = new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}

final class SurfacePreviewGateway implements RevisionPreviewGatewayInterface
{
    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant {
        return new RevisionPreviewGrant(
            $entityId,
            $expectedRevisionId,
            2_000_000_000,
            'signed-preview',
            "/preview/{$entityId}?revision={$expectedRevisionId}",
        );
    }
}
