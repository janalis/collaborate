#!/usr/bin/env bash
# Asks Packagist to read a package's repository now: `packagist-update.sh <repository URL>`. Its GitHub hook
# reads it anyway; without PACKAGIST_USERNAME and PACKAGIST_TOKEN, this relies on the hook.
set -euo pipefail

if [ -z "${PACKAGIST_USERNAME:-}" ] || [ -z "${PACKAGIST_TOKEN:-}" ]; then
  echo "Packagist secrets not set; relying on the GitHub hook"
  exit 0
fi
curl -fsS -X POST -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $PACKAGIST_USERNAME:$PACKAGIST_TOKEN" \
  -d "{\"repository\":{\"url\":\"$1\"}}" \
  https://packagist.org/api/update-package
