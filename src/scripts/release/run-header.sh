#!/usr/bin/env bash
# Self-extracting awg-gui release bundle header (payload appended after #__PAYLOAD__)
set -euo pipefail

INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0

usage() {
  cat <<EOF
Usage: $0 [--yes] [--dir=/opt/awg-gui]

Extracts the release bundle and runs the production installer.
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 1 ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || { echo "[error] Run as root (sudo)" >&2; exit 1; }

TMP="$(mktemp -d /tmp/awg-gui-extract.XXXXXX)"
trap 'rm -rf "${TMP}"' EXIT

ARCHIVE_LINE="$(awk '/^#__PAYLOAD__$/{print NR + 1; exit 0}' "$0")"
[[ -n "${ARCHIVE_LINE}" ]] || { echo "[error] Corrupt bundle (payload marker missing)" >&2; exit 1; }

echo "[run] Extracting release bundle ..."
tail -n +"${ARCHIVE_LINE}" "$0" | tar xzf - -C "${TMP}"

mkdir -p "${INSTALL_DIR}"
if command -v rsync >/dev/null 2>&1; then
  rsync -a "${TMP}/" "${INSTALL_DIR}/"
else
  cp -a "${TMP}/." "${INSTALL_DIR}/"
fi

ARGS=()
[[ "${YES}" -eq 1 ]] && ARGS+=(--yes)

echo "[run] Starting installer in ${INSTALL_DIR} ..."
exec "${INSTALL_DIR}/bundle-install.sh" "${ARGS[@]}"
exit 0
#__PAYLOAD__
