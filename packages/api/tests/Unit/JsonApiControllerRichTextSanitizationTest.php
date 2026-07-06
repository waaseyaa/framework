<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;

/**
 * Exploit tests for R13 WP2 (audit A11, SECURITY): a text_long field is never
 * sanitized server-side. An authenticated content author can save markup
 * containing <script> / event-handler attributes via the JSON:API write path
 * (JsonApiController::store/update). The write path persists it verbatim
 * (correct, non-lossy) but the READ path (ResourceSerializer, reached both
 * by the write-echo response and by every later show()/index()) MUST
 * neutralize it before it reaches a consumer, because the admin SPA renders
 * text_long via v-html (SchemaView.vue) -- raw markup surviving to that
 * boundary is a cross-admin stored XSS.
 *
 * Pre-fix: every assertion in this file is RED (the payload round-trips
 * untouched). Post-fix: markup is neutralized at every read while the
 * STORED value is left byte-for-byte as authored (non-lossy at rest).
 *
 * #[CoversNothing]: this is a chokepoint/boundary test across
 * JsonApiController, ResourceSerializer, and the in-memory storage fixture,
 * not a single-unit test.
 */
#[CoversNothing]
final class JsonApiControllerRichTextSanitizationTest extends TestCase
{
    private const SCRIPT_PAYLOAD = '<p>hi</p><script>alert(document.cookie)</script>';
    private const IMG_ONERROR_PAYLOAD = '<img src=x onerror=alert(1)>';

    /**
     * Anishinaabemowin sample: double vowels, glottal stop (apostrophe), and
     * syllabics, wrapped in safe markup alongside a <script> payload in the
     * SAME field value. Used to prove the sanitizer is non-lossy on
     * legitimate Indigenous-language content while still stripping the
     * unsafe markup in the same string.
     */
    private const ORTHOGRAPHY_PAYLOAD =
        "<p>Aaniin, Anishinaabemowin: \u{1401}\u{1489}\u{1591}\u{140b}\u{1490}\u{140e}\u{1360}"
        . "\u{1490}\u{1370}\u{1550}\u{1400}\u{140d}, gichi-mookomaan, macron \u{101}, o'ow, nake'</p>"
        . '<script>alert(1)</script>';

    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );

        // 'body' is explicitly declared text_long (the HTML-bearing richtext
        // type per SchemaPresenter::WIDGET_MAP -> 'richtext' widget -> v-html
        // in SchemaView.vue). This is the exact type the defect targets.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'body' => new FieldDefinition(name: 'body', type: 'text_long'),
            ],
        ));

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
        );
    }

    // --- 1a: write path (store) then read back through ResourceSerializer ---

    #[Test]
    public function storeThenReadBackNeutralizesScriptTag(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'New Article',
                    'body' => self::SCRIPT_PAYLOAD,
                ],
            ],
        ]);
        $array = $doc->toArray();
        $served = $array['data']['attributes']['body'];

        $this->assertStringNotContainsString(
            '<script',
            $served,
            'A <script> tag saved via JsonApiController::store must be stripped before it is served '
            . 'back through ResourceSerializer -- this is the cross-admin stored XSS sink.',
        );
        $this->assertStringNotContainsString('alert(document.cookie)', $served);
        // Safe content around the payload must survive.
        $this->assertStringContainsString('<p>hi</p>', $served);
    }

    #[Test]
    public function storeThenReadBackNeutralizesOnerrorAttribute(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'New Article',
                    'body' => self::IMG_ONERROR_PAYLOAD,
                ],
            ],
        ]);
        $served = $doc->toArray()['data']['attributes']['body'];

        $this->assertStringNotContainsString(
            'onerror',
            $served,
            'An onerror event-handler attribute saved via JsonApiController::store must be stripped '
            . 'before being served back.',
        );
        $this->assertStringNotContainsString('alert(1)', $served);
    }

    #[Test]
    public function updateThenReadBackNeutralizesScriptTag(): void
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create(['title' => 'Original', 'body' => 'Original body.']);
        $this->storage->save($entity);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => [
                    'body' => self::SCRIPT_PAYLOAD,
                ],
            ],
        ]);
        $served = $doc->toArray()['data']['attributes']['body'];

        $this->assertStringNotContainsString(
            '<script',
            $served,
            'JsonApiController::update (PATCH) must also serve the field through the sanitizing '
            . 'ResourceSerializer read boundary, not just store().',
        );
    }

    // --- 1e: non-lossy at rest -- stored bytes are untouched, only served bytes are sanitized ---

    #[Test]
    public function storedValueIsUnchangedWhileServedValueIsSanitized(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'New Article',
                    'body' => self::SCRIPT_PAYLOAD,
                ],
            ],
        ]);
        $servedOnCreate = $doc->toArray()['data']['attributes']['body'];
        $id = $doc->toArray()['data']['id'];

        // Load the RAW stored entity directly from storage, bypassing the
        // serializer entirely. The stored bytes must be exactly what the
        // author submitted -- sanitization is a read/serialization-boundary
        // concern, not a write-time mutation (non-lossy storage posture).
        $entity = $this->storage->loadByKey('uuid', $id);
        $this->assertNotNull($entity);
        $this->assertSame(
            self::SCRIPT_PAYLOAD,
            $entity->get('body'),
            'The entity as persisted in storage must retain the raw author input unchanged '
            . '(non-lossy at rest) -- only the served/serialized value may be sanitized.',
        );

        // A second, independent read through show() must ALSO be sanitized
        // (proves sanitization happens on every read, not just the write-echo).
        $reReadDoc = $this->controller->show('article', $entity->id());
        $servedOnShow = $reReadDoc->toArray()['data']['attributes']['body'];

        $this->assertStringNotContainsString('<script', $servedOnCreate);
        $this->assertStringNotContainsString('<script', $servedOnShow);
    }

    // --- 1d: orthography preservation ---

    #[Test]
    public function indigenousOrthographySurvivesSanitizationWhileScriptIsStripped(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'Orthography',
                    'body' => self::ORTHOGRAPHY_PAYLOAD,
                ],
            ],
        ]);
        $served = $doc->toArray()['data']['attributes']['body'];

        // Normalize HTML entity-encoding (a safe, non-lossy, round-trippable
        // transform the sanitizer applies to text nodes) before comparing
        // full text content.
        $decoded = html_entity_decode($served, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Aaniin, Anishinaabemowin:', $decoded);
        $this->assertStringContainsString("\u{1401}\u{1489}\u{1591}\u{140b}", $decoded, 'Syllabics must survive.');
        $this->assertStringContainsString('gichi-mookomaan', $decoded, 'Double vowels must survive.');
        $this->assertStringContainsString("\u{101}", $decoded, 'Macron must survive.');
        $this->assertStringContainsString("o'ow", $decoded, 'Glottal-stop apostrophe must survive.');
        $this->assertStringContainsString("nake'", $decoded, 'Glottal-stop apostrophe must survive.');
        $this->assertStringContainsString('<p>', $served, 'The safe <p> wrapper must survive.');

        $this->assertStringNotContainsString(
            '<script',
            $served,
            'A <script> tag in the SAME value as legitimate Indigenous-language content must still be removed.',
        );
        $this->assertStringNotContainsString('alert(1)', $served);
    }
}
