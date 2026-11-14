# waaseyaa/engagement

Layer 2 content engagement: desired-state likes, plain-text comments and one-level replies. `EngagementService` owns writes, current-policy projections, bounded summaries, sealed pagination, optimistic edits and durable submission receipts. Reaction and Comment generic APIs are disabled and configured repositories require the domain mutation invariant. Follow retains its existing contract.

Applications implement `EngagementPolicyInterface` against the same canonical DBAL identity. It supplies current root/account eligibility, actor visibility, block rules, moderation and authorization generation. Bind it in the application container, configure `engagement.installation_id`, and bind the Foundation `ApplicationMasterKeyring`. Missing dependencies refuse operations. `engagement.lock_directory` optionally selects a private local Flock directory; admission is single-host. `engagement.limits` overrides named fixed-window budgets.

Run the normal schema migrations, then `engagement:upgrade` through the application's console. The command synchronizes canonical entity storage, processes resumable batches of at most 200 and prints body-free counts, reasons and affected row IDs. Domain traffic remains unavailable until both progress records are complete. Valid legacy comments become top-level; invalid references are quarantined; duplicate likes retain the earliest `(created_at,id)` row. Non-like reactions retain their stored values and existing parent-cascaded entity visibility. Protected authorization-input fields can require a bound internal operation because a field cannot authorize its own release.

Use `issueCommentKey()` before `createComment()` and reuse the key for retries. Keys expire after 48 hours; receipts contain result references and hashes, never body copies. `editComment()` requires the current aggregate revision. Removal immediately blanks text and records the actor and author/moderator kind. Current projections expose neutral parents only when permitted replies survive.

`comments()` and `replies()` default to 20 items, cap pages at 50 and scans at 200 candidates. A continuation can contain no visible items. Cursors expire after 15 minutes and bind the viewer, root, parent, page size and current policy generation. `summaries()` accepts at most 50 distinct roots.

The provider registers the hourly retention entry after upgrade readiness. Run the application's existing scheduler to execute it: metadata/quarantine retention defaults to 30 days, receipts to 48 hours and root/account deletion queues erase content in bounded batches. Keep the scheduler running to meet the 24-hour deletion obligation. Audit retention remains owned by the audit package. No host scheduler job is installed by this package.

The supported persistence implementation is SQLite with both `sql-blob` and `sql-column` entity layouts. Mutation methods refuse caller-owned transactions, acquire canonical writer authority and recheck current policy. Admission transactions commit before releasing the external limiter lock. HTTP routing, CSRF/session handling, response cache headers and consumer adoption belong to the subsequent API/application slice.

See [the owning spec](../../docs/specs/engagement-likes-comments.md) for behavior and [the change record](../../docs/change-records/FW-ENGAGEMENT-LIKES-COMMENTS-01.md) for qualification evidence.
