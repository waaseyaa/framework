#!/usr/bin/env bash

packagist_error() {
  printf '::error::%s\n' "$*"
}

packagist_validate_bool() {
  case "$2" in
    true|false) ;;
    *)
      packagist_error "$1 must be true or false (got: $2)."
      return 1
      ;;
  esac
}

packagist_validate_uint() {
  if ! printf '%s' "$2" | grep -Eq '^[0-9]+$'; then
    packagist_error "$1 must be a non-negative integer (got: $2)."
    return 1
  fi
}

packagist_validate_positive_int() {
  if ! printf '%s' "$2" | grep -Eq '^[1-9][0-9]*$'; then
    packagist_error "$1 must be a positive integer (got: $2)."
    return 1
  fi
}

packagist_validate_package() {
  if ! printf '%s' "$1" | grep -Eq '^waaseyaa/[a-z0-9]([a-z0-9-]*[a-z0-9])?$'; then
    packagist_error "package must match waaseyaa/<name> (got: $1)."
    return 1
  fi
}

packagist_validate_tag() {
  if ! printf '%s' "$1" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
    packagist_error "tag must be a release tag shaped vX.Y.Z[-prerelease] (got: $1)."
    return 1
  fi
}

packagist_load_packages() {
  local raw="${1:-}"
  local file="${2:-}"
  local line pkg
  PACKAGIST_PACKAGE_LIST=()

  if [ -n "$raw" ] && [ -n "$file" ]; then
    packagist_error 'provide packages or packages-file, not both.'
    return 1
  fi
  if [ -z "$raw" ] && [ -z "$file" ]; then
    packagist_error 'packages or packages-file is required.'
    return 1
  fi
  if [ -n "$file" ]; then
    if [ ! -f "$file" ]; then
      packagist_error "packages-file does not exist: $file"
      return 1
    fi
    raw=$(cat "$file")
  fi

  raw=${raw//$'\r'/}
  raw=${raw//,/$'\n'}
  while IFS= read -r line || [ -n "$line" ]; do
    pkg=$(printf '%s' "$line" | tr -d '[:space:]')
    [ -z "$pkg" ] && continue
    packagist_validate_package "$pkg" || return 1
    PACKAGIST_PACKAGE_LIST+=("$pkg")
  done <<< "$raw"

  if [ "${#PACKAGIST_PACKAGE_LIST[@]}" -eq 0 ]; then
    packagist_error 'package selection is empty.'
    return 1
  fi
}

packagist_jitter() {
  local minimum="$1"
  local maximum="$2"
  if [ "$maximum" -le "$minimum" ]; then
    printf '%s\n' "$minimum"
    return
  fi
  printf '%s\n' $((minimum + RANDOM % (maximum - minimum + 1)))
}

packagist_version_visible() {
  local package="$1"
  local tag="$2"
  local user_agent="${PACKAGIST_USER_AGENT:-waaseyaa-release/1.0 (+https://github.com/waaseyaa/framework; publication verification)}"

  curl -sS --max-time "${PACKAGIST_HTTP_TIMEOUT_SECONDS:-15}" -A "$user_agent" \
    "https://repo.packagist.org/p2/${package}.json" \
    | jq -e --arg t "$tag" '.packages[][] | select(.version == $t)' >/dev/null 2>&1
}

