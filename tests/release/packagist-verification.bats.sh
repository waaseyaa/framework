#!/usr/bin/env bash
#
# Shell-level fixtures for the Packagist release state machine.
#
# These exercise the DECISION LOGIC — "is this package installable?" — against
# canned P2 responses, with no network and no credentials. That is the logic
# that was wrong at alpha.285: the pipeline could not distinguish a package
# that was queued-and-slow from one that was never crawled, and it treated an
# accepted POST as publication.
#
# Run: bash tests/release/packagist-verification.bats.sh
#
# Deliberately plain bash rather than a framework: this must be runnable inside
# a workflow container with nothing installed but jq.

set -uo pipefail

PASS=0
FAIL=0
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

ok()   { printf '  ok   %s\n' "$1"; PASS=$(( PASS + 1 )); }
bad()  { printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; FAIL=$(( FAIL + 1 )); }

# ---------------------------------------------------------------------------
# The unit under test: the same jq predicate the workflows use.
# Kept identical on purpose — a fixture that tests a paraphrase of the
# production check proves nothing about the production check.
# ---------------------------------------------------------------------------
is_visible() {
  local fixture="$1" pkg="$2" tag="$3"
  jq -e --arg t "${tag}" '.packages[][] | select(.version == $t)' < "${fixture}" >/dev/null 2>&1
}

p2() { # p2 <pkg> <version...>  -> path to a fixture file
  local pkg="$1"; shift
  local f="${WORK}/${pkg//\//_}.json" versions=""
  for v in "$@"; do versions="${versions}{\"version\":\"${v}\"},"; done
  versions="${versions%,}"
  printf '{"packages":{"%s":[%s]}}' "${pkg}" "${versions}" > "${f}"
  printf '%s' "${f}"
}

# ---------------------------------------------------------------------------
# 1. Accepted AND published — the happy path.
# ---------------------------------------------------------------------------
f=$(p2 waaseyaa/analytics v0.1.0-alpha.285 v0.1.0-alpha.284)
if is_visible "${f}" waaseyaa/analytics v0.1.0-alpha.285; then
  ok "accepted and published -> visible"
else
  bad "accepted and published -> visible" "the tag is present but was not detected"
fi

# ---------------------------------------------------------------------------
# 2. Accepted but NEVER published — the alpha.285 shape.
#    The package exists and has an older release. A check that merely asked
#    "does this package exist?" would pass here, which is the bug.
# ---------------------------------------------------------------------------
f=$(p2 waaseyaa/analytics v0.1.0-alpha.284 v0.1.0-alpha.283)
if is_visible "${f}" waaseyaa/analytics v0.1.0-alpha.285; then
  bad "accepted but never published -> NOT visible" "reported installable while only alpha.284 exists"
else
  ok "accepted but never published -> NOT visible"
fi

# ---------------------------------------------------------------------------
# 3. The v-prefix trap.
#    Packagist publishes "v0.1.0-alpha.285". Matching the unprefixed string
#    finds nothing and reports every package missing — a false alarm that
#    reads exactly like a catastrophic failed publish.
# ---------------------------------------------------------------------------
f=$(p2 waaseyaa/analytics v0.1.0-alpha.285)
if is_visible "${f}" waaseyaa/analytics 0.1.0-alpha.285; then
  bad "unprefixed tag must not match" "matched without the v prefix"
else
  ok "unprefixed tag must not match (guards the false-alarm sweep)"
fi

# ---------------------------------------------------------------------------
# 4. Substring near-misses must not satisfy the check.
#    alpha.28 must not match alpha.285, and alpha.285 must not be satisfied by
#    alpha.2851.
# ---------------------------------------------------------------------------
f=$(p2 waaseyaa/analytics v0.1.0-alpha.2851)
if is_visible "${f}" waaseyaa/analytics v0.1.0-alpha.285; then
  bad "near-miss version must not match" "alpha.2851 satisfied a request for alpha.285"
else
  ok "near-miss version must not match"
fi

# ---------------------------------------------------------------------------
# 5. Transient API failure then success.
#    An unreadable/empty body must read as "not visible" rather than crashing
#    or, worse, being treated as success.
# ---------------------------------------------------------------------------
broken="${WORK}/broken.json"; printf 'gateway timeout' > "${broken}"
if is_visible "${broken}" waaseyaa/analytics v0.1.0-alpha.285; then
  bad "malformed response -> NOT visible" "garbage was treated as a hit"
else
  ok "malformed response -> NOT visible"
fi
f=$(p2 waaseyaa/analytics v0.1.0-alpha.285)
if is_visible "${f}" waaseyaa/analytics v0.1.0-alpha.285; then
  ok "recovery after transient failure -> visible"
else
  bad "recovery after transient failure -> visible" "retry did not observe the published tag"
fi

# ---------------------------------------------------------------------------
# 6. One missing package repaired WITHOUT republishing all 76.
#    The missing set must shrink to exactly the unpublished package, so the
#    recovery command targets one name rather than the whole matrix.
# ---------------------------------------------------------------------------
declare -a missing=()
for pkg in waaseyaa/foundation waaseyaa/analytics waaseyaa/entity; do
  if [ "${pkg}" = "waaseyaa/analytics" ]; then
    f=$(p2 "${pkg}" v0.1.0-alpha.284)
  else
    f=$(p2 "${pkg}" v0.1.0-alpha.285)
  fi
  is_visible "${f}" "${pkg}" v0.1.0-alpha.285 || missing+=("${pkg}")
done
if [ "${#missing[@]}" -eq 1 ] && [ "${missing[0]}" = "waaseyaa/analytics" ]; then
  ok "targeted recovery names only the unpublished package"
else
  bad "targeted recovery names only the unpublished package" "got: ${missing[*]:-none}"
fi

# ---------------------------------------------------------------------------
# 7. The GitHub Release gate.
#    Modelled as the predicate the workflow dependency encodes: the release may
#    proceed only when the missing set is empty. Before alpha.285 the release
#    job did not depend on verification at all, so this was vacuously true.
# ---------------------------------------------------------------------------
release_allowed() { [ "$1" -eq 0 ]; }
if release_allowed "${#missing[@]}"; then
  bad "release blocked while a package is missing" "release would have proceeded with 1 missing"
else
  ok "release blocked while a package is missing"
fi
if release_allowed 0; then
  ok "release permitted when every package is visible"
else
  bad "release permitted when every package is visible" "release blocked despite a complete set"
fi

# ---------------------------------------------------------------------------
printf '\n%d passed, %d failed\n' "${PASS}" "${FAIL}"
[ "${FAIL}" -eq 0 ]
