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

compose_build() {
  log "Building Docker images (this may take a while) ..."
  COMPOSE_PARALLEL_LIMIT=1 docker compose -p "${PROJECT_NAME}" -f "${SRC}/docker-compose.yml" build
}

tag_images() {
  local svc
  for svc in caddy app awg; do
    docker tag "${PROJECT_NAME}-${svc}" "awggui-${svc}:${VERSION}"
  done
  docker pull "${MARIADB_IMAGE}"
}

export_images() {
  local bundle_dir="${STAGING}/bundle"
  mkdir -p "${bundle_dir}/images"
  local tar="${bundle_dir}/images/awggui-all-${VERSION}.tar"
  log "Exporting images to ${tar} ..."
  docker save \
    "awggui-caddy:${VERSION}" \
    "awggui-app:${VERSION}" \
    "awggui-awg:${VERSION}" \
    "${MARIADB_IMAGE}" \
    -o "${tar}"
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
  echo "${VERSION}" > "${bundle_dir}/VERSION"

  chmod +x "${bundle_dir}/bundle-install.sh" "${bundle_dir}/bundle-uninstall.sh"
}

make_run_bundle() {
  local out="${DIST}/awg-gui-${VERSION}-${ARCH}.run"
  local payload="${STAGING}/payload.tar.gz"

  log "Creating self-extracting bundle ${out} ..."
  tar czf "${payload}" -C "${STAGING}/bundle" .

  cat "${RELEASE}/run-header.sh" "${payload}" > "${out}"
  chmod +x "${out}"
  rm -f "${payload}"

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

  ensure_sing_box_vendor
  compose_build
  tag_images
  assemble_runtime
  export_images
  make_run_bundle

  log "Done. Publish dist/awg-gui-${VERSION}-${ARCH}.run to GitHub Releases."
  log "Users install with: sudo bash <(wget -O - .../dist/install.sh)"
}

main "$@"
