#!/usr/bin/env bash
# Self-extracting awg-gui release bundle header (payload appended after #__PAYLOAD__)
set -euo pipefail

INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0
SKIP_KERNEL=0

usage() {
  cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--dir=/opt/awg-gui]

Extracts the release bundle and runs the production installer.
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --no-awg-kernel) SKIP_KERNEL=1 ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 1 ;;
  esac
done

if [[ "${AWG_GUI_SKIP_KERNEL:-0}" == "1" ]]; then
  SKIP_KERNEL=1
fi

[[ "$(id -u)" -eq 0 ]] || { echo "[error] Run as root (sudo)" >&2; exit 1; }

TMP="$(mktemp -d /tmp/awg-gui-extract.XXXXXX)"
trap 'rm -rf "${TMP}"' EXIT

ARCHIVE_LINE="$(awk '/^#__PAYLOAD__$/{print NR + 1; exit 0}' "$0")"
[[ -n "${ARCHIVE_LINE}" ]] || { echo "[error] Corrupt bundle (payload marker missing)" >&2; exit 1; }

echo "[run] Extracting release bundle ..."
tail -n +"${ARCHIVE_LINE}" "$0" | tar xzf - -C "${TMP}"

mkdir -p "${INSTALL_DIR}"
if command -v rsync >/dev/null 2>&1; then
  # Never overwrite live install secrets with bundle defaults.
  rsync -a --exclude 'runtime/.env' "${TMP}/" "${INSTALL_DIR}/"
else
  # Fallback without rsync: copy tree but keep an existing .env.
  if [[ -f "${INSTALL_DIR}/runtime/.env" ]]; then
    cp -a "${INSTALL_DIR}/runtime/.env" "${TMP}/.env.preserve"
  fi
  cp -a "${TMP}/." "${INSTALL_DIR}/"
  if [[ -f "${TMP}/.env.preserve" ]]; then
    mkdir -p "${INSTALL_DIR}/runtime"
    mv -f "${TMP}/.env.preserve" "${INSTALL_DIR}/runtime/.env"
  fi
fi

ARGS=()
[[ "${YES}" -eq 1 ]] && ARGS+=(--yes)
[[ "${SKIP_KERNEL}" -eq 1 ]] && ARGS+=(--no-awg-kernel)

echo "[run] Starting installer in ${INSTALL_DIR} ..."
"${INSTALL_DIR}/bundle-install.sh" "${ARGS[@]}"
exit 0
#__PAYLOAD__
