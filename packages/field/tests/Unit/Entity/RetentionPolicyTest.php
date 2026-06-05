<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Entity\RetentionPolicy;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function can_construct_with_empty_values(): void
    {
        $policy = new RetentionPolicy([]);

        self::assertSame('', $policy->getName());
        self::assertSame('', $policy->getAction());
        self::assertSame('', $policy->getTriggerKind());
        self::assertSame('', $policy->getTriggerValue());
        self::assertSame([], $policy->getAppliesTo());
        self::assertSame([], $policy->getExemptions());
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

        self::assertSame('Purge old internal notes', $policy->getName());
        self::assertSame(['internal', 'confidential'], $policy->getAppliesTo());
        self::assertSame('purge', $policy->getAction());
        self::assertSame('age_based', $policy->getTriggerKind());
        self::assertSame('P90D', $policy->getTriggerValue());
        self::assertSame(['note:abc-123'], $policy->getExemptions());
    }

    #[Test]
    public function applies_to_decodes_json_string_storage(): void
    {
        $policy = new RetentionPolicy([
            'applies_to' => '["hold-legal","hold-research"]',
            'exemptions' => '["audit:1","audit:2"]',
        ]);

        self::assertSame(['hold-legal', 'hold-research'], $policy->getAppliesTo());
        self::assertSame(['audit:1', 'audit:2'], $policy->getExemptions());
    }

    #[Test]
    public function applies_to_filters_non_string_entries(): void
    {
        // Defensive: tolerate malformed payloads (e.g. legacy migrations) by
        // dropping non-string entries rather than crashing.
        $policy = new RetentionPolicy([
            'applies_to' => ['ok', 42, '', null, 'also-ok'],
        ]);

        self::assertSame(['ok', 'also-ok'], $policy->getAppliesTo());
    }

    #[Test]
    public function matches_label_supports_literal_equality(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['internal', 'confidential']]);

        self::assertTrue($policy->matchesLabel('internal'));
        self::assertTrue($policy->matchesLabel('confidential'));
        self::assertFalse($policy->matchesLabel('public'));
        self::assertFalse($policy->matchesLabel('hold-legal'));
    }

    #[Test]
    public function matches_label_supports_prefix_glob(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['nation-*', 'hold-*']]);

        self::assertTrue($policy->matchesLabel('nation-confidential'));
        self::assertTrue($policy->matchesLabel('nation-sacred'));
        self::assertTrue($policy->matchesLabel('hold-legal'));
        self::assertFalse($policy->matchesLabel('confidential'));
        self::assertFalse($policy->matchesLabel('public'));
    }

    #[Test]
    public function star_alone_matches_anything(): void
    {
        $policy = new RetentionPolicy(['applies_to' => ['*']]);

        self::assertTrue($policy->matchesLabel('public'));
        self::assertTrue($policy->matchesLabel('hold-legal'));
        self::assertTrue($policy->matchesLabel('anything'));
    }

    #[Test]
    public function is_exempt_keys_on_entity_type_and_uuid(): void
    {
        $policy = new RetentionPolicy([
            'exemptions' => ['node:abc-123', 'media:xyz-789'],
        ]);

        self::assertTrue($policy->isExempt('node', 'abc-123'));
        self::assertTrue($policy->isExempt('media', 'xyz-789'));
        self::assertFalse($policy->isExempt('node', 'xyz-789'));
        self::assertFalse($policy->isExempt('media', 'abc-123'));
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
