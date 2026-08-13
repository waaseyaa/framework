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
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionGatewayInterface;
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionHistory;
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionSnapshot;
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

        $history = $host->handleHistory($this->request('GET', '', $actor), 'pages', '42');
        self::assertTrue($history['ok']);
        self::assertSame([8, 7], array_column($history['data']['revisions'], 'revision_id'));

        $revision = $host->handleRevision($this->request('GET', '', $actor), 'pages', '42', '7');
        self::assertSame('<p>Before</p>', $revision['data']['document']['sections'][0]['regions']['main'][0]['config']['html']);

        $restored = $host->handleRestore($this->request('POST', json_encode([
            'target_revision_id' => 7,
            'expected_current_revision_id' => 8,
            'idempotency_key' => 'restore-7',
        ], JSON_THROW_ON_ERROR), $actor), 'pages', '42');
        self::assertTrue($restored['ok']);
        self::assertSame(9, $restored['data']['entity_revision_id']);
        self::assertSame('<p>Before</p>', $restored['data']['document']['sections'][0]['regions']['main'][0]['config']['html']);
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
            new PageBuilderRevisionHistory($gateway, $codec, $validator, new LayoutEditor($codec, $validator, $definitions)),
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

final class SurfaceGateway implements LayoutDraftGatewayInterface, PageBuilderRevisionGatewayInterface
{
    public int $updates = 0;

    /** @var array<int, LayoutDraftSnapshot> */
    private array $snapshots;

    public function __construct(private LayoutDraftSnapshot $snapshot)
    {
        $this->snapshots = [$snapshot->entityRevisionId => $snapshot];
    }

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

        $this->snapshot = new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
        $this->snapshots[$this->snapshot->entityRevisionId] = $this->snapshot;

        return $this->snapshot;
    }

    public function list(AuthorizationPrincipalInterface $actor, string $entityId): array
    {
        return array_map(
            fn(LayoutDraftSnapshot $snapshot): PageBuilderRevisionSnapshot => new PageBuilderRevisionSnapshot(
                $entityId,
                $snapshot->entityRevisionId,
                $snapshot->encodedLayout,
                isLatest: $snapshot->entityRevisionId === $this->snapshot->entityRevisionId,
            ),
            array_reverse(array_values($this->snapshots)),
        );
    }

    public function readRevision(AuthorizationPrincipalInterface $actor, string $entityId, int $revisionId): PageBuilderRevisionSnapshot
    {
        $snapshot = $this->snapshots[$revisionId] ?? throw new \InvalidArgumentException('Unknown revision.');

        return new PageBuilderRevisionSnapshot($entityId, $revisionId, $snapshot->encodedLayout);
    }

    public function restore(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $targetRevisionId,
        int $expectedCurrentRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        return $this->update($actor, $entityId, $this->readRevision($actor, $entityId, $targetRevisionId)->encodedLayout, $expectedCurrentRevisionId, $idempotencyKey);
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
