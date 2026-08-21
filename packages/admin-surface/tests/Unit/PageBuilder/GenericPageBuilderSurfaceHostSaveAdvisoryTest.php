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
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

/**
 * The page-builder transport can present a save advisory and carry the
 * reviewer's receipt back, using the same Admin envelope and the same machine
 * code as the entity save path.
 */
final class GenericPageBuilderSurfaceHostSaveAdvisoryTest extends TestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function an_advisory_is_projected_as_428_with_an_allowlisted_payload(): void
    {
        [$host, $gateway, $fingerprint] = $this->host(new AdvisorySurfaceGateway(self::TOKEN));

        $result = $host->handleCommand($this->command($fingerprint), 'pages', '42');

        self::assertFalse($result['ok']);
        self::assertSame(428, $result['error']['status']);
        self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', $result['error']['code']);
        self::assertSame(
            [[
                'code' => 'EDITORIAL_TITLE_REVIEW',
                'field' => 'title',
                'severity' => 'warning',
                'message' => 'This title is held for editorial review before any save.',
                'acknowledgement' => self::TOKEN,
            ]],
            $result['error']['meta']['save_advisories'],
        );
        self::assertSame(0, $gateway->updates, 'A held edit must not write.');
    }

    #[Test]
    public function returning_the_exact_receipt_lets_the_edit_through(): void
    {
        [$host, $gateway, $fingerprint] = $this->host(new AdvisorySurfaceGateway(self::TOKEN));

        $result = $host->handleCommand($this->command($fingerprint, [self::TOKEN]), 'pages', '42');

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(8, $result['data']['entity_revision_id']);
        self::assertSame([self::TOKEN], $gateway->receipts);
        self::assertSame(1, $gateway->updates);
    }

    #[Test]
    public function a_request_without_receipts_forwards_an_empty_list_not_a_synthesized_one(): void
    {
        [$host, $gateway, $fingerprint] = $this->host(new AdvisorySurfaceGateway(null));

        $result = $host->handleCommand($this->command($fingerprint), 'pages', '42');

        self::assertTrue($result['ok']);
        self::assertSame([], $gateway->receipts);
    }

    #[Test]
    public function receipts_sent_to_a_legacy_surface_are_refused_rather_than_dropped(): void
    {
        [$host, $gateway, $fingerprint] = $this->host(new LegacySurfaceGateway());

        $result = $host->handleCommand($this->command($fingerprint, [self::TOKEN]), 'pages', '42');

        self::assertFalse($result['ok']);
        self::assertSame(501, $result['error']['status']);
        self::assertSame('SAVE_ADVISORY_UNSUPPORTED', $result['error']['code']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(0, $gateway->updates, 'The refusal must land before any write.');
    }

    #[Test]
    public function a_legacy_surface_without_receipts_is_completely_unaffected(): void
    {
        [$host, $gateway, $fingerprint] = $this->host(new LegacySurfaceGateway());

        $result = $host->handleCommand($this->command($fingerprint), 'pages', '42');

        self::assertTrue($result['ok']);
        self::assertSame(8, $result['data']['entity_revision_id']);
        self::assertSame(1, $gateway->updates);
        self::assertSame(5, $gateway->argumentCount);
    }

    #[Test]
    public function a_malformed_receipt_is_rejected_at_the_wire_before_any_surface_call(): void
    {
        foreach ([['not-a-token'], [self::TOKEN, 42], 'a-string', array_fill(0, 33, self::TOKEN)] as $malformed) {
            [$host, $gateway, $fingerprint] = $this->host(new AdvisorySurfaceGateway(null));

            $result = $host->handleCommand($this->command($fingerprint, $malformed), 'pages', '42');

            self::assertFalse($result['ok'], json_encode($malformed));
            self::assertSame(400, $result['error']['status']);
            self::assertSame(0, $gateway->updates);
        }
    }

    /** @param list<string>|string|array<int, mixed>|null $receipts */
    private function command(string $fingerprint, array|string|null $receipts = null): PageBuilderSurfaceRequest
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
            $this->actor(),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{0: GenericPageBuilderSurfaceHost, 1: object, 2: string} */
    private function host(LayoutDraftGatewayInterface $gateway): array
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
        $editor = new LayoutEditor($codec, new LayoutValidator($registry), $registry);
        $manager = new LayoutDraftManager($gateway, $codec, new LayoutValidator($registry), $editor);
        $surfaces = new PageBuilderSurfaceRegistry();
        $surfaces->register('pages', new PageBuilderSurface(
            'edit pages',
            $registry,
            $manager,
            new NullPreviewGateway(),
        ));

        $document = $this->document();
        $gateway->seed($codec->encode($document));

        return [
            new GenericPageBuilderSurfaceHost($surfaces),
            $gateway,
            $editor->fingerprint($document),
        ];
    }

    private function actor(): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }

    private function document(): LayoutDocument
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
                    'config' => ['html' => '<p>Before</p>'],
                ]]],
            ]],
        ]);
    }
}

/** A gateway that holds every unacknowledged edit for review. */
final class AdvisorySurfaceGateway implements AdvisoryAwareLayoutDraftGatewayInterface
{
    public int $updates = 0;
    /** @var list<string>|null */
    public ?array $receipts = null;
    private string $encoded = '';

    public function __construct(private readonly ?string $requiredToken) {}

    public function seed(string $encoded): void
    {
        $this->encoded = $encoded;
    }

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        return new LayoutDraftSnapshot($entityId, 7, $this->encoded);
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraftSnapshot {
        $this->receipts = $saveAdvisoryAcknowledgements;
        if ($this->requiredToken !== null && !in_array($this->requiredToken, $saveAdvisoryAcknowledgements, true)) {
            throw new LayoutSaveAdvisoryException([[
                'code' => 'EDITORIAL_TITLE_REVIEW',
                'field' => 'title',
                'severity' => 'warning',
                'message' => 'This title is held for editorial review before any save.',
                'acknowledgement' => $this->requiredToken,
            ]]);
        }
        ++$this->updates;

        return new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}

/** A gateway frozen at the original five-argument contract. */
final class LegacySurfaceGateway implements LayoutDraftGatewayInterface
{
    public int $updates = 0;
    public int $argumentCount = 0;
    private string $encoded = '';

    public function seed(string $encoded): void
    {
        $this->encoded = $encoded;
    }

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        return new LayoutDraftSnapshot($entityId, 7, $this->encoded);
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        ++$this->updates;
        $this->argumentCount = func_num_args();

        return new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}

final class NullPreviewGateway implements RevisionPreviewGatewayInterface
{
    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant {
        throw new \LogicException('Preview is not exercised by this test.');
    }
}
