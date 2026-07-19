<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Entity\RetentionPolicyMaintenanceReader;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function can_construct_with_empty_values(): void
    {
        $policy = new RetentionPolicy([]);
        $view = new RetentionPolicyMaintenanceReader()->read($policy);

        self::assertSame('', $view->name);
        self::assertSame('', $view->action);
        self::assertSame('', $view->triggerKind);
        self::assertSame('', $view->triggerValue);
        self::assertSame([], $view->appliesTo);
        self::assertSame([], $view->exemptions);
    }

    #[Test]
    public function getters_return_set_values(): void
    {
        $policy = new RetentionPolicy([
            'name' => 'Purge old internal notes',
            'applies_to' => ['internal', 'confidential'],
            'action' => RetentionPolicy::ACTION_PURGE,
            'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
            'trigger_value' => 'P90D',
            'exemptions' => ['note:abc-123'],
        ]);

        $view = new RetentionPolicyMaintenanceReader()->read($policy);
        self::assertSame('Purge old internal notes', $view->name);
        self::assertSame(['internal', 'confidential'], $view->appliesTo);
        self::assertSame('purge', $view->action);
        self::assertSame('age_based', $view->triggerKind);
        self::assertSame('P90D', $view->triggerValue);
        self::assertSame(['note:abc-123'], $view->exemptions);
    }

    #[Test]
    public function audit_context_preserves_the_exact_operational_shape(): void
    {
        $view = new RetentionPolicyMaintenanceReader()->read(new RetentionPolicy([
            'name' => 'Purge old internal notes',
            'action' => RetentionPolicy::ACTION_PURGE,
            'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
            'created_at' => 1_700_000_000,
        ]));

        self::assertSame([
            'policy_name' => 'Purge old internal notes',
            'action' => 'purge',
            'trigger_kind' => 'age_based',
            'created_at' => 1_700_000_000,
        ], $view->auditContext());
    }

    #[Test]
    public function applies_to_decodes_json_string_storage(): void
    {
        $policy = new RetentionPolicy([
            'applies_to' => '["hold-legal","hold-research"]',
            'exemptions' => '["audit:1","audit:2"]',
        ]);

        $view = new RetentionPolicyMaintenanceReader()->read($policy);
        self::assertSame(['hold-legal', 'hold-research'], $view->appliesTo);
        self::assertSame(['audit:1', 'audit:2'], $view->exemptions);
    }

    #[Test]
    public function applies_to_filters_non_string_entries(): void
    {
        // Defensive: tolerate malformed payloads (e.g. legacy migrations) by
        // dropping non-string entries rather than crashing.
        $policy = new RetentionPolicy([
            'applies_to' => ['ok', 42, '', null, 'also-ok'],
        ]);

        self::assertSame(['ok', 'also-ok'], new RetentionPolicyMaintenanceReader()->read($policy)->appliesTo);
    }

    #[Test]
    public function matches_label_supports_literal_equality(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['internal', 'confidential']]);
        $view = new RetentionPolicyMaintenanceReader()->read($policy);

        self::assertTrue($view->matchesLabel('internal'));
        self::assertTrue($view->matchesLabel('confidential'));
        self::assertFalse($view->matchesLabel('public'));
        self::assertFalse($view->matchesLabel('hold-legal'));
    }

    #[Test]
    public function matches_label_supports_prefix_glob(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['nation-*', 'hold-*']]);
        $view = new RetentionPolicyMaintenanceReader()->read($policy);

        self::assertTrue($view->matchesLabel('nation-confidential'));
        self::assertTrue($view->matchesLabel('nation-sacred'));
        self::assertTrue($view->matchesLabel('hold-legal'));
        self::assertFalse($view->matchesLabel('confidential'));
        self::assertFalse($view->matchesLabel('public'));
    }

    #[Test]
    public function star_alone_matches_anything(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['*']]);
        $view = new RetentionPolicyMaintenanceReader()->read($policy);

        self::assertTrue($view->matchesLabel('public'));
        self::assertTrue($view->matchesLabel('hold-legal'));
        self::assertTrue($view->matchesLabel('anything'));
    }

    #[Test]
    public function is_exempt_keys_on_entity_type_and_uuid(): void
    {
        $policy = new RetentionPolicy([
            'exemptions' => ['node:abc-123', 'media:xyz-789'],
        ]);
        $view = new RetentionPolicyMaintenanceReader()->read($policy);

        self::assertTrue($view->isExempt('node', 'abc-123'));
        self::assertTrue($view->isExempt('media', 'xyz-789'));
        self::assertFalse($view->isExempt('node', 'xyz-789'));
        self::assertFalse($view->isExempt('media', 'abc-123'));
    }

    #[Test]
    public function action_and_trigger_constants_match_spec(): void
    {
        // The scheduled jobs in WP03 dispatch on these literal values.
        self::assertSame('purge', RetentionPolicy::ACTION_PURGE);
        self::assertSame('redact', RetentionPolicy::ACTION_REDACT);
        self::assertSame('hold-flag', RetentionPolicy::ACTION_HOLD_FLAG);
        self::assertSame('age_based', RetentionPolicy::TRIGGER_AGE_BASED);
        self::assertSame('event_based', RetentionPolicy::TRIGGER_EVENT_BASED);
    }
}
