# Shared bounded child execution for packaged-form harnesses.
#
# Requires: coreutils `timeout`, util-linux `setsid`.
# Caller must set BOUNDED_LOG_DIR to a writable directory for per-label logs.
#
# Contract (docs/local-testing-policy.md — new subprocess harnesses):
# - Every child runs under an explicit deadline.
# - On expiry: SIGTERM, then SIGKILL after 5s (--kill-after).
# - EXIT cleanup reaps any still-owned process group before tree removal so
#   `rm -rf` cannot hang on a live child.
# - Timeout exit is deterministic: status 124 and a stderr diagnostic naming
#   the label and deadline.

BOUNDED_OWNED_PGID=''
BOUNDED_OWNED_PID=''

reap_bounded_owned_child() {
    local pgid="${BOUNDED_OWNED_PGID:-}"
    local pid="${BOUNDED_OWNED_PID:-}"
    BOUNDED_OWNED_PGID=''
    BOUNDED_OWNED_PID=''

    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
        kill -TERM "$pid" 2>/dev/null || true
    fi
    if [[ -n "$pgid" ]] && kill -0 -- "-$pgid" 2>/dev/null; then
        kill -TERM -- "-$pgid" 2>/dev/null || true
    fi

    local _i
    for _i in 1 2 3 4 5 6 7 8 9 10; do
        local alive=0
        if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
            alive=1
        fi
        if [[ -n "$pgid" ]] && kill -0 -- "-$pgid" 2>/dev/null; then
            alive=1
        fi
        [[ "$alive" -eq 0 ]] && break
        sleep 0.1
    done

    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
    fi
    if [[ -n "$pgid" ]] && kill -0 -- "-$pgid" 2>/dev/null; then
        kill -KILL -- "-$pgid" 2>/dev/null || true
    fi
    if [[ -n "$pid" ]]; then
        wait "$pid" 2>/dev/null || true
    fi
}

# run_bounded LABEL SECONDS COMMAND [ARG...]
# Writes combined stdout/stderr to "$BOUNDED_LOG_DIR/${LABEL}.out".
run_bounded() {
    local label="$1"
    local seconds="$2"
    shift 2

    if [[ -z "${BOUNDED_LOG_DIR:-}" ]]; then
        echo 'run_bounded: BOUNDED_LOG_DIR must be set.' >&2
        return 2
    fi
    if ! command -v timeout >/dev/null 2>&1; then
        echo 'run_bounded: coreutils timeout is required for bounded child execution.' >&2
        return 127
    fi
    if ! command -v setsid >/dev/null 2>&1; then
        echo 'run_bounded: util-linux setsid is required for process-group cleanup.' >&2
        return 127
    fi
    if [[ ! "$seconds" =~ ^[1-9][0-9]*$ ]]; then
        echo "run_bounded: deadline seconds must be a positive integer (got: ${seconds})." >&2
        return 2
    fi

    local out="${BOUNDED_LOG_DIR}/${label}.out"
    mkdir -p "$BOUNDED_LOG_DIR"

    # New session so TERM/KILL can target the whole owned process group from
    # both timeout escalation and EXIT cleanup.
    set +e
    setsid timeout --signal=TERM --kill-after=5s "${seconds}s" "$@" >"$out" 2>&1 &
    BOUNDED_OWNED_PID=$!
    BOUNDED_OWNED_PGID="$(ps -o pgid= -p "$BOUNDED_OWNED_PID" 2>/dev/null | tr -d '[:space:]' || true)"
    wait "$BOUNDED_OWNED_PID"
    local status=$?
    BOUNDED_OWNED_PID=''
    BOUNDED_OWNED_PGID=''
    set -e

    # GNU timeout uses 124 on expiry; 137 is common after SIGKILL.
    if [[ "$status" -eq 124 || "$status" -eq 137 ]]; then
        echo "Bounded deadline exceeded for ${label} after ${seconds}s (sent TERM, then KILL)." >&2
        if [[ -f "$out" ]]; then
            echo "--- ${label}.out (tail) ---" >&2
            tail -n 80 "$out" >&2 || true
        fi
        return 124
    fi

    return "$status"
}
