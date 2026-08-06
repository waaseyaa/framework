<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Testing\Factory\EntityTypeFactory;

/**
 * R7 WP1 exploit-closed regression.
 *
 * Before the fix, {@see EntityAccessHandler} had no way to gate an entity's
 * LABEL/TITLE independently of {@see EntityInterface::label()} — every SSR
 * consumer (HTML `<title>`, schema.org JSON-LD, Markdown H1) read the raw
 * label directly, bypassing field-level access entirely. A viewable entity
 * (entity-level access allowed) whose label-key field was field-access-
 * Forbidden still leaked its title through all three surfaces.
 *
 * {@see EntityAccessHandler::viewableLabel()} closes this: it resolves the
 * entity type's `label` entity-key field name and runs the SAME
 * {@see EntityAccessHandler::checkFieldAccess()} the fields bag uses, so a
 * label field is gated identically to any other field.
 */
#[CoversClass(EntityAccessHandler::class)]
final class EntityAccessHandlerLabelAccessTest extends TestCase
{
    private function entityTypeManager(): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(EntityTypeFactory::create(
            'article',
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        return $manager;
    }

    private function entity(string $label = 'My Title'): EntityInterface
    {
        return new class (['id' => 1, 'uuid' => 'u-1', 'title' => $label], 'article', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title']) extends EntityBase {};
    }

    private function account(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    private function forbidLabelFieldHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('viewable entity, restricted label field');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'article';
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return $fieldName === 'title' && $operation === 'view'
                        ? AccessResult::forbidden('label field is restricted')
                        : AccessResult::neutral();
                }
            },
        ]);
    }

    #[Test]
    public function returns_null_when_the_label_key_field_is_forbidden(): void
    {
        $handler = $this->forbidLabelFieldHandler();

        $result = $handler->viewableLabel($this->entity(), $this->account(), $this->entityTypeManager());

        self::assertNull($result);
    }

    #[Test]
    public function returns_the_real_label_when_no_policy_restricts_it(): void
    {
        // Positive control: open-by-default — a label field with no
        // opinionated policy (Neutral) is shown.
        $handler = new EntityAccessHandler([]);

        $result = $handler->viewableLabel($this->entity('Public Title'), $this->account(), $this->entityTypeManager());

        self::assertSame('Public Title', $result);
    }

    #[Test]
    public function returns_the_label_unchanged_when_the_entity_type_has_no_label_key(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(EntityTypeFactory::create('widget', keys: ['id' => 'id']));

        $entity = new class (['id' => 5], 'widget', ['id' => 'id']) extends EntityBase {};
        $handler = $this->forbidLabelFieldHandler();

        $result = $handler->viewableLabel($entity, $this->account(), $manager);

        self::assertSame($entity->label(), $result);
    }

    #[Test]
    public function returns_the_label_unchanged_when_the_entity_type_is_unregistered(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $entity = $this->entity('Unregistered Type Title');
        $handler = $this->forbidLabelFieldHandler();

        $result = $handler->viewableLabel($entity, $this->account(), $manager);

        self::assertSame('Unregistered Type Title', $result);
    }
}
