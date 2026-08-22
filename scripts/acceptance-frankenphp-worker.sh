#!/usr/bin/env bash
# Real FrankenPHP worker-runtime acceptance for the shipped public/index.php.
# Missing binary is an infrastructure failure (exit 1), never a skip.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PIN_FILE="$ROOT/tools/frankenphp-runtime-pin.json"
PROBE="$ROOT/tests/Acceptance/FrankenPhpWorker/probe.php"
SEED="$ROOT/tests/Acceptance/FrankenPhpWorker/seed.php"
AUTOLOAD="$ROOT/vendor/autoload.php"
CONCURRENT_PID_ASSERT="$ROOT/tests/Acceptance/FrankenPhpWorker/assert-concurrent-pids.py"
LEAK_EXIT=42
TREE_SNAPSHOT=""

MODE="green"
SELF_TEST=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --inject-leak) MODE="leak"; shift ;;
    --self-test) SELF_TEST=1; shift ;;
    --classic-only) MODE="classic"; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

redact() {
  sed -E \
    -e 's/[Cc]ookie:[[:space:]]*[^[:space:]]+/Cookie: [redacted]/g' \
    -e 's/[Ss]et-[Cc]ookie:[[:space:]]*[^[:space:]]+/Set-Cookie: [redacted]/g' \
    -e 's/[Aa]uthorization:[[:space:]]*[^[:space:]]+/Authorization: [redacted]/g' \
    -e 's/(password|secret|token)([=:])[^[:space:]"]+/\1\2[redacted]/gi'
}

fail() {
  echo "ERROR: $*" >&2
  if [[ -n "${WORKER_LOG:-}" && -f "${WORKER_LOG}" ]]; then
    echo "----- redacted worker log (tail) -----" >&2
    tail -n 80 "$WORKER_LOG" | redact >&2
  fi
  exit 1
}

public_storage_leaked() {
  local root="${1:-$ROOT}"
  [[ -e "$root/public/storage" || -L "$root/public/storage" ]]
}

port_in_use() {
  ss -ltn 2>/dev/null | grep -qE ":${1}[[:space:]]"
}

require_pin() {
  [[ -f "$PIN_FILE" ]] || fail "missing pin file $PIN_FILE"
  python3 - "$PIN_FILE" <<'PINPY'
import json, re, sys
pin = json.load(open(sys.argv[1], encoding="utf-8"))
for key in ("version", "url", "sha256", "asset"):
    if not pin.get(key):
        raise SystemExit(f"pin missing {key}")
if not re.fullmatch(r"[0-9a-f]{64}", pin["sha256"]):
    raise SystemExit("pin sha256 must be 64 lowercase hex chars")
if pin["version"] not in pin["url"]:
    raise SystemExit("pin URL must contain the pinned version")
if ("get." + "frankenphp" + ".dev") in pin["url"]:
    raise SystemExit("unversioned installer URLs are forbidden")
print(pin["version"], pin["url"], pin["sha256"], pin["asset"])
PINPY
}

self_test_concurrent_pid_proofs() {
  [[ -f "$CONCURRENT_PID_ASSERT" ]] || fail "missing $CONCURRENT_PID_ASSERT"
  local tmp i
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/waaseyaa-frankenphp-pid-proof.XXXXXX")"
  for i in $(seq -w 1 20); do
    printf 'HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n' >"$tmp/${i}.headers"
  done
  if python3 "$CONCURRENT_PID_ASSERT" "$tmp" "12345"; then
    rm -rf -- "$tmp"
    fail "concurrent PID missing proof unexpectedly passed"
  fi
  for i in $(seq -w 1 20); do
    printf 'HTTP/1.1 200 OK\r\nX-Waaseyaa-Worker-Pid: 111\r\n\r\n' >"$tmp/${i}.headers"
  done
  printf 'HTTP/1.1 200 OK\r\nX-Waaseyaa-Worker-Pid: 222\r\n\r\n' >"$tmp/20.headers"
  if python3 "$CONCURRENT_PID_ASSERT" "$tmp" "111"; then
    rm -rf -- "$tmp"
    fail "concurrent PID changed proof unexpectedly passed"
  fi
  rm -rf -- "$tmp"
  echo "concurrent PID missing/changed proofs failed closed"
}

self_test_probe_mutations() {
  php -r '
require $argv[1];
$probe = "Waaseyaa\\FrankenPhp\\WorkerAcceptance";
if (!class_exists($probe)) {
    fwrite(STDERR, "WorkerAcceptance class missing from production autoload\n");
    exit(1);
}
$root = $argv[2];
$token = $probe::processToken([], ["HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE" => $probe::TOKEN]);
if ($token !== false) {
    fwrite(STDERR, "request-only activation unexpectedly succeeded\n");
    exit(1);
}
$missing = sys_get_temp_dir() . "/waaseyaa-missing-acceptance-" . uniqid("", true);
if (!mkdir($missing, 0755, true)) {
    fwrite(STDERR, "could not create missing-tests root\n");
    exit(1);
}
$probe::apply($missing, $probe::TOKEN, $probe::SAPI, [], false);
if (is_file($missing . "/tests/Acceptance/FrankenPhpWorker/probe.php")) {
    fwrite(STDERR, "absent tests tree unexpectedly grew extras\n");
    rmdir($missing);
    exit(1);
}
rmdir($missing);
unset($_SESSION);
$probe::apply($root, $probe::TOKEN, "cli", ["HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY" => "community-a"], false);
if (isset($_SESSION["waaseyaa_community_id"])) {
    fwrite(STDERR, "wrong SAPI unexpectedly activated extras\n");
    exit(1);
}
$evil = sys_get_temp_dir() . "/waaseyaa-evil-probe-" . uniqid("", true) . ".php";
file_put_contents($evil, "<?php throw new RuntimeException(\"evil probe executed\");");
$probe::apply($root, $probe::TOKEN, $probe::SAPI, [], $evil);
unlink($evil);
$probe::apply($root, $probe::TOKEN, $probe::SAPI, [], false);
$probe::apply($root, $probe::TOKEN, $probe::SAPI, [], false);
' "$AUTOLOAD" "$ROOT" || fail "probe activation mutations did not stay inert"
  echo "absent-tests / request-only / wrong-sapi / path-override / repeat proofs stayed inert"
}

self_test_public_storage_predicate() {
  if public_storage_leaked "$ROOT"; then
    fail "repository public/storage already leaked before self-test"
  fi
  local tmp
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/waaseyaa-frankenphp-storage-proof.XXXXXX")"
  mkdir -p "$tmp/public/storage"
  if ! public_storage_leaked "$tmp"; then
    rm -rf -- "$tmp"
    fail "public_storage_leaked missed a present artifact"
  fi
  rm -rf -- "$tmp"
  echo "public/storage artifact is treated as a custody failure"
}

if [[ "$SELF_TEST" -eq 1 ]]; then
  require_pin >/dev/null
  php -r 'require $argv[1];' "$ROOT/tests/Acceptance/FrankenPhpWorker/leak.php"
  self_test_concurrent_pid_proofs
  self_test_probe_mutations
  self_test_public_storage_predicate
  if [[ -x "${FRANKENPHP_BINARY:-}" ]]; then
    echo "self-test: pin valid; leak fixture loadable; binary present"
    exit 0
  fi
  echo "self-test: pin valid; leak fixture loadable"
  if [[ "${WAASEYAA_FRANKENPHP_SELF_TEST_REQUIRE_BINARY:-}" == "1" ]]; then
    fail "Set FRANKENPHP_BINARY to the pinned FrankenPHP binary"
  fi
  exit 0
fi

FRANKENPHP="${FRANKENPHP_BINARY:-}"
if [[ -z "$FRANKENPHP" || ! -x "$FRANKENPHP" ]]; then
  fail "Set FRANKENPHP_BINARY to an executable FrankenPHP binary (pinned ${PIN_FILE}). Missing binary is not skippable."
fi

read -r PIN_VERSION PIN_URL PIN_SHA PIN_ASSET < <(require_pin)

ACTUAL_SHA="$(sha256sum "$FRANKENPHP" | awk '{print $1}')"
if [[ "$ACTUAL_SHA" != "$PIN_SHA" ]]; then
  fail "FrankenPHP checksum mismatch: expected $PIN_SHA got $ACTUAL_SHA (source $PIN_URL asset $PIN_ASSET)"
fi

VERSION_OUT="$("$FRANKENPHP" version 2>&1 || true)"
echo "frankenphp version: $VERSION_OUT"
echo "$VERSION_OUT" | grep -Eq "FrankenPHP[[:space:]]+v?1\.12\.4" \
  || fail "runtime version did not assert FrankenPHP 1.12.4 from $VERSION_OUT"

PORT="${WAASEYAA_FRANKENPHP_ACCEPTANCE_PORT:-3055}"
CLASSIC_PORT="${WAASEYAA_FRANKENPHP_CLASSIC_PORT:-3056}"
occupy_check_ports=(3055 3056 3057 3058 "$PORT" "$CLASSIC_PORT")
seen_ports=" "
for occupy_port in "${occupy_check_ports[@]}"; do
  case "$seen_ports" in
    *" ${occupy_port} "*) continue ;;
  esac
  seen_ports+="${occupy_port} "
  if port_in_use "$occupy_port"; then
    fail "Port ${occupy_port} is already in use; refusing a contaminated listener."
  fi
done

if public_storage_leaked "$ROOT"; then
  fail "Refusing to start: ${ROOT}/public/storage exists (custody contamination)."
fi
TREE_SNAPSHOT="$(git -C "$ROOT" status --porcelain)"

RUNTIME_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/waaseyaa-frankenphp-acceptance.XXXXXX")"
WORKER_LOG="$RUNTIME_ROOT/worker.log"
CLASSIC_LOG="$RUNTIME_ROOT/classic.log"
SESSION_DIR="$RUNTIME_ROOT/sessions"
INI_DIR="$RUNTIME_ROOT/php-ini.d"
mkdir -p "$SESSION_DIR" "$INI_DIR"

export APP_ENV=production
export APP_DEBUG=false
export WAASEYAA_DEV_FALLBACK_ACCOUNT=false
export WAASEYAA_APP_SECRET="${WAASEYAA_APP_SECRET:-base64:$(php -r 'echo base64_encode(random_bytes(32));')}"
export AUTH_TOKEN_SECRET="$(php -r 'echo "base64:" . base64_encode(random_bytes(32));')"
export WAASEYAA_JWT_SECRET="${WAASEYAA_JWT_SECRET:-$(php -r 'echo bin2hex(random_bytes(32));')}"
export WAASEYAA_DB="$RUNTIME_ROOT/acceptance.sqlite"
export WAASEYAA_FILES_DIR="$RUNTIME_ROOT/files"
export WAASEYAA_STORAGE_PATH="$RUNTIME_ROOT/storage"
export WAASEYAA_MAINTENANCE_FLAG="$RUNTIME_ROOT/maintenance.flag"
export WAASEYAA_MAINTENANCE_TRUST_LOCALHOST=false
mkdir -p "$WAASEYAA_FILES_DIR" "$WAASEYAA_STORAGE_PATH"

if [[ "$MODE" == "leak" ]]; then
  export WAASEYAA_FRANKENPHP_LEAK_PROOF=1
else
  unset WAASEYAA_FRANKENPHP_LEAK_PROOF || true
fi

cat >"$INI_DIR/acceptance.ini" <<EOF
session.save_path=${SESSION_DIR}
display_errors=Off
display_startup_errors=Off
EOF

CADDYFILE="$RUNTIME_ROOT/Caddyfile"
# FrankenPHP workers do not reliably inherit the parent process environment.
# Pin the same values the CLI seed/preflight used so HTTP boot sees the same DB.
cat >"$CADDYFILE" <<EOF
{
	admin off
	frankenphp {
		php_ini display_errors Off
		php_ini display_startup_errors Off
		worker {
			file ${ROOT}/public/index.php
			num 1
			env APP_ENV "${APP_ENV}"
			env APP_DEBUG "${APP_DEBUG}"
			env WAASEYAA_DEV_FALLBACK_ACCOUNT "${WAASEYAA_DEV_FALLBACK_ACCOUNT}"
			env WAASEYAA_APP_SECRET "${WAASEYAA_APP_SECRET}"
			env AUTH_TOKEN_SECRET "${AUTH_TOKEN_SECRET}"
			env WAASEYAA_FRANKENPHP_ACCEPTANCE worker-lane-v1
			env WAASEYAA_STORAGE_PATH "${WAASEYAA_STORAGE_PATH}"
			env WAASEYAA_JWT_SECRET "${WAASEYAA_JWT_SECRET}"
			env WAASEYAA_DB "${WAASEYAA_DB}"
			env WAASEYAA_FILES_DIR "${WAASEYAA_FILES_DIR}"
			env WAASEYAA_MAINTENANCE_FLAG "${WAASEYAA_MAINTENANCE_FLAG}"
			env WAASEYAA_MAINTENANCE_TRUST_LOCALHOST "${WAASEYAA_MAINTENANCE_TRUST_LOCALHOST}"
			env WAASEYAA_FRANKENPHP_LEAK_PROOF "${WAASEYAA_FRANKENPHP_LEAK_PROOF:-}"
			env PHP_INI_SCAN_DIR "${ROOT}/config/frankenphp:${INI_DIR}"
		}
	}
}

http://127.0.0.1:${PORT} {
	root ${ROOT}/public
	php_server
}
EOF

WORKER_PID=""
CLASSIC_PID=""
cleanup() {
  exit_code=$?
  trap - EXIT
  local remaining="" occupy_port seen_ports
  if [[ -n "${WORKER_PID}" ]]; then
    if kill -0 "${WORKER_PID}" 2>/dev/null; then
      kill -TERM "${WORKER_PID}" 2>/dev/null || true
      for _ in $(seq 1 25); do
        kill -0 "${WORKER_PID}" 2>/dev/null || break
        sleep 0.1
      done
    fi
    if kill -0 "${WORKER_PID}" 2>/dev/null; then
      echo "FrankenPHP worker ${WORKER_PID} did not exit; sending KILL." >&2
      kill -KILL "${WORKER_PID}" 2>/dev/null || true
      [[ "$exit_code" -ne 0 ]] || exit_code=1
    else
      wait "${WORKER_PID}" 2>/dev/null || true
    fi
  fi
  if [[ -n "${CLASSIC_PID}" ]]; then
    if kill -0 "${CLASSIC_PID}" 2>/dev/null; then
      kill -TERM "${CLASSIC_PID}" 2>/dev/null || true
      wait "${CLASSIC_PID}" 2>/dev/null || true
    fi
  fi
  remaining="$(ps -eo pid= | while read -r pid; do
    exe="$(readlink -f "/proc/${pid}/exe" 2>/dev/null || true)"
    if [[ -n "${FRANKENPHP:-}" && "$exe" == "$(readlink -f "$FRANKENPHP")" ]]; then
      echo "$pid"
    fi
  done | tr '\n' ' ')"
  if [[ -n "${remaining// }" ]]; then
    echo "Surviving FrankenPHP processes after cleanup: ${remaining}" >&2
    [[ "$exit_code" -ne 0 ]] || exit_code=1
  fi
  seen_ports=" "
  for occupy_port in 3055 3056 3057 3058 "${PORT:-}" "${CLASSIC_PORT:-}"; do
    [[ -n "$occupy_port" ]] || continue
    case "$seen_ports" in
      *" ${occupy_port} "*) continue ;;
    esac
    seen_ports+="${occupy_port} "
    if port_in_use "$occupy_port"; then
      echo "Port ${occupy_port} is still listening after FrankenPHP shutdown." >&2
      [[ "$exit_code" -ne 0 ]] || exit_code=1
    fi
  done
  if [[ "${PREFLIGHT_CREATED:-0}" -eq 1 ]]; then
    rm -f "$ROOT/.waaseyaa/field-access-preflight.json"
    rm -f "$ROOT/.waaseyaa/field-access-classification.json"
    rmdir "$ROOT/.waaseyaa" 2>/dev/null || true
  fi
  if [[ -n "${RUNTIME_ROOT:-}" ]]; then
    rm -rf -- "$RUNTIME_ROOT"
    if [[ -e "$RUNTIME_ROOT" ]]; then
      echo "RUNTIME_ROOT still exists after cleanup: ${RUNTIME_ROOT}" >&2
      [[ "$exit_code" -ne 0 ]] || exit_code=1
    fi
  fi
  if public_storage_leaked "$ROOT"; then
    echo "public/storage leaked under ${ROOT} after cleanup." >&2
    [[ "$exit_code" -ne 0 ]] || exit_code=1
  fi
  local after
  after="$(git -C "$ROOT" status --porcelain)"
  if [[ "$after" != "$TREE_SNAPSHOT" ]]; then
    echo "git status --porcelain drifted from TREE_SNAPSHOT." >&2
    echo "before:" >&2
    printf '%s\n' "$TREE_SNAPSHOT" >&2
    echo "after:" >&2
    printf '%s\n' "$after" >&2
    [[ "$exit_code" -ne 0 ]] || exit_code=1
  fi
  exit "$exit_code"
}
trap cleanup EXIT

rm -f "$WAASEYAA_DB" "$WAASEYAA_DB-shm" "$WAASEYAA_DB-wal"
# install:init must not run as APP_ENV=production: production refuses a missing sqlite file.
# Genesis activation still writes into WAASEYAA_DB; the worker run below stays production.
APP_ENV=local "$FRANKENPHP" php-cli "$ROOT/packages/cli/bin/waaseyaa" install:init >"$RUNTIME_ROOT/db-init.log"
"$FRANKENPHP" php-cli "$SEED" "$ROOT" "$RUNTIME_ROOT/ids.json"
IDS_JSON="$RUNTIME_ROOT/ids.json"
PREFLIGHT_ARTIFACT="$ROOT/.waaseyaa/field-access-preflight.json"
PREFLIGHT_CREATED=0
write_preflight_with_frankenphp_sapi() {
  local pf_caddy="$RUNTIME_ROOT/Caddyfile.preflight"
  local pf_log="$RUNTIME_ROOT/preflight-worker.log"
  cat >"$pf_caddy" <<EOF
{
	admin off
	frankenphp {
		php_ini display_errors Off
		php_ini display_startup_errors Off
		worker {
			file ${ROOT}/tests/Acceptance/FrankenPhpWorker/write-preflight-worker.php
			num 1
			env APP_ENV "${APP_ENV}"
			env APP_DEBUG "${APP_DEBUG}"
			env WAASEYAA_DEV_FALLBACK_ACCOUNT "${WAASEYAA_DEV_FALLBACK_ACCOUNT}"
			env WAASEYAA_APP_SECRET "${WAASEYAA_APP_SECRET}"
			env AUTH_TOKEN_SECRET "${AUTH_TOKEN_SECRET}"
			env WAASEYAA_STORAGE_PATH "${WAASEYAA_STORAGE_PATH}"
			env WAASEYAA_JWT_SECRET "${WAASEYAA_JWT_SECRET}"
			env WAASEYAA_DB "${WAASEYAA_DB}"
			env WAASEYAA_FILES_DIR "${WAASEYAA_FILES_DIR}"
			env WAASEYAA_MAINTENANCE_FLAG "${WAASEYAA_MAINTENANCE_FLAG}"
			env WAASEYAA_MAINTENANCE_TRUST_LOCALHOST "${WAASEYAA_MAINTENANCE_TRUST_LOCALHOST}"
			env PHP_INI_SCAN_DIR "${ROOT}/config/frankenphp:${INI_DIR}"
		}
	}
}
http://127.0.0.1:${PORT} {
	root ${ROOT}/public
	php_server
}
EOF
  "$FRANKENPHP" run --config "$pf_caddy" --adapter caddyfile >"$pf_log" 2>&1 &
  local pf_pid=$!
  local written=0
  for _ in $(seq 1 200); do
    if [[ -f "$PREFLIGHT_ARTIFACT" ]] && grep -q '"ready": true' "$PREFLIGHT_ARTIFACT"; then
      written=1
      break
    fi
    sleep 0.1
  done
  if kill -0 "$pf_pid" 2>/dev/null; then
    kill -TERM "$pf_pid" 2>/dev/null || true
    wait "$pf_pid" 2>/dev/null || true
  fi
  if [[ "$written" -ne 1 ]]; then
    echo "----- preflight worker log -----" >&2
    tail -n 80 "$pf_log" >&2 || true
    fail "FrankenPHP-SAPI preflight worker did not write a ready artifact"
  fi
  echo "field-access preflight artifact ready (frankenphp sapi)"
}
if [[ ! -f "$PREFLIGHT_ARTIFACT" ]]; then
  PREFLIGHT_CREATED=1
  write_preflight_with_frankenphp_sapi
fi

export PHP_INI_SCAN_DIR="${ROOT}/config/frankenphp:${INI_DIR}"
export XDG_CONFIG_HOME="$RUNTIME_ROOT/config"
export XDG_DATA_HOME="$RUNTIME_ROOT/data"

wait_ready() {
  local listen_port="$1"
  local ready=0
  for _ in $(seq 1 250); do
    if curl -sf --max-time 2 -o /dev/null "http://127.0.0.1:${listen_port}/.well-known/waaseyaa-anchors.json"; then
      ready=1
      break
    fi
    sleep 0.1
  done
  [[ "$ready" -eq 1 ]] || fail "Listener did not become ready on port ${listen_port}"
}

assert_listener_is_binary() {
  local listen_port="$1"
  local listener_pid=""
  listener_pid="$(ss -ltnp 2>/dev/null | awk -v p=":${listen_port}" '$4 ~ p"$" {print}' | sed -n 's/.*pid=\([0-9]*\).*/\1/p' | head -n 1)"
  [[ -n "$listener_pid" ]] || fail "Could not resolve listener PID on port ${listen_port}"
  local exe
  exe="$(readlink -f "/proc/${listener_pid}/exe")"
  local expected
  expected="$(readlink -f "$FRANKENPHP")"
  [[ "$exe" == "$expected" ]] || fail "Listener PID ${listener_pid} exe ${exe} is not ${expected}"
  echo "listener pid ${listener_pid} exe ${exe}"
}

request() {
  local method="$1"
  local url="$2"
  local extra=("${@:3}")
  local headers="$RUNTIME_ROOT/last.headers"
  local body="$RUNTIME_ROOT/last.body"
  curl -sS -D "$headers" -o "$body" -X "$method" "${extra[@]}" "$url"
  python3 - "$headers" "$body" <<'REQPY'
import pathlib, sys
headers_path, body_path = sys.argv[1], sys.argv[2]
raw = pathlib.Path(headers_path).read_text(errors="replace")
status = "000"
server = ""
pid = ""
sapi = ""
leak = ""
community = ""
leak_community = ""
ctype = ""
for line in raw.splitlines():
    if line.startswith("HTTP/"):
        parts = line.split()
        if len(parts) >= 2:
            status = parts[1]
    lower = line.lower()
    if lower.startswith("server:"):
        server = line.split(":", 1)[1].strip()
    if lower.startswith("x-waaseyaa-worker-pid:"):
        pid = line.split(":", 1)[1].strip()
    if lower.startswith("x-waaseyaa-acceptance-sapi:"):
        sapi = line.split(":", 1)[1].strip()
    if lower.startswith("x-waaseyaa-leak-previous:"):
        leak = line.split(":", 1)[1].strip()
    if lower.startswith("x-waaseyaa-community-seen:"):
        community = line.split(":", 1)[1].strip()
    if lower.startswith("x-waaseyaa-leak-community:"):
        leak_community = line.split(":", 1)[1].strip()
    if lower.startswith("content-type:"):
        ctype = line.split(":", 1)[1].strip()
body = pathlib.Path(body_path).read_text(errors="replace")
print("\n".join([status, server, pid, sapi, leak, community, leak_community, ctype, str(len(body))]))
REQPY
}

login() {
  local username="$1"
  local password="$2"
  local cookie_jar="$3"
  curl -sS -c "$cookie_jar" -b "$cookie_jar" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -o "$RUNTIME_ROOT/login.body" -w '%{http_code}' \
    --data "{\"username\":\"${username}\",\"password\":\"${password}\"}" \
    "http://127.0.0.1:${PORT}/api/auth/login"
}

if [[ "$MODE" != "classic" ]]; then
  export WAASEYAA_FRANKENPHP_ACCEPTANCE=worker-lane-v1
  "$FRANKENPHP" run --config "$CADDYFILE" --adapter caddyfile >"$WORKER_LOG" 2>&1 &
  WORKER_PID=$!
  wait_ready "$PORT"
  assert_listener_is_binary "$PORT"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
  STATUS="${META[0]}"; SERVER="${META[1]}"; WORKER_IDENT="${META[2]}"; SAPI="${META[3]}"
  [[ "$STATUS" == "200" ]] || fail "public anchors expected 200, got $STATUS"
  echo "$SERVER" | grep -Ei 'frankenphp|caddy' >/dev/null || fail "Server header did not identify FrankenPHP/Caddy: $SERVER"
  [[ -n "$WORKER_IDENT" ]] || fail "worker PID header missing"
  echo "worker identity pid=${WORKER_IDENT} sapi=${SAPI} server=${SERVER}"

  SERIAL_FILE="$RUNTIME_ROOT/serial-status.txt"
  SERIAL_PIDS="$RUNTIME_ROOT/serial-pids.txt"
  : >"$SERIAL_FILE"
  : >"$SERIAL_PIDS"
  for _ in $(seq 1 20); do
    mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
    echo "${META[0]}" >>"$SERIAL_FILE"
    echo "${META[2]}" >>"$SERIAL_PIDS"
  done
  python3 - "$SERIAL_FILE" "$SERIAL_PIDS" "$WORKER_IDENT" <<'SERIALPY'
from pathlib import Path
import sys
status = Path(sys.argv[1]).read_text().split()
pids = [p for p in Path(sys.argv[2]).read_text().split() if p]
ident = sys.argv[3]
assert status == ["200"] * 20, status
assert pids == [ident] * 20, (pids, ident)
print("serial public", len(status), "same worker", ident)
SERIALPY

  CONCURRENT_DIR="$RUNTIME_ROOT/concurrent-headers"
  mkdir -p "$CONCURRENT_DIR"
  seq -w 1 20 | xargs -P 20 -I{} curl -sS -D "$CONCURRENT_DIR/{}.headers" -o "$CONCURRENT_DIR/{}.body" \
    "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json"
  python3 "$CONCURRENT_PID_ASSERT" "$CONCURRENT_DIR" "$WORKER_IDENT"
  assert_listener_is_binary "$PORT"
  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
  [[ "${META[0]}" == "200" && "${META[2]}" == "$WORKER_IDENT" ]] || fail "post-burst request left the retained worker"

  ALICE_USER="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["alice"]["name"])' "$IDS_JSON")"
  ALICE_PASS="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["alice"]["password"])' "$IDS_JSON")"
  BOB_USER="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["bob"]["name"])' "$IDS_JSON")"
  BOB_PASS="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["bob"]["password"])' "$IDS_JSON")"
  ALPHA_ID="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["nodes"]["alpha"])' "$IDS_JSON")"
  BETA_ID="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["nodes"]["beta"])' "$IDS_JSON")"
  HIDDEN_ID="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["nodes"]["hidden"])' "$IDS_JSON")"

  ALICE_JAR="$RUNTIME_ROOT/alice.jar"
  BOB_JAR="$RUNTIME_ROOT/bob.jar"
  ALICE_LOGIN="$(login "$ALICE_USER" "$ALICE_PASS" "$ALICE_JAR")"
  BOB_LOGIN="$(login "$BOB_USER" "$BOB_PASS" "$BOB_JAR")"
  [[ "$ALICE_LOGIN" == "200" ]] || fail "alice login expected 200, got $ALICE_LOGIN body=$(redact < "$RUNTIME_ROOT/login.body")"
  [[ "$BOB_LOGIN" == "200" ]] || fail "bob login expected 200, got $BOB_LOGIN"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/user/me" -b "$ALICE_JAR" -H "X-Waaseyaa-Acceptance-Mark: alice" -H "X-Waaseyaa-Acceptance-Community: community-a")
  [[ "${META[0]}" == "200" ]] || fail "alice /api/user/me expected 200, got ${META[0]}"
  [[ "${META[2]}" == "$WORKER_IDENT" ]] || fail "alice request left the retained worker"
  grep -q 'acceptance-alice' "$RUNTIME_ROOT/last.body" || fail "alice me payload missing alice identity"
  grep -q 'acceptance-bob' "$RUNTIME_ROOT/last.body" && fail "alice me payload leaked bob"
  ALICE_LEAK="${META[4]}"
  ALICE_COMMUNITY="${META[5]}"
  [[ "$ALICE_COMMUNITY" == "community-a" ]] || fail "community-a was not seen on alice request"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/user/me" -b "$BOB_JAR" -H "X-Waaseyaa-Acceptance-Mark: bob" -H "X-Waaseyaa-Acceptance-Community: community-b")
  [[ "${META[0]}" == "200" ]] || fail "bob /api/user/me expected 200, got ${META[0]}"
  [[ "${META[2]}" == "$WORKER_IDENT" ]] || fail "bob request left the retained worker"
  grep -q 'acceptance-bob' "$RUNTIME_ROOT/last.body" || fail "bob me payload missing bob identity"
  grep -q 'acceptance-alice' "$RUNTIME_ROOT/last.body" && fail "bob me payload leaked alice"
  BOB_LEAK="${META[4]}"
  BOB_COMMUNITY="${META[5]}"
  BOB_LEAK_COMMUNITY="${META[6]}"
  [[ "$BOB_COMMUNITY" == "community-b" ]] || fail "community-b was not seen on bob request"

  if [[ "$MODE" == "leak" ]]; then
    if [[ "$BOB_LEAK" == "alice" && "$BOB_LEAK_COMMUNITY" == "community-a" ]]; then
      echo "adversarial leak fixture retained alice/community-a into bob request"
      exit "$LEAK_EXIT"
    fi
    fail "adversarial leak fixture did not retain cross-request state (leak='${BOB_LEAK}' community='${BOB_LEAK_COMMUNITY}')"
  fi
  [[ -z "$ALICE_LEAK" && -z "$BOB_LEAK" ]] || fail "green run leaked previous mark: alice='${ALICE_LEAK}' bob='${BOB_LEAK}'"
  [[ -z "$BOB_LEAK_COMMUNITY" ]] || fail "green run leaked previous community '${BOB_LEAK_COMMUNITY}'"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/user/me")
  [[ "${META[0]}" == "401" ]] || fail "anonymous /api/user/me expected 401, got ${META[0]}"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/entity-types")
  [[ "${META[0]}" == "403" || "${META[0]}" == "401" ]] || fail "anonymous admin route expected forbidden, got ${META[0]}"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/node/${HIDDEN_ID}")
  [[ "${META[0]}" == "404" ]] || fail "concealed unpublished node expected 404, got ${META[0]}"
  grep -qi 'Acceptance Concealed Draft Token' "$RUNTIME_ROOT/last.body" && fail "concealed node leaked title"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/api/node/${HIDDEN_ID}" -b "$ALICE_JAR")
  [[ "${META[0]}" == "200" ]] || fail "admin unpublished node expected 200, got ${META[0]}"

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/node/${ALPHA_ID}" -b "$ALICE_JAR")
  ALPHA_BODY="$(cat "$RUNTIME_ROOT/last.body")"
  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/node/${BETA_ID}" -b "$BOB_JAR")
  BETA_BODY="$(cat "$RUNTIME_ROOT/last.body")"
  echo "$ALPHA_BODY" | grep -q 'Acceptance Beta Twig Unique Token' && fail "alpha HTML/JSON contained beta Twig token"
  echo "$BETA_BODY" | grep -q 'Acceptance Alpha Twig Unique Token' && fail "beta HTML/JSON contained alpha Twig token"

  STREAM_HEADERS="$RUNTIME_ROOT/stream.headers"
  STREAM_BODY="$RUNTIME_ROOT/stream.body"
  curl -sS -N --max-time 2 -D "$STREAM_HEADERS" -o "$STREAM_BODY" \
    -H 'Accept: text/event-stream' \
    -b "$ALICE_JAR" \
    "http://127.0.0.1:${PORT}/api/broadcast" || true
  python3 - "$STREAM_HEADERS" "$STREAM_BODY" <<'STREAMPY'
from pathlib import Path
import sys
headers = Path(sys.argv[1]).read_text(errors="replace").lower()
body = Path(sys.argv[2]).read_bytes()
if "text/event-stream" not in headers and b"event:" not in body and b"data:" not in body:
    raise SystemExit("streamed /api/broadcast did not look like an SSE response")
print("streamed broadcast accepted")
STREAMPY

  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/no-such-acceptance-route-${RANDOM}")
  [[ "${META[0]}" == "404" ]] || fail "missing route expected 404, got ${META[0]}"
  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
  [[ "${META[0]}" == "200" && "${META[2]}" == "$WORKER_IDENT" ]] || fail "recovery request after 404 failed"

  "$FRANKENPHP" php-cli "$ROOT/packages/cli/bin/waaseyaa" maintenance:on >/dev/null
  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
  [[ "${META[0]}" == "503" ]] || fail "maintenance expected 503, got ${META[0]}"
  "$FRANKENPHP" php-cli "$ROOT/packages/cli/bin/waaseyaa" maintenance:off >/dev/null
  mapfile -t META < <(request GET "http://127.0.0.1:${PORT}/.well-known/waaseyaa-anchors.json")
  [[ "${META[0]}" == "200" && "${META[2]}" == "$WORKER_IDENT" ]] || fail "recovery after maintenance failed"

  echo "worker-mode acceptance green pid=${WORKER_IDENT}"
  unset WAASEYAA_FRANKENPHP_ACCEPTANCE || true
fi

if ss -ltn 2>/dev/null | grep -qE ":${CLASSIC_PORT}[[:space:]]"; then
  fail "Classic port ${CLASSIC_PORT} is already in use."
fi
"$FRANKENPHP" php-server --root "$ROOT/public" --listen "127.0.0.1:${CLASSIC_PORT}" >"$CLASSIC_LOG" 2>&1 &
CLASSIC_PID=$!
wait_ready "$CLASSIC_PORT"
mapfile -t META < <(request GET "http://127.0.0.1:${CLASSIC_PORT}/.well-known/waaseyaa-anchors.json")
[[ "${META[0]}" == "200" ]] || fail "classic FrankenPHP php-server expected 200, got ${META[0]}"
echo "${META[1]}" | grep -Ei 'frankenphp|caddy' >/dev/null || fail "classic Server header did not identify FrankenPHP/Caddy: ${META[1]}"
echo "classic FrankenPHP fallback 200 server=${META[1]}"