#!/usr/bin/env bash
set -uo pipefail

ACTION_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=../packagist-verify/packagist-lib.sh
source "${ACTION_DIR}/../packagist-verify/packagist-lib.sh"

packages="${PACKAGIST_PACKAGES:-}"
packages_file="${PACKAGIST_PACKAGES_FILE:-}"
repository_owner="${PACKAGIST_REPOSITORY_OWNER:-}"
username="${PACKAGIST_USERNAME:-}"
token="${PACKAGIST_TOKEN:-}"
main_token="${PACKAGIST_MAIN_TOKEN:-}"
allow_create="${PACKAGIST_ALLOW_CREATE:-true}"
mode="${PACKAGIST_MODE:-submit}"
tag="${PACKAGIST_TAG:-}"
max_attempts="${PACKAGIST_MAX_ATTEMPTS:-3}"
spacing_min="${PACKAGIST_SPACING_MIN_SECONDS:-3}"
spacing_max="${PACKAGIST_SPACING_MAX_SECONDS:-6}"
dry_run="${PACKAGIST_DRY_RUN:-false}"

packagist_validate_bool allow-create "$allow_create" || exit 1
packagist_validate_bool dry-run "$dry_run" || exit 1
packagist_validate_positive_int max-attempts "$max_attempts" || exit 1
packagist_validate_uint spacing-min-seconds "$spacing_min" || exit 1
packagist_validate_uint spacing-max-seconds "$spacing_max" || exit 1
if [ "$spacing_max" -lt "$spacing_min" ]; then
  packagist_error 'spacing-max-seconds must be greater than or equal to spacing-min-seconds.'
  exit 1
fi
if [ "$mode" != submit ] && [ "$mode" != recovery ]; then
  packagist_error "mode must be submit or recovery (got: ${mode})."
  exit 1
fi
if ! printf '%s' "$repository_owner" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9-]*$'; then
  packagist_error "repository-owner is invalid (got: ${repository_owner})."
  exit 1
fi
packagist_load_packages "$packages" "$packages_file" || exit 1

if [ "$mode" = recovery ]; then
  packagist_validate_tag "$tag" || exit 1
  if [ "$max_attempts" -gt 99 ]; then
    packagist_error 'max-attempts must be between 1 and 99 in recovery mode.'
    exit 1
  fi
fi

if [ "$dry_run" != true ] && { [ -z "$username" ] || [ -z "$token" ]; }; then
  packagist_error 'PACKAGIST_USERNAME / PACKAGIST_TOKEN secret not set.'
  packagist_error 'The Packagist push webhooks are disabled by design, so an authenticated submission is required.'
  exit 1
fi

response_dir="${RUNNER_TEMP:-${TMPDIR:-/tmp}}/waaseyaa-packagist"
mkdir -p "$response_dir"
jobs_file="${response_dir}/jobs.tsv"
: > "$jobs_file"

submit_one() {
  local package="$1"
  local package_allow_create="$2"
  local short="${package#waaseyaa/}"
  local repo_url="https://github.com/${repository_owner}/${short}"
  local response="${response_dir}/${short}-update.json"
  local create_response="${response_dir}/${short}-create.json"
  local code create_code job_id status_text

  if [ "$dry_run" = true ]; then
    echo "DRY RUN: ${package} would call update-package for ${repo_url} (allow-create=${package_allow_create})."
    printf '%s\t%s\t%s\n' "$package" DRY-RUN none >> "$jobs_file"
    PACKAGIST_SUBMIT_JOB_ID=none
    return 0
  fi

  code=$(curl -sS -A 'waaseyaa-release/1.0 (+https://github.com/waaseyaa/framework; release submission)' \
    -o "$response" -w '%{http_code}' -X POST \
    "https://packagist.org/api/update-package?username=${username}&apiToken=${token}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":{\"url\":\"${repo_url}\"}}" || echo '000')
  job_id=$(jq -r '.job // .jobs[0] // empty' "$response" 2>/dev/null)
  status_text=$(jq -r '.status // empty' "$response" 2>/dev/null)
  PACKAGIST_SUBMIT_JOB_ID="${job_id:-none}"

  if [ "$code" = 200 ] || [ "$code" = 202 ]; then
    printf '%s\t%s\t%s\n' "$package" "$code" "${job_id:-none}" >> "$jobs_file"
    echo "::notice::${package}: update-package accepted (HTTP ${code}, status ${status_text:-none}, job ${job_id:-none}); not yet published."
    return 0
  fi

  if [ "$code" != 404 ] || [ "$package_allow_create" != true ]; then
    packagist_error "${package}: update-package failed (HTTP ${code}): $(cat "$response" 2>/dev/null)"
    return 1
  fi

  echo "::notice::${package}: not registered on Packagist yet; calling create-package."
  if [ -z "$main_token" ]; then
    packagist_error "${package}: PACKAGIST_MAIN_TOKEN is required to register a new package."
    return 1
  fi
  create_code=$(curl -sS -A 'waaseyaa-release/1.0 (+https://github.com/waaseyaa/framework; package registration)' \
    -o "$create_response" -w '%{http_code}' -X POST \
    'https://packagist.org/api/create-package' \
    -H "Authorization: Bearer ${username}:${main_token}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":\"${repo_url}\"}" || echo '000')
  if [ "$create_code" = 200 ] || [ "$create_code" = 202 ]; then
    printf '%s\t%s\t%s\n' "$package" "$create_code" created >> "$jobs_file"
    echo "::notice::${package}: create-package accepted (HTTP ${create_code}); not yet published."
    PACKAGIST_SUBMIT_JOB_ID=created
    return 0
  fi

  packagist_error "${package}: create-package failed (HTTP ${create_code}): $(cat "$create_response" 2>/dev/null)"
  return 1
}

write_submission_summary() {
  [ -z "${GITHUB_STEP_SUMMARY:-}" ] && return
  {
    echo '## Packagist crawl submissions'
    echo
    echo 'Accepted or queued, **not** published; publication requires exact-tag P2 verification.'
    echo
    echo '| Package | HTTP | Job |'
    echo '|---|---|---|'
    while IFS=$'\t' read -r package code job; do
      echo "| \`${package}\` | ${code} | \`${job}\` |"
    done < "$jobs_file"
  } >> "$GITHUB_STEP_SUMMARY"
}

if [ "$mode" = submit ]; then
  rc=0
  count=0
  for package in "${PACKAGIST_PACKAGE_LIST[@]}"; do
    count=$((count + 1))
    if [ "$count" -gt 1 ]; then
      sleep "$(packagist_jitter "$spacing_min" "$spacing_max")"
    fi
    submit_one "$package" "$allow_create" || rc=1
  done
  write_submission_summary
  exit "$rc"
fi

missing=()
recovered=()
for package in "${PACKAGIST_PACKAGE_LIST[@]}"; do
  short="${package#waaseyaa/}"
  echo "::group::${package}"
  if [ "$dry_run" != true ]; then
    if [ -z "${GH_TOKEN:-}" ]; then
      packagist_error 'github-token is required in recovery mode.'
      missing+=("$package")
      echo '::endgroup::'
      continue
    fi
    if ! gh api "repos/${repository_owner}/${short}/git/ref/tags/${tag}" -q .object.sha >/dev/null 2>&1; then
      packagist_error "${package}: split repo has no ${tag}. Refusing to submit; fix the split first."
      missing+=("$package")
      echo '::endgroup::'
      continue
    fi
    if packagist_version_visible "$package" "$tag"; then
      echo "::notice::${package}: ${tag} already visible; nothing to do."
      recovered+=("$package")
      echo '::endgroup::'
      continue
    fi
  fi

  published=false
  attempt=1
  while [ "$attempt" -le "$max_attempts" ]; do
    if submit_one "$package" false; then
      if [ "$dry_run" = true ]; then
        published=true
        break
      fi
      for poll in $(seq 1 20); do
        sleep "$(packagist_jitter 15 24)"
        if packagist_version_visible "$package" "$tag"; then
          echo "::notice::${package}: ${tag} visible in P2 after poll ${poll} (job ${PACKAGIST_SUBMIT_JOB_ID:-none})."
          published=true
          break
        fi
      done
      [ "$published" = true ] && break
      echo "::warning::${package}: accepted (job ${PACKAGIST_SUBMIT_JOB_ID:-none}) but ${tag} never appeared; resubmitting."
      sleep "$(packagist_jitter "$((attempt * 30))" "$((attempt * 30 + 14))")"
    else
      sleep "$(packagist_jitter "$((attempt * 20))" "$((attempt * 20 + 9))")"
    fi
    attempt=$((attempt + 1))
  done

  if [ "$published" = true ]; then
    recovered+=("$package")
  else
    missing+=("$package")
  fi
  echo '::endgroup::'
  [ "$dry_run" = true ] || sleep 10
done

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "recovered=${recovered[*]:-}" >> "$GITHUB_OUTPUT"
fi
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "## Packagist recovery: ${tag}"
    echo
    echo '| Package | Result |'
    echo '|---|---|'
    for package in "${recovered[@]:-}"; do
      if [ -n "$package" ]; then
        [ "$dry_run" = true ] && result='dry-run validated' || result='published'
        echo "| \`${package}\` | ${result} |"
      fi
    done
    for package in "${missing[@]:-}"; do [ -n "$package" ] && echo "| \`${package}\` | **STILL MISSING** |"; done
  } >> "$GITHUB_STEP_SUMMARY"
fi
write_submission_summary

if [ "${#missing[@]}" -gt 0 ] && [ -n "${missing[0]:-}" ]; then
  packagist_error "Still missing after recovery: ${missing[*]}"
  packagist_error "Retry: gh workflow run packagist-recover.yml -f packages=$(IFS=,; echo "${missing[*]}") -f tag=${tag}"
  exit 1
fi
if [ "$dry_run" = true ]; then
  echo "DRY RUN: recovery inputs and submission plan validated for ${tag}."
else
  echo "::notice::All requested packages are visible at ${tag}."
fi
