<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Type;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\FieldValueCanonicalizer;
use Waaseyaa\Entity\Validation\FieldDefinitionConstraintBuilder;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Node\Node;
use Waaseyaa\User\User;

/** Definition/write/validate contract gate for #2064's canonical bool shape. */
#[CoversNothing]
final class BooleanFieldCanonicalizationArchitectureTest extends TestCase
{
    /** @return list<class-string> */
    public static function firstPartyBooleanEntityClasses(): array
    {
        return [
            \Waaseyaa\AI\Agent\Entity\AgentAuditLog::class,
            \Waaseyaa\Attachment\Attachment::class,
            \Waaseyaa\Engagement\Comment::class,
            \Waaseyaa\Genealogy\Entity\GenealogyEvent::class,
            \Waaseyaa\Genealogy\Entity\GenealogyFamily::class,
            \Waaseyaa\Genealogy\Entity\GenealogyPerson::class,
            \Waaseyaa\Genealogy\Entity\GenealogyTree::class,
            \Waaseyaa\Media\Media::class,
            \Waaseyaa\Menu\MenuLink::class,
            \Waaseyaa\Messaging\ThreadMessage::class,
            Node::class,
            \Waaseyaa\Oidc\Entity\OidcClient::class,
            \Waaseyaa\Path\PathAlias::class,
            \Waaseyaa\Relationship\Relationship::class,
            \Waaseyaa\Taxonomy\Term::class,
            User::class,
        ];
    }

    #[Test]
    public function firstPartyBooleanDefinitionsNeverDeclareIntegerDefaults(): void
    {
        $audited = [];
        foreach (self::firstPartyBooleanEntityClasses() as $class) {
            foreach (EntityType::fromClass($class)->getFieldDefinitions() as $name => $definition) {
                if (!in_array(strtolower($definition->getType()), ['bool', 'boolean'], true)) {
                    continue;
                }
                $audited[] = EntityType::fromClass($class)->id() . '.' . $name;
                $default = $definition->getDefaultValue();
                self::assertTrue(
                    $default === null || is_bool($default),
                    sprintf('%s boolean definition default must be bool|null, got %s.', end($audited), get_debug_type($default)),
                );
            }
        }

        sort($audited);
        self::assertContains('node.promote', $audited);
        self::assertContains('user.email_verified', $audited);
        self::assertContains('user.status', $audited);
    }

    #[Test]
    public function compiledDefinitionWriteAndValidationPathsShareNativeBool(): void
    {
        $type = EntityType::fromClass(Node::class);
        $layout = EntityReadRuntime::layoutFor(
            Node::class,
            ['type' => 'article', 'promote' => 1],
            $type->id(),
            $type->getKeys(),
            registeredEntityType: true,
            entityTypeDefinitions: $type->getFieldDefinitions(),
        );

        self::assertTrue($layout->isBooleanField('promote'));
        self::assertSame(true, $layout->canonicalize('promote', 1));
        self::assertSame(false, FieldValueCanonicalizer::forType('boolean', 0));

        $node = new Node(['type' => 'article', 'promote' => 1]);
        self::assertSame(true, $node->get('promote'));
        $node->set('promote', 0);
        self::assertSame(false, $node->get('promote'));

        $constraints = FieldDefinitionConstraintBuilder::build([
            'promote' => $type->getFieldDefinitions()['promote'],
        ]);
        $typeConstraints = array_values(array_filter(
            $constraints['promote'],
            static fn(object $constraint): bool => $constraint instanceof Type,
        ));
        self::assertCount(1, $typeConstraints);
        self::assertSame('bool', $typeConstraints[0]->type);
    }

    #[Test]
    public function canonicalizationDoesNotWeakenProtectedMissingContextDenial(): void
    {
        EntityReadRuntime::installGuard(null);
        $user = new User(['status' => 1]);

        $this->expectException(MissingFieldReadContext::class);
        $user->get('status');
    }

    #[Test]
    public function effectiveDefinitionOverlayDrivesBooleanCanonicalization(): void
    {
        $type = EntityType::fromClass(BooleanCanonicalizationOverlayFixture::class);
        $definitions = $type->getFieldDefinitions();
        $definitions['flag'] = new FieldDefinition(
            name: 'flag',
            type: 'boolean',
            read: FieldReadLevel::Public,
        );

        $layout = EntityReadRuntime::layoutFor(
            BooleanCanonicalizationOverlayFixture::class,
            ['flag' => 1],
            $type->id(),
            $type->getKeys(),
            registeredEntityType: true,
            entityTypeDefinitions: $definitions,
        );

        self::assertTrue($layout->isBooleanField('flag'));
        self::assertSame(true, $layout->canonicalize('flag', 1));
    }
}

#[\Waaseyaa\Entity\Attribute\ContentEntityType(id: 'boolean_overlay_fixture')]
#[\Waaseyaa\Entity\Attribute\ContentEntityKeys(label: 'label')]
final class BooleanCanonicalizationOverlayFixture extends \Waaseyaa\Entity\ContentEntityBase
{
    #[\Waaseyaa\Entity\Attribute\Field(type: 'string', read: FieldReadLevel::Public)]
    public string $flag = '';
}
