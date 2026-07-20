#!/usr/bin/env bash
# dist/install.sh — production online installer (wget one-liner entry point)
set -euo pipefail

GITHUB_REPO="${AWG_GUI_GITHUB_REPO:-YOUR_ORG/awg-gui}"
VERSION="${AWG_GUI_VERSION:-}"
INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0
BUNDLE_LOCAL=""

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${CYAN}[install]${NC} $*"; }
ok() { echo -e "${GREEN}[ok]${NC} $*"; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

usage() {
  cat <<EOF
Usage:
  sudo bash <(wget -O - https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/install.sh)
  sudo bash <(wget -O - .../dist/install.sh) --yes
  sudo AWG_GUI_VERSION=1.0.0 bash <(wget -O - .../dist/install.sh) --yes

Options:
  --yes              Non-interactive install
  --bundle=PATH      Use local .run bundle (skip download)
  --dir=/opt/awg-gui Install directory

Environment:
  AWG_GUI_GITHUB_REPO   GitHub owner/repo (default: ${GITHUB_REPO})
  AWG_GUI_VERSION       Release tag without v (default: latest release)
  AWG_GUI_INSTALL_DIR   Target install dir (default: ${INSTALL_DIR})
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --bundle=*) BUNDLE_LOCAL="${arg#*=}" ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg (try --help)" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root: sudo bash <(wget -O - .../dist/install.sh)"

detect_arch() {
  case "$(uname -m)" in
    x86_64|amd64) echo amd64 ;;
    aarch64|arm64) echo arm64 ;;
    armv7l) echo armhf ;;
    *) die "Unsupported architecture: $(uname -m)" ;;
  esac
}

need_downloader() {
  command -v curl >/dev/null 2>&1 && return 0
  command -v wget >/dev/null 2>&1 && return 0
  die "curl or wget required"
}

fetch_url() {
  local url="$1" dest="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --progress-bar -o "${dest}" "${url}"
  else
    wget -q --show-progress -O "${dest}" "${url}"
  fi
}

resolve_release_url() {
  local arch api tag url
  arch="$(detect_arch)"
  api="https://api.github.com/repos/${GITHUB_REPO}/releases"

  if [[ -n "${VERSION}" ]]; then
    tag="v${VERSION#v}"
    api="${api}/tags/v${tag#v}"
  else
    api="${api}/latest"
  fi

  log "Fetching release metadata from GitHub (${GITHUB_REPO}) ..."
  local json
  json="$(curl -fsSL "${api}" 2>/dev/null || true)"
  [[ -n "${json}" ]] || die "Failed to fetch release info from GitHub"

  if echo "${json}" | grep -q 'API rate limit'; then
    die "GitHub API rate limit. Set AWG_GUI_VERSION and retry later, or download .run manually."
  fi

  url="$(echo "${json}" | grep -oE "https://[^\"]+awg-gui-[^\"]+-${arch}\\.run" | head -1)"
  [[ -n "${url}" ]] || die "Release bundle awg-gui-*-${arch}.run not found for ${GITHUB_REPO}"

  printf '%s' "${url}"
}

download_bundle() {
  local dest dir url
  dir="$(mktemp -d /tmp/awg-gui-install.XXXXXX)"
  dest="${dir}/bundle.run"

  if [[ -n "${BUNDLE_LOCAL}" ]]; then
    [[ -f "${BUNDLE_LOCAL}" ]] || die "Bundle not found: ${BUNDLE_LOCAL}"
    cp "${BUNDLE_LOCAL}" "${dest}"
    ok "Using local bundle ${BUNDLE_LOCAL}"
  else
    url="$(resolve_release_url)"
    log "Downloading ${url} ..."
    fetch_url "${url}" "${dest}"
    ok "Download complete"
  fi

  chmod +x "${dest}"
  printf '%s' "${dest}"
}

main() {
  need_downloader
  local bundle args=()
  bundle="$(download_bundle)"
  args=(--dir="${INSTALL_DIR}")
  [[ "${YES}" -eq 1 ]] && args+=(--yes)
  log "Running release installer ..."
  exec "${bundle}" "${args[@]}"
}

main "$@"
