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
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

/**
 * The Admin SPA layout save-advisory review (#2475) is a two-language contract:
 * TypeScript writes the request body and reads the response envelope, PHP
 * accepts the body and emits the envelope. Neither side's test suite can see
 * the other, so a rename on either side is silently breaking.
 *
 * This test pins the shared vocabulary — body keys, machine codes, the advisory
 * projection's fields, the token shape, and the receipt bound — against the
 * real host, reading the SPA sources for the values the SPA actually uses.
 */
#[CoversNothing]
final class AdminSpaLayoutAdvisoryContractTest extends TestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function the_spa_client_sends_exactly_the_body_keys_the_host_accepts(): void
    {
        $sent = $this->spaCommandBodyKeys();

        self::assertSame([
            'command',
            'expected_document_fingerprint',
            'expected_entity_revision_id',
            'idempotency_key',
            'save_advisory_acknowledgements',
        ], $sent);

        [$host, $gateway, $fingerprint] = $this->composition();

        // Every key the client can send is accepted; nothing is an extra field.
        $accepted = $host->handleCommand($this->spaShapedCommand($fingerprint, [self::TOKEN]), 'pages', '42');
        self::assertTrue($accepted['ok'], $accepted['error']['detail'] ?? '');
        self::assertSame([self::TOKEN], $gateway->receipts);
    }

    #[Test]
    public function the_spa_reader_branches_on_the_machine_codes_the_host_emits(): void
    {
        $reader = $this->spaSource('packages/admin/app/runtime/layoutSaveAdvisory.ts');

        self::assertStringContainsString(
            "LAYOUT_SAVE_ADVISORY_REQUIRED_CODE = '" . LayoutSaveAdvisoryException::ERROR_CODE . "'",
            $reader,
        );
        self::assertStringContainsString(
            "LAYOUT_SAVE_ADVISORY_UNSUPPORTED_CODE = '"
                . UnsupportedLayoutSaveAdvisoryAcknowledgementException::ERROR_CODE . "'",
            $reader,
        );
    }

    #[Test]
    public function the_held_envelope_carries_exactly_the_fields_the_spa_reader_requires(): void
    {
        [$host, $gateway, $fingerprint] = $this->composition(self::TOKEN);

        $result = $host->handleCommand($this->spaShapedCommand($fingerprint), 'pages', '42');

        self::assertFalse($result['ok']);
        self::assertSame(428, $result['error']['status']);
        self::assertSame(LayoutSaveAdvisoryException::ERROR_CODE, $result['error']['code']);
        self::assertSame(0, $gateway->updates, 'A held edit must write nothing.');

        $required = $this->spaAdvisoryFields();
        self::assertSame(['acknowledgement', 'code', 'field', 'message', 'severity'], $required);

        foreach ($result['error']['meta']['save_advisories'] as $advisory) {
            $keys = array_keys($advisory);
            sort($keys);
            self::assertSame($required, $keys);
            self::assertSame('warning', $advisory['severity']);
            self::assertMatchesRegularExpression(
                '/' . $this->spaAcknowledgementPattern() . '/D',
                $advisory['acknowledgement'],
                'The SPA reader rejects any token it cannot match.',
            );
        }
    }

    #[Test]
    public function the_acknowledged_retry_the_spa_emits_writes_exactly_once(): void
    {
        [$host, $gateway, $fingerprint] = $this->composition(self::TOKEN);

        $held = $host->handleCommand($this->spaShapedCommand($fingerprint), 'pages', '42');
        $receipts = array_map(
            static fn(array $advisory): string => $advisory['acknowledgement'],
            $held['error']['meta']['save_advisories'],
        );

        // Exactly the received tokens, on the same command, fingerprint,
        // revision, and idempotency key — the SPA never mints its own.
        $retry = $host->handleCommand($this->spaShapedCommand($fingerprint, $receipts), 'pages', '42');

        self::assertTrue($retry['ok'], $retry['error']['detail'] ?? '');
        self::assertSame([self::TOKEN], $gateway->receipts);
        self::assertSame(1, $gateway->updates);
    }

    #[Test]
    public function a_wrong_receipt_stays_a_refusal_with_no_write(): void
    {
        [$host, $gateway, $fingerprint] = $this->composition(self::TOKEN);

        $result = $host->handleCommand(
            $this->spaShapedCommand($fingerprint, [str_repeat('f', 64)]),
            'pages',
            '42',
        );

        self::assertFalse($result['ok']);
        self::assertSame(428, $result['error']['status']);
        self::assertSame(0, $gateway->updates);
    }

    #[Test]
    public function the_spa_receipt_bound_matches_the_bound_the_host_enforces(): void
    {
        $hostBound = new \ReflectionClass(GenericPageBuilderSurfaceHost::class)
            ->getConstant('MAX_SAVE_ADVISORY_RECEIPTS');
        $reader = $this->spaSource('packages/admin/app/runtime/layoutSaveAdvisory.ts');

        self::assertSame(32, $hostBound);
        self::assertMatchesRegularExpression(
            '/MAX_ADVISORIES = ' . $hostBound . '\b/',
            $reader,
        );
    }

    /**
     * The exact body the SPA's `PageBuilderClient.command()` writes, with the
     * receipt key present only when receipts exist.
     *
     * @param list<string> $receipts
     */
    private function spaShapedCommand(string $fingerprint, array $receipts = []): PageBuilderSurfaceRequest
    {
        $payload = [
            'expected_entity_revision_id' => 7,
            'expected_document_fingerprint' => $fingerprint,
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'command' => [
                'type' => 'configure_block',
                'block_id' => 'blk_body',
                'config' => ['html' => '<p>After</p>'],
            ],
        ];
        if ($receipts !== []) {
            $payload['save_advisory_acknowledgements'] = $receipts;
        }

        return new PageBuilderSurfaceRequest(
            new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test'),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<string> */
    private function spaCommandBodyKeys(): array
    {
        $source = $this->spaSource('packages/admin/app/runtime/pageBuilderClient.ts');
        $start = strpos($source, 'admin_surface.page_builder.command');
        self::assertNotFalse($start, 'The SPA client no longer names the command route.');
        $body = substr($source, $start, 700);

        preg_match_all('/^\s{8}([a-z_]+)[:,]/m', $body, $matches);
        $keys = array_values(array_unique($matches[1]));
        preg_match('/\{ (save_advisory_acknowledgements): /', $body, $receiptKey);
        if ($receiptKey !== []) {
            $keys[] = $receiptKey[1];
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function spaAdvisoryFields(): array
    {
        $source = $this->spaSource('packages/admin/app/runtime/layoutSaveAdvisory.ts');
        preg_match('/const \{ ([^}]+) \} = candidate as Partial<AdminSurfaceSaveAdvisory>/', $source, $matches);
        self::assertNotEmpty($matches, 'The SPA advisory guard no longer destructures the projection.');
        $fields = array_map(trim(...), explode(',', $matches[1]));
        sort($fields);

        return $fields;
    }

    private function spaAcknowledgementPattern(): string
    {
        $source = $this->spaSource('packages/admin/app/runtime/layoutSaveAdvisory.ts');
        preg_match('#ACKNOWLEDGEMENT_PATTERN = /(.+)/\n#', $source, $matches);
        self::assertNotEmpty($matches, 'The SPA no longer pins an acknowledgement token shape.');

        return $matches[1];
    }

    private function spaSource(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array{0: GenericPageBuilderSurfaceHost, 1: ReviewingLayoutGateway, 2: string} */
    private function composition(?string $requiredToken = null): array
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

        $gateway = new ReviewingLayoutGateway($requiredToken);
        $gateway->seed($codec->encode($document));
        $surfaces = new PageBuilderSurfaceRegistry();
        $surfaces->register('pages', new PageBuilderSurface(
            'edit pages',
            $registry,
            new LayoutDraftManager($gateway, $codec, new LayoutValidator($registry), $editor),
            new UnusedContractPreviewGateway(),
        ));

        return [new GenericPageBuilderSurfaceHost($surfaces), $gateway, $editor->fingerprint($document)];
    }
}

/** Holds every edit for review until the exact minted receipt comes back. */
final class ReviewingLayoutGateway implements AdvisoryAwareLayoutDraftGatewayInterface
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
                'code' => 'RESERVED_ROUTE_VALUE',
                'field' => 'slug',
                'severity' => 'warning',
                'message' => 'This slug is reserved for a system route.',
                'acknowledgement' => $this->requiredToken,
            ]]);
        }

        ++$this->updates;
        $this->encoded = $encodedLayout;

        return new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}

/** Preview is not part of this contract. */
final class UnusedContractPreviewGateway implements RevisionPreviewGatewayInterface
{
    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $revisionId,
    ): RevisionPreviewGrant {
        throw new \LogicException('Preview is not exercised by this contract test.');
    }
}
