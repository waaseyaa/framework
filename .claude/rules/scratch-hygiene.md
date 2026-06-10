# Scratch Hygiene

This rule is always active. Follow it silently.

---

## Core Principle: Scratch Work Never Touches the Repo

**Verification experiments, throwaway installs, and exploratory commands run in a temp directory — never in the repository working tree.**

The repo cwd is for tracked changes only. Twice in one session, `composer` scratch commands run from the repo root rewrote the root `composer.json`; git caught it both times, but that is luck, not a control.

---

## Rules

- Any command that can write files as a side effect (`composer init`, `composer create-project`, `composer require`, `npm init`, code generators, archive extraction, test harness spikes) runs under a fresh temp dir:
  - Bash: `work=$(mktemp -d)` … `rm -rf "$work"` (mirror the `trap cleanup EXIT` pattern in `ci.yml`'s skeleton job)
  - PowerShell: `$work = Join-Path $env:TEMP ("scratch-" + [guid]::NewGuid()); New-Item -ItemType Directory $work`
- Never run `composer` subcommands from the repo root "just to check something" — `cd` to the temp dir first, or pass `--working-dir`.
- If a scratch run must reference the repo (e.g. a path repository), point at it read-only from the temp dir; do not invert the direction.
- Before committing, treat any unexpected diff in `composer.json`, `composer.lock`, or other manifests as scratch contamination until proven intentional.

---

## Why This Is a Rule and Not a Habit

The repo root already gitignores a long tail of "runtime/test artifacts that leak into repo root" (see `.gitignore`). Every entry in that list is a past leak. Temp-dir discipline prevents the *next* one, including writes to tracked files that gitignore cannot protect.
