<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldDefinitionRegistry;

#[CoversClass(FieldDefinitionRegistry::class)]
final class FieldDefinitionRegistryTest extends TestCase
{
    #[Test]
    public function registersAndRetrievesBundleFields(): void
    {
        $registry = new FieldDefinitionRegistry();
        $email = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );

        $registry->registerBundleFields('group', 'business', ['email' => $email]);

        $fields = $registry->bundleFieldsFor('group', 'business');
        self::assertArrayHasKey('email', $fields);
        self::assertSame($email, $fields['email']);
    }

    #[Test]
    public function coreFieldsSynthesizedToFieldDefinitionObjects(): void
    {
        $registry = new FieldDefinitionRegistry();
        $meta = [
            'label' => ['type' => 'string', 'required' => true, 'label' => 'Label'],
            'age' => ['type' => 'integer', 'weight' => 5, 'default' => 0],
        ];

        $registry->registerCoreFields('group', $meta);
        $fields = $registry->coreFieldsFor('group');

        self::assertArrayHasKey('label', $fields);
        self::assertInstanceOf(FieldDefinitionInterface::class, $fields['label']);
        self::assertSame('label', $fields['label']->getName());
        self::assertSame('string', $fields['label']->getType());
        self::assertTrue($fields['label']->isRequired());
        self::assertSame('Label', $fields['label']->getLabel());
        self::assertSame('group', $fields['label']->getTargetEntityTypeId());
        self::assertNull($fields['label']->getTargetBundle());

        self::assertSame('integer', $fields['age']->getType());
        self::assertSame(0, $fields['age']->getDefaultValue());
        // Unknown metadata keys surface in settings.
        self::assertSame(5, $fields['age']->getSetting('weight'));
    }

    #[Test]
    public function corePreConstructedFieldDefinitionsPassThrough(): void
    {
        $registry = new FieldDefinitionRegistry();
        $label = new FieldDefinition(
            name: 'label',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: null,
        );

        $registry->registerCoreFields('group', ['label' => $label]);

        self::assertSame($label, $registry->coreFieldsFor('group')['label']);
    }

    #[Test]
    public function bundleFieldsEmptyForUnregisteredEntityType(): void
    {
        $registry = new FieldDefinitionRegistry();

        self::assertSame([], $registry->bundleFieldsFor('group', 'business'));
    }

    #[Test]
    public function bundleFieldsEmptyForUnknownBundleOnRegisteredEntityType(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('group', []);

        self::assertSame([], $registry->bundleFieldsFor('group', 'unknown'));
    }

    #[Test]
    public function mismatchedTargetEntityTypeIdThrows(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'node',
            targetBundle: 'business',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetEntityTypeId "node"');

        $registry->registerBundleFields('group', 'business', [$field]);
    }

    #[Test]
    public function mismatchedTargetBundleThrows(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'organization',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetBundle "organization"');

        $registry->registerBundleFields('group', 'business', [$field]);
    }

    #[Test]
    public function nullTargetBundleOnBundleRegistrationThrows(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetBundle "(null)"');

        $registry->registerBundleFields('group', 'business', [$field]);
    }

    #[Test]
    public function nonFieldDefinitionEntryThrows(): void
    {
        $registry = new FieldDefinitionRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FieldDefinitionInterface');

        $registry->registerBundleFields('group', 'business', [new \stdClass()]);
    }

    #[Test]
    public function coreBundleCollisionThrowsWithSpecifiedMessage(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('group', ['status' => ['type' => 'boolean']]);

        $bundleStatus = new FieldDefinition(
            name: 'status',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Field "status" on entity type "group" bundle "business" collides with core field "status" on entity type "group".',
        );

        $registry->registerBundleFields('group', 'business', ['status' => $bundleStatus]);
    }

    #[Test]
    public function duplicateNameWithinSameRegistrationThrows(): void
    {
        $registry = new FieldDefinitionRegistry();
        $first = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );
        $second = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate bundle field "email"');

        $registry->registerBundleFields('group', 'business', [$first, $second]);
    }

    #[Test]
    public function duplicateAcrossTwoRegistrationsSameBundleThrows(): void
    {
        $registry = new FieldDefinitionRegistry();
        $email = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );
        $registry->registerBundleFields('group', 'business', [$email]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $registry->registerBundleFields('group', 'business', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
        ]);
    }

    #[Test]
    public function sameNameAcrossDifferentBundlesIsAllowed(): void
    {
        $registry = new FieldDefinitionRegistry();
        $businessEmail = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'business',
        );
        $orgEmail = new FieldDefinition(
            name: 'email',
            type: 'string',
            targetEntityTypeId: 'group',
            targetBundle: 'organization',
        );

        $registry->registerBundleFields('group', 'business', [$businessEmail]);
        $registry->registerBundleFields('group', 'organization', [$orgEmail]);

        self::assertSame($businessEmail, $registry->bundleFieldsFor('group', 'business')['email']);
        self::assertSame($orgEmail, $registry->bundleFieldsFor('group', 'organization')['email']);
    }

    #[Test]
    public function bundlesDefiningFieldEnumeratesEveryBundleWithThatName(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('group', 'business', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
            new FieldDefinition(
                name: 'phone',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
        ]);
        $registry->registerBundleFields('group', 'organization', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
            new FieldDefinition(
                name: 'org_code',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
        ]);

        self::assertEqualsCanonicalizing(
            ['business', 'organization'],
            $registry->bundlesDefiningField('group', 'email'),
        );
        self::assertSame(['business'], $registry->bundlesDefiningField('group', 'phone'));
        self::assertSame(['organization'], $registry->bundlesDefiningField('group', 'org_code'));
        self::assertSame([], $registry->bundlesDefiningField('group', 'nonexistent'));
        self::assertSame([], $registry->bundlesDefiningField('other_type', 'email'));
    }

    #[Test]
    public function mergeCoreFields_appends_without_replacing_existing(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', ['title' => ['type' => 'string', 'label' => 'Title']]);
        $registry->mergeCoreFields('node', [
            'subtitle' => new FieldDefinition(
                name: 'subtitle',
                type: 'string',
                targetEntityTypeId: 'node',
                label: 'Subtitle',
            ),
        ]);

        $core = $registry->coreFieldsFor('node');
        self::assertArrayHasKey('title', $core);
        self::assertArrayHasKey('subtitle', $core);
        self::assertSame('subtitle', $core['subtitle']->getName());
    }

    #[Test]
    public function mergeCoreFields_rejects_duplicate_names(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', ['title' => ['type' => 'string', 'label' => 'Title']]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->mergeCoreFields('node', [
            'title' => new FieldDefinition(
                name: 'title',
                type: 'string',
                targetEntityTypeId: 'node',
                label: 'Other',
            ),
        ]);
    }

    #[Test]
    public function bundleUniqueKeysRequireRegisteredBundleFieldsAndPreserveCompositeOrder(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('media', 'minutes', [
            new FieldDefinition(name: 'meeting_date', type: 'date', targetEntityTypeId: 'media', targetBundle: 'minutes', stored: \Waaseyaa\Field\FieldStorage::Data),
            new FieldDefinition(name: 'language', type: 'string', targetEntityTypeId: 'media', targetBundle: 'minutes'),
        ]);
        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => 'media_minutes_date_language',
            'fields' => ['meeting_date', 'language'],
        ]]);

        self::assertSame([[
            'name' => 'media_minutes_date_language',
            'fields' => ['meeting_date', 'language'],
        ]], $registry->bundleUniqueKeysFor('media', 'minutes'));
        self::assertSame(
            \Waaseyaa\Field\FieldStorage::Column,
            $registry->bundleFieldsFor('media', 'minutes')['meeting_date']->getStored(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field "missing"');
        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => 'invalid',
            'fields' => ['missing'],
        ]]);
    }

    #[Test]
    public function bundleUniqueKeyNamesRespectThePortableIdentifierLimit(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('media', 'minutes', [
            new FieldDefinition(name: 'meeting_date', type: 'date', targetEntityTypeId: 'media', targetBundle: 'minutes'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('63-byte identifier limit');
        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => str_repeat('x', 64),
            'fields' => ['meeting_date'],
        ]]);
    }

    #[Test]
    public function bundleUniqueKeyRegistrationRejectsMalformedDeclarations(): void
    {
        $unregistered = new FieldDefinitionRegistry();
        try {
            $unregistered->registerBundleUniqueKeys('media', 'minutes', []);
            self::fail('Expected registration-order refusal.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('before its fields are registered', $exception->getMessage());
        }

        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('media', 'minutes', [
            new FieldDefinition(name: 'meeting_date', type: 'date', targetEntityTypeId: 'media', targetBundle: 'minutes'),
        ]);
        foreach ([
            [['name' => '', 'fields' => ['meeting_date']], 'non-empty name and field list'],
            [['name' => 'bad_field', 'fields' => ['']], 'non-string or empty field'],
            [['name' => 'duplicate_field', 'fields' => ['meeting_date', 'meeting_date']], 'duplicate or empty fields'],
        ] as [$key, $message]) {
            try {
                $registry->registerBundleUniqueKeys('media', 'minutes', [$key]);
                self::fail('Expected malformed key refusal.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => 'media_minutes_date',
            'fields' => ['meeting_date'],
        ]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate bundle unique key name');
        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => 'media_minutes_date',
            'fields' => ['meeting_date'],
        ]]);
    }

    #[Test]
    public function bundleUniqueKeyCannotPromoteACustomDataBackedDefinition(): void
    {
        $custom = $this->createStub(\Waaseyaa\Field\FieldDefinitionInterface::class);
        $custom->method('getName')->willReturn('meeting_date');
        $custom->method('getTargetEntityTypeId')->willReturn('media');
        $custom->method('getTargetBundle')->willReturn('minutes');
        $custom->method('getStored')->willReturn(\Waaseyaa\Field\FieldStorage::Data);
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('media', 'minutes', [$custom]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot promote custom Data-backed field');
        $registry->registerBundleUniqueKeys('media', 'minutes', [[
            'name' => 'media_minutes_date',
            'fields' => ['meeting_date'],
        ]]);
    }
}
