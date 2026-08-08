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
    public const int VERSION = 1;

    /** @return array<string, RuntimeTableDefinition> */
    public function definitions(): array
    {
        $definitions = [
            new RuntimeTableDefinition('cache_discovery', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('cache_mcp_read', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('cache_render', RuntimeTablePolicy::Artifact),
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
            new RuntimeTableDefinition('audit_retention_policy', RuntimeTablePolicy::Artifact),
            new RuntimeTableDefinition('user', RuntimeTablePolicy::IdentityMerge),
            new RuntimeTableDefinition('user_block', RuntimeTablePolicy::Preserve, ['blocker_id']),
            new RuntimeTableDefinition('auth_tokens', RuntimeTablePolicy::Preserve, ['user_id', 'created_by']),
            new RuntimeTableDefinition('auth_bearer_token', RuntimeTablePolicy::Preserve, ['account_uid']),
            new RuntimeTableDefinition('rate_limits', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('rate_limit_windows', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_access_token', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_authorization_codes', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_client', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_refresh_token', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('oidc_signing_key', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('oidc_user_consent', RuntimeTablePolicy::Preserve, ['account_id']),
            new RuntimeTableDefinition('waaseyaa_queue_jobs', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_failed_jobs', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_notifications', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_schedule_locks', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('waaseyaa_schedule_state', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('_broadcast_log', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('_broadcast_retained', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('agent_run', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('trace', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('trace_span', RuntimeTablePolicy::Preserve),
            new RuntimeTableDefinition('audit_event', RuntimeTablePolicy::AppendOnly, ['account_uid', 'actor_uid'], [0, \PHP_INT_MAX]),
            new RuntimeTableDefinition('audit_checkpoint', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('privileged_read_ledger', RuntimeTablePolicy::AppendOnly),
            new RuntimeTableDefinition('strict_audit_ledger', RuntimeTablePolicy::AppendOnly, ['actor_uid'], [0, \PHP_INT_MAX]),
            new RuntimeTableDefinition('mcp_approval_event', RuntimeTablePolicy::AppendOnly, ['operator_uid'], [\PHP_INT_MAX]),
            new RuntimeTableDefinition('agent_audit_log', RuntimeTablePolicy::AppendOnly),
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
