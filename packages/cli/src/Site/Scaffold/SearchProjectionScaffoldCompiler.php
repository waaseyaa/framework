<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Scaffold;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

/**
 * The pure search-projection scaffold compiler (#2849, ADR-025 D-6.1).
 *
 * It is a function of its validated input plus its own version: no
 * filesystem observation, no project reference, no clock. Two runs on the
 * same input produce byte-identical plans, which is what makes the plan
 * reviewable and what lets `make:search-projection` hand publication to the
 * shared execution authority instead of writing files and rewriting
 * `composer.json` itself. It exactly mirrors {@see ContentTypeScaffoldCompiler},
 * scaffolding an application-owned `EntitySearchProjectorInterface`
 * implementation for an entity type the developer already has rather than
 * scaffolding the entity type itself.
 *
 * The unit is **seeded** (D-2.2): a scaffold is published exactly once and is
 * then the developer's to edit, so the authority never re-renders it. Its set
 * evolution stays `Frozen` — a scaffold that later wanted to add a path would
 * be asking to own bytes its developer already owns. The provider
 * registration travels as a plan-borne merge instruction (D-6.6), so it
 * lands inside the same transaction as the three files and preserves the
 * application's own `composer.json` bytes.
 *
 * @api
 */
final readonly class SearchProjectionScaffoldCompiler
{
    public const int GENERATOR_VERSION = 1;

    /** Every scaffolded search projector owns one unit under this namespace. */
    public const string UNIT_PREFIX = 'scaffold:search-projection';

    /** The D-2.1 unit-id grammar one colon-separated segment must satisfy. */
    private const string UNIT_SEGMENT_GRAMMAR = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * @param string $entityTypeId the validated existing entity type id to project
     * @param string $className the validated PascalCase class base derived from $entityTypeId
     * @param list<string> $bodyFields validated snake_case field names, non-empty
     */
    public function compile(string $entityTypeId, string $className, array $bodyFields): ArtifactPlan
    {
        if ($entityTypeId === '' || $className === '' || $bodyFields === []) {
            throw new \InvalidArgumentException('A search-projection scaffold requires an entity type id, a class name and at least one body field.');
        }

        $providerClass = $className . 'SearchServiceProvider';
        $projectorClass = $className . 'SearchProjector';
        $testClass = $className . 'SearchProjectorTest';

        return new ArtifactPlan(
            self::class,
            self::GENERATOR_VERSION,
            self::unitId($entityTypeId),
            GenerationUnitDisposition::Seeded,
            self::inputDigest($entityTypeId, $bodyFields),
            [
                new GeneratedArtifact(
                    'src/Provider/' . $providerClass . '.php',
                    $this->renderProvider($providerClass, $projectorClass, $className),
                ),
                new GeneratedArtifact(
                    'src/Search/' . $projectorClass . '.php',
                    $this->renderProjector($projectorClass, $entityTypeId, $bodyFields),
                ),
                new GeneratedArtifact(
                    'tests/Search/' . $testClass . '.php',
                    $this->renderTest($testClass, $projectorClass, $className, $entityTypeId, $bodyFields),
                ),
            ],
            registrations: [new ComposerProviderRegistration('App\\Provider\\' . $providerClass)],
            companionTests: ['tests/Search/' . $testClass . '.php'],
        );
    }

    /**
     * A unit id is an ownership key, not display text, and D-2.1's grammar is
     * ASCII. An entity type id may be Indigenous orthography — syllabics or
     * diacritics the charter forbids transliterating — so an id the grammar
     * cannot spell is addressed by a stable digest of itself instead. The
     * orthography stays verbatim where it is read: the class name and the
     * generated file paths.
     */
    public static function unitId(string $entityTypeId): string
    {
        $slug = strtr($entityTypeId, '_', '-');

        return preg_match(self::UNIT_SEGMENT_GRAMMAR, $slug) === 1
            ? self::UNIT_PREFIX . ':' . $slug
            : self::UNIT_PREFIX . ':x' . substr(hash('sha256', $entityTypeId), 0, 32);
    }

    /** @param list<string> $bodyFields */
    private static function inputDigest(string $entityTypeId, array $bodyFields): string
    {
        return hash('sha256', CanonicalJson::encode(['entity_type_id' => $entityTypeId, 'body_fields' => $bodyFields]) . "\n");
    }

    /**
     * @param list<string> $bodyFields
     */
    private function renderProjector(string $projectorClass, string $entityTypeId, array $bodyFields): string
    {
        // The handler has already identifier-validated the entity type id and
        // each body-field name. Escape them again before they land in
        // single-quoted PHP literals — escape-at-the-sink, independent of
        // upstream validation.
        $safeEntityTypeId = addslashes($entityTypeId);
        $fieldLiterals = implode(', ', array_map(
            static fn(string $field): string => "'" . addslashes($field) . "'",
            $bodyFields,
        ));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Search;

            use Waaseyaa\\Entity\\EntityInterface;
            use Waaseyaa\\Entity\\Exception\\FieldReadDenied;
            use Waaseyaa\\Entity\\Exception\\MissingFieldReadContext;
            use Waaseyaa\\Search\\Document\\SearchDocument;
            use Waaseyaa\\Search\\Projection\\EntitySearchDocumentId;
            use Waaseyaa\\Search\\Projection\\EntitySearchProjectorInterface;
            use Waaseyaa\\Search\\Projection\\SearchTextNormalizer;
            use Waaseyaa\\Search\\SearchIndexableInterface;

            /**
             * Reads go through the guarded accessor so index-time projection (no
             * account scope) can only release {@see \\Waaseyaa\\Entity\\FieldReadLevel::Public}
             * fields; a per-field denial omits that field's text and keeps the
             * entity findable by its readable fields. Never bypass the accessor.
             */
            final class {$projectorClass} implements EntitySearchProjectorInterface
            {
                private const string ENTITY_TYPE_ID = '{$safeEntityTypeId}';

                /** @var list<string> */
                private const array BODY_FIELDS = [{$fieldLiterals}];

                public function supports(EntityInterface \$entity): bool
                {
                    return \$entity->getEntityTypeId() === self::ENTITY_TYPE_ID
                        && EntitySearchDocumentId::fromEntity(\$entity) !== null;
                }

                public function project(EntityInterface \$entity): ?SearchIndexableInterface
                {
                    \$documentId = EntitySearchDocumentId::fromEntity(\$entity);
                    if (\$documentId === null || \$entity->getEntityTypeId() !== self::ENTITY_TYPE_ID) {
                        return null;
                    }

                    \$bodyParts = [];
                    foreach (self::BODY_FIELDS as \$field) {
                        \$part = SearchTextNormalizer::normalize(\$this->guardedValue(\$entity, \$field));
                        if (\$part !== '') {
                            \$bodyParts[] = \$part;
                        }
                    }

                    return new SearchDocument(
                        id: \$documentId,
                        title: SearchTextNormalizer::normalize(\$this->guardedLabel(\$entity)),
                        body: implode(' ', \$bodyParts),
                        metadata: ['entity_type' => self::ENTITY_TYPE_ID, 'content_type' => \$entity->bundle()],
                    );
                }

                private function guardedLabel(EntityInterface \$entity): string
                {
                    try {
                        return \$entity->label();
                    } catch (FieldReadDenied|MissingFieldReadContext) {
                        return '';
                    }
                }

                private function guardedValue(EntityInterface \$entity, string \$field): mixed
                {
                    try {
                        return \$entity->get(\$field);
                    } catch (FieldReadDenied|MissingFieldReadContext) {
                        return null;
                    }
                }
            }

            PHP;
    }

    private function renderProvider(string $providerClass, string $projectorClass, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Provider;

            use App\\Search\\{$projectorClass};
            use Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider;
            use Waaseyaa\\Search\\ProvidesEntitySearchProjectorsInterface;
            use Waaseyaa\\Search\\Projection\\EntitySearchProjectorInterface;

            /**
             * The container resolves ONE {@see ProvidesEntitySearchProjectorsInterface},
             * so an application has a single search-projector provider. Additional
             * projectors belong in this class's {@see self::entitySearchProjectors()}
             * list, not in a second provider.
             */
            final class {$providerClass} extends ServiceProvider implements ProvidesEntitySearchProjectorsInterface
            {
                public function register(): void
                {
                    \$this->singleton(
                        ProvidesEntitySearchProjectorsInterface::class,
                        fn (): ProvidesEntitySearchProjectorsInterface => \$this,
                    );
                }

                /** @return list<EntitySearchProjectorInterface> */
                public function entitySearchProjectors(): array
                {
                    return [new {$projectorClass}()];
                }
            }

            PHP;
    }

    /** @param list<string> $bodyFields */
    private function renderTest(string $testClass, string $projectorClass, string $className, string $entityTypeId, array $bodyFields): string
    {
        $safeEntityTypeId = addslashes($entityTypeId);
        $firstBodyField = addslashes($bodyFields[0]);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Search;

            use App\\Entity\\{$className};
            use App\\Search\\{$projectorClass};
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Entity\\ContentEntityBase;

            final class {$testClass} extends TestCase
            {
                #[Test]
                public function it_projects_the_entity_into_a_search_document(): void
                {
                    \$projector = new {$projectorClass}();
                    \$entity = new {$className}(['id' => 1, 'title' => 'Example title', '{$firstBodyField}' => 'Example body']);

                    self::assertTrue(\$projector->supports(\$entity));

                    \$document = \$projector->project(\$entity);
                    self::assertNotNull(\$document);
                    self::assertSame('{$safeEntityTypeId}:1', \$document->getSearchDocumentId());
                    self::assertNotSame(
                        '',
                        \$document->toSearchDocument()['body'],
                        'The projected body is empty: every indexed field is currently unreadable at index time. A registered entity type defaults undeclared fields to FieldReadLevel::Internal, and Internal/Protected fields are deliberately withheld from the index. Decide per field: declare read: FieldReadLevel::Public on the #[Field] attribute of content genuinely meant to be searchable, or leave the field restricted and stop indexing it. Do not widen a field just to make this pass.',
                    );
                }

                #[Test]
                public function an_entity_of_another_type_is_not_supported(): void
                {
                    \$projector = new {$projectorClass}();
                    \$other = new class (['id' => 1, 'title' => 'x'], '{$safeEntityTypeId}_other') extends ContentEntityBase {
                        public function __construct(array \$values, string \$entityTypeId)
                        {
                            parent::__construct(\$values, \$entityTypeId, ['id' => 'id', 'label' => 'title']);
                        }
                    };

                    self::assertFalse(\$projector->supports(\$other));
                }
            }

            PHP;
    }
}
