# FW-NATIVE-WINDOWS-QUALIFICATION-01: bounded local qualification

- Program: `FW-PACKAGE-CONVERGENCE-01`, `waaseyaa/framework#3118`
- Base: `64c88543c51d218d6e8bfa6408702414779aaab2`
- Scope: Framework maintainer delivery skill

## Evidence

An exact candidate on native Windows passed its focused ai-vector and CLI
suites and the default preflight. A repository-wide qualification then exposed
two unrelated host effects:

- overlapping heavy runs exhausted Git for Windows fork resources;
- a serialized Unit suite still failed unrelated symlink, locked SQLite,
  subprocess, and permission fixtures and left a test-owned PHP server.

The unchanged base reproduced its existing local dead-code findings while the
same base's hosted Linux dead-code job and main-feedback run passed. These are
host and base distinctions, not evidence against the ai-vector candidate.

## Decision

Native Windows uses default preflight and focused affected suites for local
evidence. The exact-head hosted full-qualification job owns the complete
verdict unless the repository's current native-host contract explicitly makes
the repository-wide local suite combination supported.

Any useful multi-suite Windows diagnostic uses one job, does not overlap
another local preflight or qualification against the repository, is labelled
diagnostic, and cleans up test-owned child processes after an unsupported run.
Host-sensitive failures become candidate findings only when they reproduce on
the supported host or differ from the exact base.
