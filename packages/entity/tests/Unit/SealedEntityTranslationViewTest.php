<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\InternalFieldArrayExportDenied;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Hydration\FallbackChainResolver;

final class SealedEntityTranslationViewTest extends TestCase
{
    protected function tearDown(): void
    {
        ContentEntityBase::setEntityTypeManager(null);
    }

    #[Test]
    public function translation_and_fallback_reads_use_reissued_view_identities_without_array_export(): void
    {
        $manager = new EntityTypeManager($this->createStub(EventDispatcherInterface::class));
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: SealedTranslatableFixture::class,
            keys: ['id' => 'id', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            translatable: true,
            _fieldDefinitions: ['title' => ['type' => 'string', 'translatable' => true]],
        ));
        ContentEntityBase::setEntityTypeManager($manager);

        $guard = new TranslationViewRecordingGuard();
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: [
                'id' => 7,
                'langcode' => 'en',
                'default_langcode' => 'en',
                'mail' => 'member@example.test',
            ],
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'langcode' => FieldReadLevel::Public,
                'default_langcode' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Protected,
                'mail' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure(
                entityTypeId: 'article',
                bundleId: 'article',
                id: 7,
                uuid: null,
                activeLanguageId: 'en',
                defaultLanguageId: 'en',
                knownTranslationIds: ['en', 'fr'],
                fieldNames: ['id', 'langcode', 'default_langcode', 'title', 'mail'],
            ),
            entityTypeId: 'article',
            entityKeys: ['id' => 'id', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            guard: $guard,
        );
        $entity = $boundary->installer()->instantiate(SealedTranslatableFixture::class, $payload);
        self::assertInstanceOf(SealedTranslatableFixture::class, $entity);
        $entity->_setTranslationData([
            'en' => ['title' => 'English title'],
            'fr' => ['title' => null],
        ], 'en');

        $arrayExportDeniedInResolver = false;
        $entity->_setFallbackResolver(new FallbackChainResolver(
            static function (string $requested, EntityInterface $view) use (&$arrayExportDeniedInResolver): array {
                try {
                    $view->toArray();
                } catch (InternalFieldArrayExportDenied) {
                    $arrayExportDeniedInResolver = true;
                }

                return [$requested, 'en'];
            },
        ));

        self::assertSame('English title', $entity->getTranslation('fr')->get('title'));
        self::assertSame('English title', $entity->getTranslation('fr')->get('title'));
        self::assertTrue($arrayExportDeniedInResolver);
        self::assertCount(4, array_unique($guard->viewIds));
    }
}

final class SealedTranslatableFixture extends ContentEntityBase {}

final class TranslationViewRecordingGuard implements EntityValueReadGuardInterface
{
    /** @var list<int> */
    public array $viewIds = [];

    public function assertProtectedReadable(EntityBase $entity, string $field, object $viewIdentity): void
    {
        $this->viewIds[] = spl_object_id($viewIdentity);
    }

    public function invalidate(EntityBase $entity): void {}
}
