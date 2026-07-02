<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the `group` content entity type and its `group_type` bundle
 * config entity.
 *
 * Ships with zero pre-registered bundles. Products declare their own
 * GroupType config entities and register bundle-scoped fields via
 * EntityTypeManager::addBundleFields().
 */
final class GroupsServiceProvider extends ServiceProvider
{
    /**
     * PARKED API surface — do not add an access policy to "fix" this.
     *
     * `group` and `group_type` are registered here for STORAGE and bundle
     * purposes only (subtable materialization, `EntityTypeManager` lookups,
     * config-entity bundle declarations). The package ships zero
     * `AccessPolicyInterface` implementations, and `JsonApiRouteProvider`
     * auto-mounts JSON:API CRUD routes for every registered entity type with
     * no per-type opt-out. The combination means:
     *
     *   - `EntityAccessHandler` has no applicable policy for either type, so
     *     `check()`/`checkCreateAccess()` always return Neutral.
     *   - `JsonApiController` treats Neutral as denied (deny-by-default, not
     *     fail-open) — every `/api/group*` CRUD request is rejected for
     *     every account, including admins.
     *
     * This is an intentional PARK decision (audit-remediation batch
     * 2026-07-02, WP5), not an oversight: no consumer exercises group/
     * group_type over JSON:API today. `discoverable: false` below
     * de-advertises both types from the `GET /api` discovery index so the
     * dead surface is not even listed. Full route suppression requires the
     * planned entity-type `#[ContentEntityType(..., api: false)]` exposure
     * flag (not built yet — see the roadmap's
     * FEATURE-entity-attribute-discovery.md) and is deferred to that work.
     * Tracked in issue #1871. A consumer that wants group-over-API must ship
     * its own access policy; do not add one here.
     */
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            Group::class,
            bundleEntityType: 'group_type',
            group: 'groups',
            discoverable: false,
        ));

        // GroupType is a config entity (extends ConfigEntityBase). Attribute
        // reflection only applies to ContentEntityBase subclasses, so the
        // config entity registration stays explicit per AD-3 in the plan.
        $this->entityType(new EntityType(
            id: 'group_type',
            label: 'Group type',
            description: 'Declares a Group bundle.',
            class: GroupType::class,
            keys: [
                'id' => 'id',
                'label' => 'label',
            ],
            group: 'groups',
            discoverable: false,
            _fieldDefinitions: [
                'description' => new FieldDefinition(
                    name: 'description',
                    type: 'text',
                    targetEntityTypeId: 'group_type',
                    label: 'Description',
                    description: 'Human-readable description of this group type.',
                    settings: ['weight' => 5],
                ),
            ],
        ));
    }
}
