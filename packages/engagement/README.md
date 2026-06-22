# waaseyaa/engagement

**Layer 2 — Content Types**

Social engagement entities for Waaseyaa: reactions, comments, follows.

Provides three content entities (`Reaction`, `Comment`, `Follow`) with their own access policies that respect parent-content visibility — comments on a draft post are not visible to anonymous readers even if the comment itself is public. `EngagementAccessPolicy` enforces the parent-cascade.

### Access model

`EngagementAccessPolicy` gates `view` on two conditions (an `administer content` permission bypasses both):

- **Parent-cascade** — an engagement is only as visible as the content it targets (`target_type`/`target_id`). The policy loads the parent and asks the access system whether the caller may `view` it; if it cannot prove the parent is viewable (parent missing, unresolvable, or denied, or the policy was constructed without its `EntityTypeManagerInterface`/`EntityAccessHandler` dependencies), `view` is **denied** (fail-closed). The kernel's two-phase policy discovery injects those dependencies.
- **Comment moderation** — an unpublished comment (`status = false`) is viewable only by its owner.

Create access is authenticated-only; delete is owner-or-admin.

Key classes: `Reaction`, `Comment`, `Follow`, `EngagementAccessPolicy`, `EngagementServiceProvider`.
