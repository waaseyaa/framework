# Retire misleading Framework deployment environments

Date: 2026-08-17

## Intent

Make the Framework repository's release surface describe what it actually
does. GitHub displayed more than 500 `staging` and `production` deployment
records, but the workflow had no external target, credentials, protection
rules, variables, or environment secrets. Its shell script built files on a
disposable Actions runner and wrote metadata only.

## Accepted design

1. Preserve useful manual exact-main-SHA verification.
2. Rename the workflow contract to **Release Readiness**.
3. Build the candidate fail-closed and run the complete Playwright sweep.
4. Retain bounded build and browser evidence.
5. Remove both GitHub Environment bindings and every claim of staging,
   production promotion, rollback, or incident response.
6. Delete the two empty GitHub Environments only after the corrected workflow
   is merged, so a subsequent run cannot recreate them.

## Removed false authorities

- `scripts/deploy.sh` did not transmit or activate an artifact anywhere.
- `scripts/rollback.sh` only changed the checkout inside an ephemeral runner.
- The production job could create an incident claiming rollback was attempted
  even though no external state could have changed.
- The GitHub Environments had zero secrets, zero variables, zero protection
  rules, and no branch policy.

## Authority boundary

`release-cut.yml`, package splitting, GitHub Releases, and Packagist own
Framework publication. Application deployment and rollback belong to consumer
and infrastructure repositories with a real immutable artifact, target, and
operator recovery path. This change does not publish, deploy, tag, split, or
mutate an application environment.

## Verification

- Architecture contract goes red before implementation and green afterward.
- Workflow YAML parses.
- Shell syntax and ShellCheck pass.
- `php bin/check-pr-preflight --full` and spec drift pass on the exact commit.
- Hosted PR checks pass before merge.
- After merge, delete only `waaseyaa/framework` environments `staging` and
  `production`, then verify the environment list is empty.
