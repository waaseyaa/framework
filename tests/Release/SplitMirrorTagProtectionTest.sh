#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/split.yml" <<'YAML'
matrix:
  package:
    - { local: 'packages/zeta', remote: 'zeta' }
    - { local: 'packages/alpha', remote: 'alpha' }
    - { local: 'packages/alpha-copy', remote: 'alpha' }
YAML

cat > "${TMP_DIR}/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "${FAKE_GH_LOG}"
endpoint=""
method="GET"
for arg in "$@"; do
  case "${arg}" in
    repos/*/rulesets*) endpoint="${arg}" ;;
    POST|PUT) method="${arg}" ;;
  esac
done

if [[ "${method}" == "GET" ]]; then
  if [[ "${endpoint}" == */rulesets/42 ]]; then
    printf '%s\n' '{"id":42,"name":"split-tag-protection-forward-only","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/**"],"exclude":[]}},"rules":[{"type":"deletion"},{"type":"non_fast_forward"},{"type":"update"}]}'
    exit 0
  fi
  if [[ "${FAKE_GH_MODE}" == "protected" ]]; then
    printf '%s\n' '[{"id":42,"name":"split-tag-protection-forward-only","target":"tag","enforcement":"active"}]'
  else
    printf '%s\n' '[]'
  fi
  exit 0
fi

payload="$(cat)"
printf '%s\n' "${payload}" >> "${FAKE_GH_PAYLOAD_LOG}"
printf '%s\n' '{"id":42,"name":"split-tag-protection-forward-only","target":"tag","enforcement":"active"}'
SH
chmod +x "${TMP_DIR}/bin/gh"

export PATH="${TMP_DIR}/bin:${PATH}"
export FAKE_GH_LOG="${TMP_DIR}/gh.log"
export FAKE_GH_PAYLOAD_LOG="${TMP_DIR}/payload.log"

export FAKE_GH_MODE=unprotected
if SPLIT_YML="${TMP_DIR}/split.yml" "${ROOT_DIR}/bin/configure-split-tag-protection" --check >"${TMP_DIR}/check.out" 2>&1; then
  echo "FAIL: --check passed for unprotected mirrors"
  exit 1
fi
grep -q 'alpha: missing' "${TMP_DIR}/check.out"
grep -q 'zeta: missing' "${TMP_DIR}/check.out"

: > "${FAKE_GH_LOG}"
: > "${FAKE_GH_PAYLOAD_LOG}"
SPLIT_YML="${TMP_DIR}/split.yml" "${ROOT_DIR}/bin/configure-split-tag-protection" --apply >"${TMP_DIR}/apply.out"

[[ "$(grep -c -- '--method POST' "${FAKE_GH_LOG}")" -eq 2 ]]
[[ "$(grep -c 'refs/tags/\\*\\*' "${FAKE_GH_PAYLOAD_LOG}")" -eq 2 ]]
grep -q '"type":"deletion"' "${FAKE_GH_PAYLOAD_LOG}"
grep -q '"type":"update"' "${FAKE_GH_PAYLOAD_LOG}"
grep -q '"type":"non_fast_forward"' "${FAKE_GH_PAYLOAD_LOG}"

: > "${FAKE_GH_LOG}"
export FAKE_GH_MODE=protected
SPLIT_YML="${TMP_DIR}/split.yml" "${ROOT_DIR}/bin/configure-split-tag-protection" --check >"${TMP_DIR}/protected.out"
[[ "$(grep -c 'repos/waaseyaa/.*/rulesets' "${FAKE_GH_LOG}")" -eq 4 ]]
! grep -q -- '--method' "${FAKE_GH_LOG}"
grep -q 'OK: 2 split mirrors' "${TMP_DIR}/protected.out"

echo "OK: split mirror tag-protection operator is idempotent and covers the matrix exactly once."
