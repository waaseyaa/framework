<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use GraphQL\Type\Definition\ObjectType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\GraphQL\Schema\EntityTypeBuilder;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * R13 WP2 (audit A11, SECURITY) exploit test: the GraphQL plain-field
 * resolver built by {@see EntityTypeBuilder::buildOutputFields()} returns
 * `$data[$fieldName]` RAW for a text_long field (the defect note in the read
 * path: "the GraphQL plain-field resolver ... returns `$data[$fieldName]`
 * raw"). A GraphQL query or mutation response for a text_long field must
 * therefore be sanitized at this exact resolver, mirroring ResourceSerializer
 * -- otherwise the GraphQL consumer is a second, unpatched route to the same
 * cross-admin stored XSS that the REST read path closes.
 *
 * 'body' is registered directly as a text_long FieldDefinition (the same
 * shape a runtime/admin-created "Long text" bundle field takes -- text_long
 * is not expressible via the code-first #[Field] attribute inferrer, only
 * via a directly-constructed FieldDefinition, which is how SchemaPresenter's
 * WIDGET_MAP -> 'richtext' widget path is actually reached in production).
 *
 * Pre-fix: RED (the payload passes through the resolver untouched).
 * Post-fix: the resolver strips <script> / event-handler markup while
 * leaving safe markup and Indigenous-language text untouched.
 */
#[CoversClass(EntityTypeBuilder::class)]
final class EntityTypeBuilderRichTextSanitizationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        SchemaFactory::resetCache();

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerCoreEntityType(TestEntityType::stub(
            'article',
            [
                'id' => new FieldDefinition(name: 'id', type: 'integer'),
                'uuid' => new FieldDefinition(name: 'uuid', type: 'string'),
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                'body' => new FieldDefinition(name: 'body', type: 'text_long'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));
    }

    protected function tearDown(): void
    {
        // This test populates the static SchemaFactory cache under the
        // "article" entity-type-id key with a minimal schema. Clear it so the
        // cached shape never leaks into a later test that registers a richer
        // "article" type (e.g. SchemaValidationTest's ArticleSchemaFixture) and
        // then reads a stale cached schema.
        SchemaFactory::resetCache();
    }

    private function bodyFieldResolver(): callable
    {
        $factory = new SchemaFactory(entityTypeManager: $this->entityTypeManager);
        $schema = $factory->build();
        $queryType = $schema->getQueryType();
        self::assertNotNull($queryType);

        $field = $queryType->getField('article');
        $type = $field->getType();
        self::assertInstanceOf(ObjectType::class, $type);

        $bodyField = $type->getField('body');
        self::assertNotNull($bodyField->resolveFn, 'The body field must declare a resolver.');

        return $bodyField->resolveFn;
    }

    #[Test]
    public function scriptTagIsStrippedFromResolvedValue(): void
    {
        $resolve = $this->bodyFieldResolver();

        $data = ['body' => '<p>hi</p><script>alert(document.cookie)</script>'];
        $result = $resolve($data);

        self::assertIsString($result);
        self::assertStringNotContainsString(
            '<script',
            $result,
            'The EntityTypeBuilder plain-field resolver must sanitize a text_long value before '
            . 'GraphQL serves it -- this is the GraphQL route to the cross-admin stored XSS sink.',
        );
        self::assertStringContainsString('<p>hi</p>', $result);
    }

    #[Test]
    public function onerrorAttributeIsStrippedFromResolvedValue(): void
    {
        $resolve = $this->bodyFieldResolver();

        $data = ['body' => '<img src=x onerror=alert(1)>'];
        $result = $resolve($data);

        self::assertIsString($result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringNotContainsString('alert(1)', $result);
    }

    #[Test]
    public function safeMarkupAndIndigenousOrthographySurviveResolution(): void
    {
        $resolve = $this->bodyFieldResolver();

        $payload = "<p>Aaniin, Anishinaabemowin: \u{1401}\u{1489}\u{1591}\u{140b}"
            . "\u{1490}\u{140e}\u{1360}\u{1490}\u{1370}\u{1550}\u{1400}\u{140d}, "
            . "gichi-mookomaan, macron \u{101}, o'ow, nake'</p><script>alert(1)</script>";

        $result = $resolve(['body' => $payload]);
        self::assertIsString($result);

        $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertStringContainsString('Aaniin, Anishinaabemowin:', $decoded);
        self::assertStringContainsString("\u{1401}\u{1489}\u{1591}\u{140b}", $decoded, 'Syllabics must survive.');
        self::assertStringContainsString('gichi-mookomaan', $decoded, 'Double vowels must survive.');
        self::assertStringContainsString("\u{101}", $decoded, 'Macron must survive.');
        self::assertStringContainsString("o'ow", $decoded, 'Glottal-stop apostrophe must survive.');
        self::assertStringContainsString("nake'", $decoded, 'Glottal-stop apostrophe must survive.');
        self::assertStringContainsString('<p>', $result, 'The safe <p> wrapper must survive.');
        self::assertStringNotContainsString('<script', $result);
    }
}
