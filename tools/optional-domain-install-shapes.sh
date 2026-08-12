#!/usr/bin/env bash

set -euo pipefail

framework_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
cleanup() { rm -rf "$work"; }
trap cleanup EXIT

install_shape() {
  local shape=$1
  local project_root="$work/$shape"

  cp -R "$framework_root/skeleton/." "$project_root/"
  composer config --working-dir="$project_root" repositories.framework path "$framework_root"
  composer config --working-dir="$project_root" repositories.packages path "$framework_root/packages/*"
  composer require --working-dir="$project_root" waaseyaa/framework:dev-main --no-update --no-interaction

  if [[ "$shape" == "full" ]]; then
    composer require --working-dir="$project_root" \
      waaseyaa/genealogy:dev-main \
      waaseyaa/ai-agent:dev-main \
      waaseyaa/oidc:dev-main \
      waaseyaa/mcp:dev-main \
      waaseyaa/wayfinding:dev-main \
      waaseyaa/messaging:dev-main \
      waaseyaa/engagement:dev-main \
      --no-update --no-interaction
  fi

  COMPOSER_ROOT_VERSION=dev-main composer install --working-dir="$project_root" \
    --no-interaction --no-scripts --no-plugins --quiet

  local database_path="$project_root/storage/waaseyaa.sqlite"
  APP_ENV=testing WAASEYAA_DB="$database_path" php "$project_root/vendor/bin/waaseyaa" db:init
  APP_ENV=testing WAASEYAA_DB="$database_path" php "$framework_root/tools/skeleton-smoke/smoke.php" "$project_root"
  APP_ENV=testing WAASEYAA_DB="$database_path" php "$framework_root/tools/optional-domain-install-smoke.php" "$project_root" "$shape"
}

install_shape minimal
install_shape full
