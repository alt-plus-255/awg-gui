#!/usr/bin/env bash
# build.sh — production release builder (repo root)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${ROOT}/src/scripts/build-dist.sh" "$@"
