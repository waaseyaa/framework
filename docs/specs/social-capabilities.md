---
waaseyaa-spec:
  lifecycle: draft
---
# Shared social capabilities

Status: scope accepted for planning by the maintainer, 2026-10-08. Detailed
transitions remain draft; this is not an implemented or accepted release contract.
Tracking: [#3197](https://github.com/waaseyaa/framework/issues/3197). Change record: [FW-SOCIAL-CAPABILITIES-01](../change-records/FW-SOCIAL-CAPABILITIES-01.md).

## Purpose and settled direction

Applications should consume the same reusable social capabilities from Waaseyaa.
Product vocabulary, presentation and access settings can differ; applications
should not maintain competing messaging, relationship or engagement engines.
Historical consumer implementations are discovery evidence, not the target
specification or proof of supported behavior. Shared requirements precede further
social runtime implementation. Consumer-specific roadmaps and adoption evidence
belong in the consuming repositories, with dependency links pointing upstream.

The maintainer accepted familiar private/group-chat behavior as the design
baseline: participants act as themselves, group administrators manage membership,
people can leave, senders control their own edits/unsending, personal inbox
hiding differs from shared deletion, and moderation requires explicit authority.
Exact transitions must compose with consent, blocking, account lifecycle and
retention. Similar behavior across products does not require cross-site accounts
or a federated social network.

## Accepted capability scope, implementation pending

All rows describe intended reusable capability, not current completeness. The
first delivery slice is not yet selected. Existing owning specs remain the live
contracts until a reviewed amendment replaces the relevant clauses.

| Capability | Proposed common behavior | Existing owner / gap |
| --- | --- | --- |
| Accounts and public profiles | Separate public presentation from login, private account data and authority to act. Publishing a profile does not publish email or grant another account control. | `user`, `auth`, `media`; product claim/verification rules remain separate. |
| Following, private saves and muting | Following subscribes to permitted updates; saving records a private bookmark; muting affects the member's presentation/notifications. None grants contact, membership or paid access. | `engagement` owns Follow; saved/muted state needs an explicit owner and identity contract. |
| Published content and feeds | Public or restricted content, comments and reactions inherit target visibility; feeds apply current visibility before pagination/counts. | `node`, `publishing`, `engagement`, `listing`, `search`, `cache`; member posts, comments/replies and feeds are accepted planning scope. |
| Groups and communities | Membership has explicit join/invite/approval/leave/removal states and scoped roles. A group member is not automatically a site administrator or a message recipient. | `groups` + `relationship`; #1627, #2762 and #1957 retain their separate contracts. |
| Private and group conversations | Contact permission, requests, participants, messages, read positions, edit/unsend and leave are coherent state transitions. | `messaging`; [messaging spec](messaging.md), audit D2/D3. #2054 is a separate AI/corpus-chat proposal, not the owner of human messaging. |
| Blocking and contact preferences | A current block denies the defined interaction paths, including stale requests and queued effects. Explicit eligibility governs initiating contact. | No shared owner is ratified here. Resolve placement before adding another relationship/policy implementation. |
| Reporting and moderation | Restricted reports/evidence, scoped moderator decisions, visible outcomes and audit records. Ordinary group admin status does not imply unrestricted access to private conversations. | Reuse access/audit/workflows where suitable; report lifecycle and owner still need design. |
| Notifications | Preferences and current visibility govern inbox/email/live delivery; retries preserve intended recipient and avoid duplicate logical notifications. | `notification`, `mail`, `queue`, `mercure`; #2745 and #1624 remain owners. |
| Account/content exit | Deactivation, deletion, leaving, revocation and retention have explicit effects on edges, messages, feeds, cached results and notifications. | Existing entity lifecycle, relationship guards and retention owners; coordinated acceptance is required. |

Proposed exclusions from the initial shared contract: billing or paid DM access,
federation, voice/video calls, end-to-end encryption claims, algorithmic feed
ranking and app-specific moderation/legal policy. These are not permanently
rejected features; none is silently required to finish the current audit.

## Cross-package requirements to ratify

| ID | Intended invariant | Discriminating acceptance |
| --- | --- | --- |
| SOC-01 | An authenticated actor is distinct from a displayed profile, claimed identity and delegated moderator. | An unclaimed/admin-curated profile cannot send, follow, accept contact or authenticate merely because it has a record. |
| SOC-02 | Follow, save, group membership and contact permission are independent relationships. | Following/joining/saving alone cannot start a conversation or reveal private membership/preferences. |
| SOC-03 | Authorization uses current state at the mutation/read boundary. | A revoked member or blocked sender cannot reuse a stale page, cached identifier, queued request or retry to regain authority. |
| SOC-04 | Each relationship and operation has one identity and retry contract. | Concurrent follow/join/message submissions produce the documented result; partial failures produce no success-shaped response. |
| SOC-05 | Visibility applies throughout derived surfaces. | Hidden/deleted/blocked content does not leak through search, counts, snippets, notifications, cached lists or attachment URLs. |
| SOC-06 | Conversation changes preserve authorship, membership authority and shared history semantics. | A member cannot impersonate another sender, move a message to another conversation or change another member's read position. |
| SOC-07 | Personal removal, shared unsend, moderator removal and retention are different operations. | Hiding a chat does not delete it for peers; unsent content is absent from ordinary participant projections; restricted evidence follows explicit retention. |
| SOC-08 | Exit and leadership transitions are explicit. | A user can leave; the final-admin case has a ratified successor/archive rule, and cannot silently orphan an active group or trap a user indefinitely. |
| SOC-09 | Asynchronous effects follow durable success and current delivery permission. | Rollback emits no notification; a blocked/deactivated recipient or changed visibility is rechecked before delayed delivery. |
| SOC-10 | Applications consume one supported implementation. | Both consumer-shaped fixtures use the same package operations/policies, with different app settings and no copied social engine. |

These are proposed acceptance obligations. They do not assert that current
packages pass them, or silently convert missing future features into defects.
Confirmed defects retain their existing owners and severity/evidence records.

## Composition and Symfony reuse

Plan first
for explicitly installed opt-in packages in normal kernel applications on the
current S1 SQLite boundary, including production installs without development
dependencies. Source tests and old consumer code are not installed acceptance.
The portable domain contract should remain independent of application names.
Do not promise separately hand-assembled runtime composition until it has a
named consumer and qualifying evidence.

Reuse existing entity/repository, immutable-principal access, schema, transaction,
configuration and after-commit authorities. Evaluate supported Symfony Security,
Workflow, Validator, Messenger, RateLimiter and Notifier components where their
generic capabilities fit. This is an evaluation requirement, not a decision to
install all of them or wrap them in parallel Framework abstractions. Symfony does
not choose social consent, relationship identity or moderation authority for us.

No catch-all `social` package is proposed merely to collect these features.
Choose ownership from the approved state/transaction boundaries. Add a package
only if a capability has a coherent independent purpose that existing owners
cannot serve cleanly. Static layering alone does not prove the journeys work.

## SDD sequence and verification

1. Use the accepted shared scope below; keep application-specific policy in consumer repositories.
2. Write transition/permission tables for identity/contact/blocking, membership,
   messages, content/engagement, notifications and exit. Define operation inputs,
   outcomes, refusal codes, concurrency and retention, not only entity fields.
3. Amend the owning live specs, map each requirement to existing code and issues,
   and identify justified reuse/removal work. Retire superseded passages in the
   same reviewed change; do not rewrite historical evidence as fresh proof.
4. Capture failing controls for accepted defects, then implement bounded slices
   in dependency order. Distinguish required convergence repairs from new feature
   delivery. Do not restart unaffected listing/messaging audit work.
5. Qualify common source behavior, production no-dev package installs, real kernel
   HTTP/session and trusted non-HTTP paths, and synthetic consumers with distinct application policies. Only a later test against an exact updated application revision
   qualifies that real consumer. No application upgrade or deployment is implied.

Existing owners include #2756 (engagement target
integrity/lifecycle), #1627/#2762/#1957 (membership contract/atomicity/UI),
#2745 (async notification routing), #3005/#2859 (listing production composition),
#2055 (downstream dead paths) and #3118 (framework convergence). Link concrete
repairs to those owners rather than filing a second parallel backlog. #2054
proposes AI/corpus chat with retrieval grounding; keep its requirements separate
from the shared human-conversation contract.

## Scope decision and remaining design

On 2026-10-08 the maintainer accepted a common social target including
member-authored posts, following/community feeds, comments and replies, reactions,
groups and private/group conversations. Delivery is phased after identity,
privacy and relationship foundations. This settles D1 at planning scope only.

### Comment contract to specify before implementation

- Bind the author to the authenticated actor and the comment to a permitted,
  existing post. Replies must reference a comment on that same post.
- Apply current post visibility and block/moderation rules to reads, writes,
  reply counts, notifications, search and cached projections.
- Distinguish an author's edit/delete from moderator removal; define how child
  replies behave when a parent comment or post is removed.
- Define reply depth, edit windows, moderation visibility, retention and retry
  identity before implementing those transitions. They are open choices, not
  implied defaults from old application code.
- Acceptance includes a stranger, revoked member, blocked author, deleted post,
  reply to another post, concurrent retry and delayed notification after removal.

Subsequent transition design must also settle request acceptance, block effects
on old history and groups, membership admission, last-admin succession, message
edit/unsend limits, attachment scope and report retention. Record coherent
recommended defaults together; do not silently ratify them through implementation.
