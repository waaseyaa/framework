<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Command\ConfigureBlock;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

final class LayoutDraftManagerTest extends TestCase
{
    #[Test]
    public function appliesOneValidatedEditThroughTheRevisionGuardedGateway(): void
    {
        $codec = new CanonicalLayoutCodec();
        $initial = $this->document('<p>Before</p>');
        $gateway = new RecordingGateway(new LayoutDraftSnapshot('42', 7, $codec->encode($initial)));
        $manager = $this->manager($gateway);
        $draft = $manager->read($this->actor(), '42');

        $updated = $manager->apply(
            actor: $this->actor(),
            entityId: '42',
            expectedEntityRevisionId: 7,
            expectedDocumentFingerprint: $draft->documentFingerprint,
            command: new ConfigureBlock('blk_body', ['html' => '<p>After</p>']),
            idempotencyKey: 'edit-42-1',
        );

        self::assertSame(8, $updated->entityRevisionId);
        self::assertSame(['html' => '<p>After</p>'], $updated->document->sections()[0]['regions']['main'][0]['config']);
        self::assertSame(7, $gateway->expectedRevisionId);
        self::assertSame('edit-42-1', $gateway->idempotencyKey);
        self::assertSame($codec->encode($updated->document), $gateway->encodedLayout);
    }

    #[Test]
    public function staleEntityRevisionRefusesBeforeTheGatewayMutation(): void
    {
        $codec = new CanonicalLayoutCodec();
        $gateway = new RecordingGateway(new LayoutDraftSnapshot('42', 9, $codec->encode($this->document('<p>Newer</p>'))));
        $manager = $this->manager($gateway);

        try {
            $manager->apply(
                actor: $this->actor(),
                entityId: '42',
                expectedEntityRevisionId: 8,
                expectedDocumentFingerprint: str_repeat('a', 64),
                command: new ConfigureBlock('blk_body', ['html' => '<p>Must not win</p>']),
                idempotencyKey: 'stale-edit',
            );
            self::fail('Stale entity revision was accepted.');
        } catch (StaleEntityRevisionException $exception) {
            self::assertSame(8, $exception->expectedRevisionId);
            self::assertSame(9, $exception->currentRevisionId);
            self::assertSame(0, $gateway->updateCalls);
        }
    }

    private function manager(LayoutDraftGatewayInterface $gateway): LayoutDraftManager
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

        return new LayoutDraftManager(
            gateway: $gateway,
            codec: $codec,
            validator: new LayoutValidator($registry),
            editor: new LayoutEditor($codec, new LayoutValidator($registry), $registry),
        );
    }

    private function actor(): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }

    private function document(string $html): LayoutDocument
    {
        return LayoutDocument::fromArray([
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
                    'config' => ['html' => $html],
                ]]],
            ]],
        ]);
    }
}

final class RecordingGateway implements LayoutDraftGatewayInterface
{
    public int $updateCalls = 0;
    public ?int $expectedRevisionId = null;
    public ?string $idempotencyKey = null;
    public ?string $encodedLayout = null;

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
        ++$this->updateCalls;
        $this->expectedRevisionId = $expectedRevisionId;
        $this->idempotencyKey = $idempotencyKey;
        $this->encodedLayout = $encodedLayout;
        $this->snapshot = new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);

        return $this->snapshot;
    }
}
