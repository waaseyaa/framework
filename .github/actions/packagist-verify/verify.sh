#!/usr/bin/env bash
set -uo pipefail

ACTION_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=packagist-lib.sh
source "${ACTION_DIR}/packagist-lib.sh"

packages="${PACKAGIST_PACKAGES:-}"
packages_file="${PACKAGIST_PACKAGES_FILE:-}"
tag="${PACKAGIST_TAG:-}"
max_attempts="${PACKAGIST_MAX_ATTEMPTS:-24}"
deadline_seconds="${PACKAGIST_DEADLINE_SECONDS:-0}"
interval_min="${PACKAGIST_INTERVAL_MIN_SECONDS:-30}"
interval_max="${PACKAGIST_INTERVAL_MAX_SECONDS:-30}"
dry_run="${PACKAGIST_DRY_RUN:-false}"
recovery_command="${PACKAGIST_RECOVERY_COMMAND:-false}"

packagist_validate_tag "$tag" || exit 1
packagist_validate_positive_int max-attempts "$max_attempts" || exit 1
packagist_validate_uint deadline-seconds "$deadline_seconds" || exit 1
packagist_validate_uint interval-min-seconds "$interval_min" || exit 1
packagist_validate_uint interval-max-seconds "$interval_max" || exit 1
packagist_validate_bool dry-run "$dry_run" || exit 1
packagist_validate_bool recovery-command "$recovery_command" || exit 1
if [ "$interval_max" -lt "$interval_min" ]; then
  packagist_error 'interval-max-seconds must be greater than or equal to interval-min-seconds.'
  exit 1
fi
packagist_load_packages "$packages" "$packages_file" || exit 1

if [ "$dry_run" = true ]; then
  printf 'DRY RUN: would verify %s package(s) expose %s in Packagist P2 metadata.\n' \
    "${#PACKAGIST_PACKAGE_LIST[@]}" "$tag"
  printf 'DRY RUN package: %s\n' "${PACKAGIST_PACKAGE_LIST[@]}"
  exit 0
fi

remaining=("${PACKAGIST_PACKAGE_LIST[@]}")
start=$(date +%s)
deadline=0
if [ "$deadline_seconds" -gt 0 ]; then
  deadline=$((start + deadline_seconds))
fi
attempt=1

while [ "${#remaining[@]}" -gt 0 ]; do
  still_missing=()
  for package in "${remaining[@]}"; do
    if packagist_version_visible "$package" "$tag"; then
      echo "::notice::${package}: ${tag} visible after $(( $(date +%s) - start ))s (attempt ${attempt})."
    else
      still_missing+=("$package")
    fi
  done
  remaining=("${still_missing[@]}")
  [ "${#remaining[@]}" -eq 0 ] && break

  now=$(date +%s)
  if [ "$attempt" -ge "$max_attempts" ] || { [ "$deadline" -gt 0 ] && [ "$now" -ge "$deadline" ]; }; then
    break
  fi

  echo "${#remaining[@]} still pending after $((now - start))s; waiting."
  sleep "$(packagist_jitter "$interval_min" "$interval_max")"
  attempt=$((attempt + 1))
done

elapsed=$(( $(date +%s) - start ))
if [ "${#remaining[@]}" -gt 0 ]; then
  list=$(IFS=,; echo "${remaining[*]}")
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
      echo "## Packagist verification FAILED after ${elapsed}s"
      echo
      printf '{"missing":['
      separator=''
      for package in "${remaining[@]}"; do
        printf '%s"%s"' "$separator" "$package"
        separator=','
      done
      echo ']}'
      if [ "$recovery_command" = true ]; then
        echo
        echo 'Recover only these packages, without republishing the rest:'
        echo
        echo "gh workflow run packagist-recover.yml -f packages=${list} -f tag=${tag}"
      fi
    } >> "$GITHUB_STEP_SUMMARY"
  fi
  packagist_error "Not installable at ${tag}: ${list}"
  if [ "$recovery_command" = true ]; then
    packagist_error "Recover: gh workflow run packagist-recover.yml -f packages=${list} -f tag=${tag}"
  fi
  exit 1
fi

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo '## Packagist verification passed'
    echo
    echo "All ${#PACKAGIST_PACKAGE_LIST[@]} package(s) installable at \`${tag}\` after ${elapsed}s."
  } >> "$GITHUB_STEP_SUMMARY"
fi
echo "::notice::All ${#PACKAGIST_PACKAGE_LIST[@]} package(s) visible at ${tag} after ${elapsed}s."

