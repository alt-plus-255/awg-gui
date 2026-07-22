#!/usr/bin/env bash
# build-dist.sh — assemble production release into dist/ (called from repo-root build.sh)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="${ROOT}/src"
DIST="${ROOT}/dist"
RELEASE="${SRC}/scripts/release"
STAGING="${DIST}/.staging"
SING_BOX_VERSION=1.12.12
MARIADB_IMAGE=mariadb:11.4
DOCKER_PROXY_IMAGE=tecnativa/docker-socket-proxy:0.3.0
CERTBOT_IMAGE=certbot/certbot:v3.0.0
PROJECT_NAME=awggui

VERSION="${1:-}"
ARCH="${2:-}"

usage() {
  cat <<EOF
Usage: build.sh [--version VER] [--arch amd64|arm64]

Builds Docker images from src/, exports them to dist/awg-gui-VERSION-ARCH.run
EOF
}

log() { echo "[build] $*"; }
die() { echo "[build] ERROR: $*" >&2; exit 1; }

read_version() {
  if [[ -n "${VERSION}" ]]; then
    return
  fi
  if [[ -f "${ROOT}/VERSION" ]]; then
    VERSION="$(tr -d '[:space:]' < "${ROOT}/VERSION")"
  fi
  [[ -n "${VERSION}" ]] || die "VERSION not set (VERSION file or --version)"
}

detect_arch() {
  if [[ -n "${ARCH}" ]]; then
    return
  fi
  case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) die "Unsupported build host arch: $(uname -m). Use --arch amd64|arm64" ;;
  esac
}

sing_box_arch() {
  case "${ARCH}" in
    amd64) echo amd64 ;;
    arm64) echo arm64 ;;
    armhf) echo armv7 ;;
    *) die "Unsupported sing-box arch: ${ARCH}" ;;
  esac
}

ensure_sing_box_vendor() {
  local sb_arch dest url
  sb_arch="$(sing_box_arch)"
  dest="${SRC}/awg/vendor/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  if [[ -f "${dest}" ]]; then
    log "sing-box vendor present"
    return
  fi
  mkdir -p "${SRC}/awg/vendor"
  url="https://github.com/SagerNet/sing-box/releases/download/v${SING_BOX_VERSION}/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  log "Downloading sing-box ${SING_BOX_VERSION} (${sb_arch}) ..."
  curl -fsSL -o "${dest}" "${url}"
}

require_free_gb() {
  local need_gb="$1"
  local avail_kb avail_gb
  avail_kb="$(df -Pk "${ROOT}" | awk 'NR==2 {print $4}')"
  avail_gb=$((avail_kb / 1024 / 1024))
  if (( avail_gb < need_gb )); then
    die "Need >= ${need_gb}G free on disk (have ~${avail_gb}G). Free space and retry."
  fi
  log "Disk OK: ~${avail_gb}G free"
}

compose_build() {
  log "Building Docker images (this may take a while) ..."
  COMPOSE_PARALLEL_LIMIT=1 docker compose -p "${PROJECT_NAME}" -f "${SRC}/docker-compose.yml" build
  # Drop intermediate build layers so export has room on small disks
  docker builder prune -af >/dev/null 2>&1 || true
}

tag_images() {
  local svc
  for svc in caddy app awg panel-ops; do
    docker tag "${PROJECT_NAME}-${svc}" "awggui-${svc}:${VERSION}"
  done
  docker pull "${MARIADB_IMAGE}"
  docker pull "${DOCKER_PROXY_IMAGE}"
  docker pull "${CERTBOT_IMAGE}"
}

export_images() {
  local bundle_dir="${STAGING}/bundle"
  mkdir -p "${bundle_dir}/images"
  # gzip stream avoids a multi-GB uncompressed tar on small VDS disks
  local tar="${bundle_dir}/images/awggui-all-${VERSION}.tar.gz"
  log "Exporting images to ${tar} ..."
  require_free_gb 2
  docker save \
    "awggui-caddy:${VERSION}" \
    "awggui-app:${VERSION}" \
    "awggui-awg:${VERSION}" \
    "awggui-panel-ops:${VERSION}" \
    "${MARIADB_IMAGE}" \
    "${DOCKER_PROXY_IMAGE}" \
    "${CERTBOT_IMAGE}" \
    | gzip -1 > "${tar}"
}

assemble_runtime() {
  local bundle_dir="${STAGING}/bundle"
  local runtime="${bundle_dir}/runtime"
  mkdir -p "${runtime}"

  sed "s/__VERSION__/${VERSION}/g" \
    "${RELEASE}/docker-compose.release.yml" > "${runtime}/docker-compose.yml"

  cp "${SRC}/.env.example" "${runtime}/.env.example"
  cp -a "${SRC}/bin" "${runtime}/"
  cp -a "${SRC}/systemd" "${runtime}/"
  mkdir -p "${runtime}/caddy"
  cp "${SRC}/caddy/Caddyfile" "${runtime}/caddy/"
  cp -a "${SRC}/caddy/host-files" "${runtime}/caddy/"

  cp "${RELEASE}/bundle-install.sh" "${bundle_dir}/bundle-install.sh"
  cp "${RELEASE}/bundle-uninstall.sh" "${bundle_dir}/bundle-uninstall.sh"
  cp "${ROOT}/LICENSE" "${bundle_dir}/LICENSE"
  cp "${ROOT}/NOTICE.md" "${bundle_dir}/NOTICE.md"
  echo "${VERSION}" > "${bundle_dir}/VERSION"

  chmod +x "${bundle_dir}/bundle-install.sh" "${bundle_dir}/bundle-uninstall.sh"
}

make_run_bundle() {
  local out="${DIST}/awg-gui-${VERSION}-${ARCH}.run"

  log "Creating self-extracting bundle ${out} ..."
  require_free_gb 1
  # Stream header+payload into .run — no intermediate payload.tar.gz on disk
  {
    cat "${RELEASE}/run-header.sh"
    tar czf - -C "${STAGING}/bundle" .
  } > "${out}"
  chmod +x "${out}"

  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "${out}" > "${out}.sha256"
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "${out}" > "${out}.sha256"
  fi

  log "Bundle ready: ${out}"
  [[ -f "${out}.sha256" ]] && log "Checksum: ${out}.sha256"
}

cleanup() {
  rm -rf "${STAGING}"
}

main() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --version=*) VERSION="${1#*=}"; shift ;;
      --version) VERSION="${2:?}"; shift 2 ;;
      --arch=*) ARCH="${1#*=}"; shift ;;
      --arch) ARCH="${2:?}"; shift 2 ;;
      --help|-h) usage; exit 0 ;;
      *) die "Unknown argument: $1" ;;
    esac
  done

  command -v docker >/dev/null 2>&1 || die "docker required"
  docker compose version >/dev/null 2>&1 || die "docker compose plugin required"
  command -v curl >/dev/null 2>&1 || die "curl required"

  read_version
  detect_arch

  mkdir -p "${DIST}"
  rm -rf "${STAGING}"
  mkdir -p "${STAGING}"

  trap cleanup EXIT

  require_free_gb 3
  ensure_sing_box_vendor
  compose_build
  tag_images
  assemble_runtime
  export_images
  make_run_bundle
  # staging cleaned by EXIT trap; prune again after heavy export
  docker builder prune -af >/dev/null 2>&1 || true

  log "Done. Publish dist/awg-gui-${VERSION}-${ARCH}.run to GitHub Releases."
  log "Users install with: curl -fsSL .../dist/install.sh | sudo bash"
}

main "$@"
