# Manual agent reviews

Pull requests are optional during the private-MVP sprint, but risk-based
independent review is still required for authentication, authorization,
persistence, execution, and isolation changes. A reviewer must inspect an exact
candidate and record findings and the reviewed commit identity. Agent feedback
is advisory and does not substitute for release qualification.

The temporary hosted Claude review workflow was retired on 2026-09-27. There is
no `@claude review` trigger, repository Actions credential, or automatic Claude
review path. When Claude is selected as an independent reviewer, run it through
the maintainer's local reviewed tooling and preserve its exact-head evidence.

For a PR-backed candidate, Codex review may still be requested manually through
the ChatGPT GitHub integration with `@codex review`. Keep automatic review and
paid-credit overflow disabled. A direct-main candidate instead records the
review alongside its delivery evidence without manufacturing a PR solely for
the review.
