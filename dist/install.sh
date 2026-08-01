#!/usr/bin/env bash
# dist/install.sh — production online installer (wget one-liner entry point)
set -euo pipefail

GITHUB_REPO="${AWG_GUI_GITHUB_REPO:-alt-plus-255/awg-gui}"
VERSION="${AWG_GUI_VERSION:-}"
INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0
SKIP_KERNEL=0
BUNDLE_LOCAL=""
DOWNLOAD_TMP_DIR=""
MIN_TMP_FREE_BYTES=$((1024 * 1024 * 1024))
MIN_INSTALL_FREE_BYTES=$((768 * 1024 * 1024))
MIN_DOCKER_FREE_BYTES=$((5 * 1024 * 1024 * 1024))

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${CYAN}[install]${NC} $*" >&2; }
ok() { echo -e "${GREEN}[ok]${NC} $*" >&2; }
warn() { echo -e "${YELLOW}[warn]${NC} $*" >&2; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

cleanup() {
  if [[ -n "${DOWNLOAD_TMP_DIR}" && -d "${DOWNLOAD_TMP_DIR}" ]]; then
    rm -rf "${DOWNLOAD_TMP_DIR}"
  fi
}

trap cleanup EXIT

usage() {
  cat <<EOF
Usage:
  curl -fsSL https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/install.sh | sudo bash
  curl -fsSL .../dist/install.sh | sudo bash -s -- --yes
  curl -fsSL .../dist/install.sh | sudo AWG_GUI_VERSION=1.0.0 bash -s -- --yes
  wget --no-config -O /tmp/awg-gui-install.sh .../dist/install.sh && sudo bash /tmp/awg-gui-install.sh --yes

Options:
  --yes              Non-interactive install (auto-install Docker if missing; installs kernel module unless skipped)
  --no-awg-kernel    Skip AmneziaWG kernel module install
  --bundle=PATH      Use local .run bundle (skip download)
  --dir=/opt/awg-gui Install directory

Environment:
  AWG_GUI_GITHUB_REPO   GitHub owner/repo (default: ${GITHUB_REPO})
  AWG_GUI_VERSION       Release tag without v (default: latest release)
  AWG_GUI_INSTALL_DIR   Target install dir (default: ${INSTALL_DIR})
  AWG_GUI_SKIP_KERNEL=1 Same as --no-awg-kernel
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --no-awg-kernel) SKIP_KERNEL=1 ;;
    --bundle=*) BUNDLE_LOCAL="${arg#*=}" ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg (try --help)" ;;
  esac
done

if [[ "${AWG_GUI_SKIP_KERNEL:-0}" == "1" ]]; then
  SKIP_KERNEL=1
fi

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

# Load Docker installer helper. Prefer local checkout, else fetch from GitHub
# (needed when this script is piped via curl|bash).
load_ensure_docker() {
  local base c
  base="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)" || true
  for c in \
    ${base:+"${base}/ensure-docker.sh"} \
    ${base:+"${base}/../src/scripts/lib/ensure-docker.sh"}
  do
    if [[ -f "${c}" ]]; then
      # shellcheck disable=SC1090
      source "${c}"
      return 0
    fi
  done

  local ref="refs/heads/main" url tmp
  if [[ -n "${VERSION}" ]]; then
    ref="refs/tags/v${VERSION#v}"
  fi
  tmp="$(mktemp /tmp/awg-gui-ensure-docker.XXXXXX)"
  for url in \
    "https://raw.githubusercontent.com/${GITHUB_REPO}/${ref}/dist/ensure-docker.sh" \
    "https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/ensure-docker.sh" \
    "https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/src/scripts/lib/ensure-docker.sh"
  do
    if curl -fsSL "${url}" -o "${tmp}" 2>/dev/null; then
      # shellcheck disable=SC1090
      source "${tmp}"
      rm -f "${tmp}"
      return 0
    fi
  done
  rm -f "${tmp}"
  die "Failed to load Docker install helper. Install Docker manually: https://docs.docker.com/engine/install/"
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

human_mib() {
  local bytes="${1:-0}"
  awk -v b="${bytes}" 'BEGIN { printf "%.1f MiB", b / 1048576 }'
}

read_tty() {
  local prompt="$1" ans=""
  if [[ -r /dev/tty ]]; then
    printf '%s' "${prompt}" > /dev/tty
    read -r ans < /dev/tty || true
  elif [[ -t 0 ]]; then
    read -r -p "${prompt}" ans || true
  fi
  printf '%s' "${ans}"
}

confirm() {
  local msg="$1" default="${2:-n}" ans hint
  if [[ "${YES}" -eq 1 ]]; then
    log "${msg} → yes (--yes)"
    return 0
  fi
  if [[ "${default}" == "y" ]]; then
    hint="[Y/n]"
  else
    hint="[y/N]"
  fi
  ans="$(read_tty "${msg} ${hint}: ")"
  ans="${ans:-${default}}"
  case "${ans}" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

existing_parent_dir() {
  local path="$1"
  while [[ ! -e "${path}" && "${path}" != "/" ]]; do
    path="$(dirname "${path}")"
  done
  printf '%s\n' "${path}"
}

available_bytes() {
  local path="$1"
  df -Pk "${path}" 2>/dev/null | awk 'NR==2 { print $4 * 1024 }'
}

docker_root_dir() {
  docker info --format '{{.DockerRootDir}}' 2>/dev/null || printf '/var/lib/docker\n'
}

cleanup_stale_tmp_artifacts() {
  local paths=() count=0 size=0
  shopt -s nullglob
  paths=(/tmp/awg-gui-install.* /tmp/awg-gui-extract.*)
  shopt -u nullglob
  count=${#paths[@]}
  (( count > 0 )) || return 0

  size="$(du -sb "${paths[@]}" 2>/dev/null | awk '{sum+=$1} END {print sum+0}')"
  warn "Found ${count} stale awg-gui temp artifact(s) in /tmp using $(human_size "${size}")"
  if confirm "Remove stale awg-gui temp files from /tmp before install?" y; then
    rm -rf "${paths[@]}"
    ok "Removed stale awg-gui temp files from /tmp"
  else
    warn "Keeping stale /tmp artifacts may cause install to run out of disk space"
  fi
}

# After the .run returns, drop leftover tmp paths and unused previous awggui:* tags.
cleanup_after_bundle() {
  local img removed=0 self="${BASH_SOURCE[0]:-}"
  log "Cleaning temporary files and unused Docker images ..."

  find /tmp -maxdepth 1 -type d \( -name 'awg-gui-install.*' -o -name 'awg-gui-extract.*' \) \
    -exec rm -rf {} + 2>/dev/null || true
  find /tmp -maxdepth 1 -type f \( \
    -name 'awg-gui-ensure-docker.*' \
    -o -name 'awg-gui*.log' -o -name 'awg-gui-*.log' \
  \) -delete 2>/dev/null || true
  # Do not delete the script we may still be executing (wget one-liner path).
  if [[ -f /tmp/awg-gui-install.sh && "$(readlink -f /tmp/awg-gui-install.sh 2>/dev/null || true)" != "$(readlink -f "${self}" 2>/dev/null || true)" ]]; then
    rm -f /tmp/awg-gui-install.sh
  fi

  while read -r img; do
    [[ -n "${img}" ]] || continue
    [[ "${img}" == *":<none>" ]] && continue
    if docker rmi "${img}" >/dev/null 2>&1; then
      removed=$((removed + 1))
    fi
  done < <(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^awggui-' || true)

  docker image prune -f >/dev/null 2>&1 || true
  docker rmi alpine:3.20 >/dev/null 2>&1 || true

  if [[ "${removed}" -gt 0 ]]; then
    ok "Removed ${removed} unused awg-gui image(s) and cleaned /tmp"
  else
    ok "Cleaned /tmp and dangling Docker images"
  fi
}

require_free_space() {
  local path="$1" required="$2" label="$3" avail
  avail="$(available_bytes "${path}")"
  [[ "${avail}" =~ ^[0-9]+$ ]] || die "Failed to check free space for ${path}"
  if (( avail < required )); then
    die "Not enough free space for ${label} at ${path}: need at least $(human_size "${required}") free, have $(human_size "${avail}"). Clean disk space and retry."
  fi
  ok "${label} free space OK at ${path} ($(human_size "${avail}") available)"
}

preflight_disk_checks() {
  local bundle_bytes="$1"
  local tmp_required install_required docker_required install_parent docker_root

  tmp_required=$(( bundle_bytes * 2 + 256 * 1024 * 1024 ))
  (( tmp_required < MIN_TMP_FREE_BYTES )) && tmp_required="${MIN_TMP_FREE_BYTES}"

  install_required=$(( bundle_bytes + 256 * 1024 * 1024 ))
  (( install_required < MIN_INSTALL_FREE_BYTES )) && install_required="${MIN_INSTALL_FREE_BYTES}"

  docker_required="${MIN_DOCKER_FREE_BYTES}"
  install_parent="$(existing_parent_dir "${INSTALL_DIR}")"
  docker_root="$(docker_root_dir)"

  require_free_space "/tmp" "${tmp_required}" "installer temp space"
  require_free_space "${install_parent}" "${install_required}" "install directory space"
  require_free_space "${docker_root}" "${docker_required}" "Docker data space"
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
  local url="$1" dest="$2" expected="${3:-0}" total is_tty=0
  local spinner='|/-\'
  [[ "${expected}" -gt 0 ]] && total="$(human_size "${expected}")"
  [[ -t 2 ]] && is_tty=1

  log "Bundle download started${total:+ (${total})} ..."

  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "${dest}" "${url}" &
  else
    wget --no-config -q -O "${dest}" "${url}" &
  fi

  local pid=$! tick=0 cur pct frame downloaded_mib total_mib line
  while kill -0 "${pid}" 2>/dev/null; do
    cur=$(stat -c%s "${dest}" 2>/dev/null || echo 0)
    frame="${spinner:tick%${#spinner}:1}"
    downloaded_mib="$(human_mib "${cur}")"

    if [[ "${expected}" -gt 0 ]]; then
      pct=$(( cur * 100 / expected ))
      (( pct > 100 )) && pct=100
      total_mib="$(human_mib "${expected}")"
      line="$(printf '[install] [%s] %3s%%  %9s / %-9s' "${frame}" "${pct}" "${downloaded_mib}" "${total_mib}")"
    else
      line="$(printf '[install] [%s]      %9s downloaded' "${frame}" "${downloaded_mib}")"
    fi

    if (( is_tty )); then
      printf '\033[2K\r%s' "${line}" >&2
    else
      printf '\r%s' "${line}" >&2
    fi

    tick=$((tick + 1))
    sleep 1
  done

  wait "${pid}"
  cur=$(stat -c%s "${dest}" 2>/dev/null || echo 0)
  downloaded_mib="$(human_mib "${cur}")"
  if [[ "${expected}" -gt 0 ]]; then
    total_mib="$(human_mib "${expected}")"
    line="$(printf '[install] [*] 100%%  %9s / %-9s' "${downloaded_mib}" "${total_mib}")"
  else
    line="$(printf '[install] [*]      %9s downloaded' "${downloaded_mib}")"
  fi

  if (( is_tty )); then
    printf '\033[2K\r%s\n' "${line}" >&2
  else
    printf '\r%s\n' "${line}" >&2
  fi
}

download_bundle() {
  local dest dir url size_bytes filename
  dir="$(mktemp -d /tmp/awg-gui-install.XXXXXX)"
  DOWNLOAD_TMP_DIR="${dir}"
  dest="${dir}/bundle.run"

  if [[ -n "${BUNDLE_LOCAL}" ]]; then
    [[ -f "${BUNDLE_LOCAL}" ]] || die "Bundle not found: ${BUNDLE_LOCAL}"
    cp "${BUNDLE_LOCAL}" "${dest}"
    ok "Using local bundle ${BUNDLE_LOCAL}"
  else
    [[ -n "${RELEASE_URL}" ]] || resolve_release_asset
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
  local bundle args=() bundle_bytes=0
  need_downloader
  # Ask / install Docker before downloading ~500 MiB release bundle
  load_ensure_docker
  ensure_docker_engine
  if [[ -n "${BUNDLE_LOCAL}" ]]; then
    [[ -f "${BUNDLE_LOCAL}" ]] || die "Bundle not found: ${BUNDLE_LOCAL}"
    bundle_bytes="$(stat -c%s "${BUNDLE_LOCAL}" 2>/dev/null || echo 0)"
  else
    resolve_release_asset
    bundle_bytes="${RELEASE_SIZE_BYTES}"
  fi
  [[ "${bundle_bytes}" =~ ^[0-9]+$ ]] || bundle_bytes=0
  cleanup_stale_tmp_artifacts
  preflight_disk_checks "${bundle_bytes}"
  bundle="$(download_bundle)"
  args=(--dir="${INSTALL_DIR}")
  [[ "${YES}" -eq 1 ]] && args+=(--yes)
  [[ "${SKIP_KERNEL}" -eq 1 ]] && args+=(--no-awg-kernel)
  log "Running release installer ..."
  "${bundle}" "${args[@]}"
  # Bundle finished: download/extract traps may still run on EXIT; clear leftovers + old images now.
  DOWNLOAD_TMP_DIR=""
  cleanup_after_bundle
}

main "$@"
