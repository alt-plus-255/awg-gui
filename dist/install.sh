#!/usr/bin/env bash
# dist/install.sh — production online installer (wget one-liner entry point)
set -euo pipefail

GITHUB_REPO="${AWG_GUI_GITHUB_REPO:-alt-plus-255/awg-gui}"
VERSION="${AWG_GUI_VERSION:-}"
INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0
BUNDLE_LOCAL=""

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${CYAN}[install]${NC} $*" >&2; }
ok() { echo -e "${GREEN}[ok]${NC} $*" >&2; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

usage() {
  cat <<EOF
Usage:
  curl -fsSL https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/install.sh | sudo bash
  curl -fsSL .../dist/install.sh | sudo bash -s -- --yes
  curl -fsSL .../dist/install.sh | sudo AWG_GUI_VERSION=1.0.0 bash -s -- --yes
  wget --no-config -O /tmp/awg-gui-install.sh .../dist/install.sh && sudo bash /tmp/awg-gui-install.sh --yes

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

[[ "$(id -u)" -eq 0 ]] || die "Run as root: curl -fsSL .../dist/install.sh | sudo bash"

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

human_size() {
  local bytes="${1:-0}"
  if (( bytes >= 1073741824 )); then
    awk -v b="${bytes}" 'BEGIN { printf "%.1f GiB", b / 1073741824 }'
  elif (( bytes >= 1048576 )); then
    awk -v b="${bytes}" 'BEGIN { printf "%.1f MiB", b / 1048576 }'
  elif (( bytes >= 1024 )); then
    awk -v b="${bytes}" 'BEGIN { printf "%.0f KiB", b / 1024 }'
  else
    printf '%s B' "${bytes}"
  fi
}

RELEASE_URL=""
RELEASE_SIZE_BYTES=0

resolve_release_asset() {
  local arch api tag json
  arch="$(detect_arch)"
  api="https://api.github.com/repos/${GITHUB_REPO}/releases"

  if [[ -n "${VERSION}" ]]; then
    tag="v${VERSION#v}"
    api="${api}/tags/v${tag#v}"
  else
    api="${api}/latest"
  fi

  log "Fetching release metadata from GitHub (${GITHUB_REPO}) ..."
  json="$(curl -fsSL "${api}" 2>/dev/null || true)"
  [[ -n "${json}" ]] || die "Failed to fetch release info from GitHub"

  if echo "${json}" | grep -q 'API rate limit'; then
    die "GitHub API rate limit. Set AWG_GUI_VERSION and retry later, or download .run manually."
  fi

  RELEASE_URL="$(echo "${json}" | grep -oE "https://[^\"]+awg-gui-[^\"]+-${arch}\\.run" | head -1)"
  RELEASE_SIZE_BYTES="$(echo "${json}" | awk -v arch="${arch}" '
    $0 ~ "\"name\": \"awg-gui-.*-" arch "\\.run\"" { want=1; next }
    want && /"size":/ {
      match($0, /[0-9]+/)
      if (RSTART) { print substr($0, RSTART, RLENGTH); exit }
    }
  ')"
  [[ -n "${RELEASE_URL}" ]] || die "Release bundle awg-gui-*-${arch}.run not found for ${GITHUB_REPO}"
  [[ "${RELEASE_SIZE_BYTES}" =~ ^[0-9]+$ ]] || RELEASE_SIZE_BYTES=0
}

fetch_url_with_progress() {
  local url="$1" dest="$2" expected="${3:-0}" label total
  label="[install] Downloading"
  [[ "${expected}" -gt 0 ]] && total="$(human_size "${expected}")"

  if command -v curl >/dev/null 2>&1; then
    if [[ -t 2 ]]; then
      log "Bundle download started${total:+ (${total})} — progress below:"
      curl -fL --progress-bar -o "${dest}" "${url}"
      echo >&2
      return 0
    fi

    log "Bundle download started${total:+ (${total})} — updating size every 3s ..."
    curl -fL -o "${dest}" "${url}" &
    local pid=$!
    while kill -0 "${pid}" 2>/dev/null; do
      if [[ -f "${dest}" ]]; then
        local cur; cur=$(stat -c%s "${dest}" 2>/dev/null || echo 0)
        if [[ "${expected}" -gt 0 ]]; then
          local pct=$(( cur * 100 / expected ))
          printf '\r%s: %s / %s (%s%%)  ' "${label}" "$(human_size "${cur}")" "${total}" "${pct}" >&2
        else
          printf '\r%s: %s  ' "${label}" "$(human_size "${cur}")" >&2
        fi
      fi
      sleep 3
    done
    wait "${pid}"
    echo >&2
    return 0
  fi

  if [[ -t 2 ]]; then
    log "Bundle download started${total:+ (${total})} — progress below:"
    wget --no-config --show-progress -O "${dest}" "${url}"
    echo >&2
    return 0
  fi

  log "Bundle download started${total:+ (${total})} — updating size every 3s ..."
  wget --no-config -O "${dest}" "${url}" &
  local pid=$!
  while kill -0 "${pid}" 2>/dev/null; do
    if [[ -f "${dest}" ]]; then
      local cur; cur=$(stat -c%s "${dest}" 2>/dev/null || echo 0)
      if [[ "${expected}" -gt 0 ]]; then
        local pct=$(( cur * 100 / expected ))
        printf '\r%s: %s / %s (%s%%)  ' "${label}" "$(human_size "${cur}")" "${total}" "${pct}" >&2
      else
        printf '\r%s: %s  ' "${label}" "$(human_size "${cur}")" >&2
      fi
    fi
    sleep 3
  done
  wait "${pid}"
  echo >&2
}

download_bundle() {
  local dest dir url size_bytes filename
  dir="$(mktemp -d /tmp/awg-gui-install.XXXXXX)"
  dest="${dir}/bundle.run"

  if [[ -n "${BUNDLE_LOCAL}" ]]; then
    [[ -f "${BUNDLE_LOCAL}" ]] || die "Bundle not found: ${BUNDLE_LOCAL}"
    cp "${BUNDLE_LOCAL}" "${dest}"
    ok "Using local bundle ${BUNDLE_LOCAL}"
  else
    resolve_release_asset
    url="${RELEASE_URL}"
    size_bytes="${RELEASE_SIZE_BYTES}"
    filename="${url##*/}"
    if [[ "${size_bytes}" -gt 0 ]]; then
      log "Downloading ${filename} ($(human_size "${size_bytes}")) ..."
    else
      log "Downloading ${filename} ..."
    fi
    fetch_url_with_progress "${url}" "${dest}" "${size_bytes}"
    ok "Download complete ($(human_size "$(stat -c%s "${dest}")"))"
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
