#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ADMIN_WORKFLOW="${ROOT_DIR}/.github/workflows/admin-dist.yml"
CI_WORKFLOW="${ROOT_DIR}/.github/workflows/ci.yml"
GIT_WRAPPER="${ROOT_DIR}/bin/git"
FAIL=0

fail() {
    echo "FAIL: $*" >&2
    FAIL=1
}

checkout_line="$(grep -n -m1 'uses: actions/checkout@' "${ADMIN_WORKFLOW}" | cut -d: -f1)"
checkout_end=$((checkout_line + 12))
if ! sed -n "${checkout_line},${checkout_end}p" "${ADMIN_WORKFLOW}" \
    | grep -Eq '^[[:space:]]+persist-credentials:[[:space:]]+false[[:space:]]*$'; then
    fail "admin-dist checkout must set persist-credentials: false"
fi
if ! grep -Fq 'GIT_ASKPASS=' "${ADMIN_WORKFLOW}" \
    || ! grep -Eq '^[[:space:]]+GH_TOKEN:[[:space:]]+\$\{\{ secrets\.GITHUB_TOKEN \}\}[[:space:]]*$' "${ADMIN_WORKFLOW}"; then
    fail "admin-dist push must authenticate from the step environment after checkout credentials are disabled"
fi
if ! grep -Eq '^[[:space:]]+run_gate check-field-guards[[:space:]]*$' "${CI_WORKFLOW}"; then
    fail "ci/verify-gates must run the field-guards regression"
fi

if [ ! -x "${GIT_WRAPPER}" ]; then
    fail "bin/git must exist and be executable"
else
    TMP_DIR="$(mktemp -d)"
    trap 'rm -rf "${TMP_DIR}"' EXIT
    FAKE_GIT="${TMP_DIR}/git-real"
    CALLS="${TMP_DIR}/calls"

    cat > "${FAKE_GIT}" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$@" >> "${WAASEYAA_GIT_WRAPPER_CALLS}"
FAKE
    chmod +x "${FAKE_GIT}"

    for invocation in \
        "stash" \
        "stash push" \
        "-C ${ROOT_DIR} stash list" \
        "-c color.ui=false stash pop"; do
        : > "${CALLS}"
        # shellcheck disable=SC2086 # Intentional word splitting exercises argv parsing.
        if WAASEYAA_SYSTEM_GIT="${FAKE_GIT}" WAASEYAA_GIT_WRAPPER_CALLS="${CALLS}" \
            "${GIT_WRAPPER}" ${invocation} >"${TMP_DIR}/stdout" 2>"${TMP_DIR}/stderr"; then
            fail "bin/git accepted forbidden invocation: git ${invocation}"
        fi
        if ! grep -Fq 'git stash is forbidden' "${TMP_DIR}/stderr"; then
            fail "bin/git did not explain the stash refusal: git ${invocation}"
        fi
        if [ -s "${CALLS}" ]; then
            fail "bin/git delegated forbidden invocation: git ${invocation}"
        fi
    done

    : > "${CALLS}"
    if ! WAASEYAA_SYSTEM_GIT="${FAKE_GIT}" WAASEYAA_GIT_WRAPPER_CALLS="${CALLS}" \
        "${GIT_WRAPPER}" -C "${ROOT_DIR}" status --short; then
        fail "bin/git did not delegate an allowed command"
    fi
    if ! diff -u <(printf '%s\n' -C "${ROOT_DIR}" status --short) "${CALLS}"; then
        fail "bin/git changed allowed-command arguments"
    fi
fi

if [ "${FAIL}" -ne 0 ]; then
    exit 1
fi

echo "PASS: admin checkout credentials and repository git wrapper are guarded"
