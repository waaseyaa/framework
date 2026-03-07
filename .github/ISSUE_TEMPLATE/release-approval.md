---
name: Release Approval (v1.0)
about: Formal owner sign-off to authorize a v1.0 release. DO NOT OPEN without Russell's explicit instruction.
labels: versioning, release-approval
---

> **WARNING:** This template is for the formal v1.0 release authorization process only.
> Do not open this issue speculatively. Russell must initiate this process explicitly.

## Release Version

`v1.0.0`

## Owner Authorization

- Authorized by: @jonesrussell
- Authorization date: <!-- YYYY-MM-DD -->
- Authorization method: This issue + PR merging `release-approvals/v1.0.approved`

## Pre-Release Checklist

- [ ] All P0 issues in milestone `v0.1-defaults` are closed
- [ ] All P0 issues in milestone `v0.2-onboarding` are closed
- [ ] Boot validation CI passes on main
- [ ] Schema conformance CI passes on main
- [ ] ACL enforcement tests pass
- [ ] Migration smoke tests pass
- [ ] `VERSIONING.md` updated with authorization record
- [ ] `release-approvals/v1.0.approved` file created in this PR
- [ ] Monorepo split CI (`split.yml`) will be unblocked after merge

## Approval Artifact PR

<!-- Link to the PR that creates release-approvals/v1.0.approved -->

## Notes

<!-- Any release notes, known issues, or post-release tasks -->
