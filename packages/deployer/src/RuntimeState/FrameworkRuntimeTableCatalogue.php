<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/**
 * Versioned ownership catalogue for state authored by a serving Waaseyaa host.
 *
 * Consumer applications must not copy this list. Uninstalled optional domains
 * simply have no matching table in either database.
 *
 * @api
 */
final class FrameworkRuntimeTableCatalogue
{
    /**
     * 2 (#2547): the 22 Framework-owned tables current migrations install but
     * the catalogue never classified — configuration authority, scheduler
     * state, idempotency, the two S1 authority tables, `state`, and
     * `cache_items`. Until they were classified, a legitimate serving
     * database built on this very commit was rejected by
     * {@see SqliteArtifactPreparer} before a single row was copied.
     */
    public const int VERSION = 2;

    /** @return array<string, RuntimeTableDefinition> */
    public function definitions(): array
    {
        $definitions = [
            new RuntimeTableDefinition('cache_discovery', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('cache_mcp_read', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('cache_render', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('cache_generation', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('embeddings', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_metadata', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index_config', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index_content', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index_data', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index_docsize', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('search_index_idx', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('migration_id_map', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('migration_run_state', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('waaseyaa_migrations', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('audit_retention_policy', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('user', RuntimeTablePolicy::IdentityMerge),
            new RuntimeTableDefinition('user_block', RuntimeTablePolicy::Preserve, ['blocker_id', 'blocked_id']),
            new RuntimeTableDefinition('auth_tokens', RuntimeTablePolicy::Preserve, ['user_id', 'created_by']),
            new RuntimeTableDefinition('auth_bearer_token', RuntimeTablePolicy::Preserve, ['account_uid']),
            new RuntimeTableDefinition('rate_limits', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('rate_limit_windows', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_access_token', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_authorization_codes', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_client', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_refresh_token', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_signing_key', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_signing_key_revocation', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('oidc_signing_key_version_sequence', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_token_custody_sequence', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_user_consent', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('waaseyaa_queue_jobs', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_failed_jobs', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_notifications', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_schedule_locks', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_schedule_state', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_application_master_version', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_adapter', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_failure', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_purpose', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_verification', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_rollback_verification', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_gate', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_application_master_rekey_event', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('_broadcast_log', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('_broadcast_retained', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('agent_run', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('trace', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('trace_span', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('audit_event', RuntimeTablePolicy::AppendOnly, ['account_uid', 'actor_uid'], [0, \PHP_INT_MAX]),
            new RuntimeTableDefinition('audit_checkpoint', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('audit_checkpoint_succession', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('audit_checkpoint_succession_pruned', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('privileged_read_ledger', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('strict_audit_ledger', RuntimeTablePolicy::AppendOnly, ['actor_uid'], [0, \PHP_INT_MAX]),
            new RuntimeTableDefinition('mcp_approval_event', RuntimeTablePolicy::AppendOnly, ['operator_uid'], [\PHP_INT_MAX]),
            new RuntimeTableDefinition('agent_audit_log', RuntimeTablePolicy::AppendOnly),

            // ---------------------------------------------------------------
            // #2547 — configuration authority.
            //
            // One aggregate, not twelve tables: `waaseyaa_config_activation_manifest`
            // carries a foreign key into `waaseyaa_config_generation_v2`, and
            // `waaseyaa_config_manifest_replay` has BEFORE INSERT/UPDATE triggers
            // that abort unless the matching committed activation row is already
            // present. Splitting the set across the handoff — preserving some and
            // taking others from the artifact — would leave the graph referencing
            // rows that are not there, so every member is Preserve together.
            //
            // What is lost if these came from the artifact: the host's own
            // configuration. The activation pointer, the candidate sweep fence, and
            // the activation counter would all revert to the build's values, so the
            // site would serve the artifact's configuration instead of its own and
            // the monotonic sequences would run backwards.
            //
            // The v1 tables (`waaseyaa_config_activation`, `..._entry`,
            // `..._generation`) are the pre-v2 shape. The 2026_08_12 migration reads
            // them to seed `waaseyaa_config_activation_counter` and does not drop
            // them, so they are still present in every migrated database and must be
            // classified even though the live path writes the `_v2` tables.
            //
            // AppendOnly versus Preserve within the set is a statement of
            // INTENT, not of behaviour: the two are the same code path in
            // SqliteArtifactPreparer today (both copy the serving rows over the
            // candidate). The split follows the migrations' own words —
            // "Immutable configuration generations and append-only ordered
            // activation" — so a future reader can tell which rows are
            // rewritable state and which are a ledger, and so a policy that
            // does distinguish them lands on the right rows.
            new RuntimeTableDefinition('waaseyaa_config_activation', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_activation_counter', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_config_activation_manifest', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_activation_v2', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_candidate', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_config_candidate_sweep_fence', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_config_entry', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_entry_contract', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_entry_v2', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_generation', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_generation_v2', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('waaseyaa_config_manifest_replay', RuntimeTablePolicy::Preserve),

            // ---------------------------------------------------------------
            // #2547 — scheduler state.
            //
            // `waaseyaa_scheduler_fence_sequence` is a singleton monotonic counter
            // seeded to 1 by its migration, and the leases carry the fencing tokens
            // drawn from it. A fresh artifact's copy would reset it, so a stale
            // lease holder could out-fence the live one — a correctness failure, not
            // just lost bookkeeping. The occurrence tables are the host's own record
            // of what has already run; taking them from the artifact would re-run
            // work the host has already done.
            new RuntimeTableDefinition('waaseyaa_scheduler_effect_fences', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_scheduler_fence_sequence', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_scheduler_leases', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_scheduler_occurrence_outbox', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_scheduler_occurrences', RuntimeTablePolicy::Preserve),

            // ---------------------------------------------------------------
            // #2547 — remaining serving-authored runtime state.

            // Replay protection for content mutations. From the artifact, a key the
            // serving host has already answered would be executed a second time.
            new RuntimeTableDefinition('publishing_idempotency', RuntimeTablePolicy::Preserve),

            // The framework key-value state store: last-run markers and similar
            // host-authored operational facts.
            new RuntimeTableDefinition('state', RuntimeTablePolicy::Preserve),

            // The migration-generation counter, which must agree with the
            // migration ledger it counts — and `waaseyaa_migrations` is already
            // Artifact above. The candidate's SCHEMA is the artifact's, so its
            // generation is the artifact's too. Preserving the serving counter
            // while taking the artifact's ledger would leave the two describing
            // different schema states.
            new RuntimeTableDefinition('waaseyaa_schema_authority', RuntimeTablePolicy::Artifact),

            // Per-entity aggregate versions and mutation tags — the anti-ABA fence.
            // Its own migration's down() declines to remove tombstones for exactly
            // this reason, so the serving values must never move backwards.
            //
            // This is the one table in the set that needs BOTH sides. Preserve would
            // drop the artifact's rows for entities only the artifact has, and a row
            // is not optional: EntityRepository::hydrate() throws
            // "Persisted entity … has no aggregate mutation authority" when it finds
            // none, and nothing recreates one on the read path — the repository's
            // backfill is an explicit privileged migration whose docblock says
            // outright that "ordinary reads never synthesize authority". Preserving
            // here would therefore make every entity the artifact introduces
            // unreadable on the serving host.
            //
            // IdentityMerge is the mechanism that fits: INSERT OR REPLACE lets the
            // serving row win on collision, so the host's aggregate versions are
            // never rolled back, while artifact-only rows survive. The name reads as
            // identity-specific, but the semantics — destination wins, both sides
            // survive — are exactly what this table requires. It is safe from the
            // collateral-deletion trap #2549 reports against `user`: the primary key
            // (storage_authority, tenant_id, entity_type, entity_id) is this table's
            // only unique index, so OR REPLACE can displace only the row it matched.
            new RuntimeTableDefinition('waaseyaa_entity_mutation_authority', RuntimeTablePolicy::IdentityMerge),

            // The generic cache backend table, classified like the cache entries
            // already in this catalogue: a cache is rebuildable, so the artifact's
            // copy is allowed to win and a cold cache is the intended cost.
            //
            // Every DatabaseBackend read filters `generation = :generation` against
            // the preserved `cache_generation` singleton, so an artifact row written
            // under a different generation is invisible rather than served stale.
            // That is a safety net, not a guarantee: the generation also advances at
            // runtime (see CacheGenerationRekeyAdapter), so a serving host and a
            // same-commit artifact can share one, and the artifact's entries are
            // then readable — which is correct, because they describe the content
            // the artifact is delivering.
            //
            // A cache arguably wants a policy this enum does not have: discard both
            // sides and rebuild cold. Adding one is a change to the handoff, not a
            // classification, so it is not taken here.
            new RuntimeTableDefinition('cache_items', RuntimeTablePolicy::Artifact),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->name])) {
                throw new \LogicException('Duplicate framework runtime table: ' . $definition->name);
            }
            $indexed[$definition->name] = $definition;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
