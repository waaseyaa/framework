# Optional parallel delivery

Use only when additional implementation or review agents are authorized. This
reference coordinates that work; it does not require multiple agents or select
their models, vendors or execution services.

Assign one integration owner and give each worker a bounded outcome, disjoint
file ownership or an explicit integration boundary. Tell workers they are not
alone and must preserve other lanes. Use isolated worktrees and the repository's
lease mechanism where available. Record the path, branch and base before work.

Workers hand off stable files, an exact candidate, focused evidence and remaining
acceptance. Visible edits in a shared checkout are not a handoff. The integration
owner inspects the delta before staging it and integrates shared files serially.

Keep dependencies candidate-local. Do not borrow another worktree's vendor tree
or add autoload overrides to manufacture a pass. Provision dependencies before
tests; verify relevant classes resolve into the candidate. Coordinate heavy
installs, builds and qualification on shared hosts under the local testing policy.

Keep a small queue of ready work and reuse valid evidence. Check actual job state
and artifacts before reporting activity; queued work is not running, and elapsed
time is not proof of progress. Independent hosted checks may run concurrently;
shared-file integration and merges remain serial.

For independent review, provide the immutable diff, owning acceptance and
existing verification. The reviewer did not author that candidate; model identity
does not establish independence or review quality. One proportionate review is
normally enough. Additional reviews need a distinct risk or unresolved finding.

Preserve unfinished work and stop the affected lane if custody or evidence
integrity fails. Do not weaken permissions or reroute an operation to bypass a
denial. Monitoring after the active turn needs separate scheduling authorization.
