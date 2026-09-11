# Shared bounded child execution for packaged-form harnesses.
#
# Requires: coreutils `timeout`. Optional: util-linux tools are not required;
# process-group isolation uses bash monitor mode so the background leader's
# PID is its PGID deterministically (no post-spawn `ps` adoption race).
#
# Caller must set BOUNDED_LOG_DIR to a writable directory for per-label logs.
#
# Contract (docs/local-testing-policy.md — new subprocess harnesses):
# - Every child runs under an explicit deadline.
# - On expiry: SIGTERM, then SIGKILL after 5s (--kill-after).
# - After the direct leader exits — including exit 0 — surviving descendants
#   in the owned process group are reaped before custody is cleared.
# - EXIT cleanup reaps any still-owned process group before tree removal so
#   `rm -rf` cannot hang on a live child.
# - Timeout exit is deterministic: status 124 and a stderr diagnostic naming
#   the label and deadline.
# - Custody never adopts the caller's process group.

BOUNDED_OWNED_PGID=''
BOUNDED_OWNED_PID=''

_bounded_pid_alive() {
    local pid="${1:-}"
    [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null
}

_bounded_process_group_alive() {
    local pgid="${1:-}"
    [[ -n "$pgid" ]] || return 1
    # A process group is alive when signalling it by id succeeds.
    kill -0 -- "-$pgid" 2>/dev/null
}

_bounded_read_pgrp() {
    local pid="${1:-}"
    [[ -n "$pid" && -r "/proc/${pid}/stat" ]] || return 1
    # proc(5): field 5 is pgrp.
    awk '{print $5}' "/proc/${pid}/stat" 2>/dev/null
}

reap_bounded_owned_child() {
    local pgid="${BOUNDED_OWNED_PGID:-}"
    local pid="${BOUNDED_OWNED_PID:-}"

    if [[ -z "$pgid" && -z "$pid" ]]; then
        return 0
    fi

    if [[ -n "$pgid" ]]; then
        kill -TERM -- "-$pgid" 2>/dev/null || true
    fi
    if [[ -n "$pid" ]]; then
        kill -TERM "$pid" 2>/dev/null || true
    fi

    local _i
    for _i in 1 2 3 4 5 6 7 8 9 10; do
        if ! _bounded_process_group_alive "$pgid" && ! _bounded_pid_alive "$pid"; then
            break
        fi
        sleep 0.1
    done

    if _bounded_process_group_alive "$pgid"; then
        kill -KILL -- "-$pgid" 2>/dev/null || true
    fi
    if _bounded_pid_alive "$pid"; then
        kill -KILL "$pid" 2>/dev/null || true
    fi

    if [[ -n "$pid" ]]; then
        wait "$pid" 2>/dev/null || true
    fi

    for _i in 1 2 3 4 5 6 7 8 9 10; do
        if ! _bounded_process_group_alive "$pgid" && ! _bounded_pid_alive "$pid"; then
            break
        fi
        sleep 0.1
    done

    # Clear custody only after the owned group/leader are gone (best-effort).
    BOUNDED_OWNED_PGID=''
    BOUNDED_OWNED_PID=''
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
    if [[ ! "$seconds" =~ ^[1-9][0-9]*$ ]]; then
        echo "run_bounded: deadline seconds must be a positive integer (got: ${seconds})." >&2
        return 2
    fi

    local out="${BOUNDED_LOG_DIR}/${label}.out"
    mkdir -p "$BOUNDED_LOG_DIR"

    local caller_pgid
    caller_pgid="$(_bounded_read_pgrp "$$" || true)"
    local monitor_was_on=0
    [[ $- == *m* ]] && monitor_was_on=1

    # Monitor mode makes the background job a new process-group leader whose
    # PGID equals its PID — known before any descendant can start.
    set -m
    set +e
    timeout --signal=TERM --kill-after=5s "${seconds}s" "$@" >"$out" 2>&1 &
    BOUNDED_OWNED_PID=$!

    local adopted=0
    local child_pgid=''
    local _i
    for _i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
        child_pgid="$(_bounded_read_pgrp "$BOUNDED_OWNED_PID" || true)"
        if [[ "$child_pgid" =~ ^[1-9][0-9]*$ \
            && "$child_pgid" == "$BOUNDED_OWNED_PID" \
            && "$child_pgid" != "$caller_pgid" ]]; then
            BOUNDED_OWNED_PGID="$child_pgid"
            adopted=1
            break
        fi
        if ! _bounded_pid_alive "$BOUNDED_OWNED_PID"; then
            break
        fi
        sleep 0.05
    done

    if [[ "$adopted" -ne 1 ]]; then
        echo "run_bounded: refused to adopt process group for ${label} (caller_pgid=${caller_pgid:-unknown} child=${BOUNDED_OWNED_PID})." >&2
        if _bounded_pid_alive "$BOUNDED_OWNED_PID"; then
            kill -TERM "$BOUNDED_OWNED_PID" 2>/dev/null || true
            sleep 0.2
            kill -KILL "$BOUNDED_OWNED_PID" 2>/dev/null || true
            wait "$BOUNDED_OWNED_PID" 2>/dev/null || true
        fi
        BOUNDED_OWNED_PID=''
        BOUNDED_OWNED_PGID=''
        [[ "$monitor_was_on" -eq 0 ]] && set +m
        set -e
        return 126
    fi

    wait "$BOUNDED_OWNED_PID"
    local status=$?
    # Leader may have exited 0 after backgrounding a descendant. Hold PGID and
    # reap the whole owned group before releasing custody.
    reap_bounded_owned_child
    [[ "$monitor_was_on" -eq 0 ]] && set +m
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
