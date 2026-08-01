#!/usr/bin/env bash
# Self-extracting awg-gui release bundle header (payload appended after #__PAYLOAD__)
set -euo pipefail

INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
YES=0
SKIP_KERNEL=0

# Minimal i18n inline until bundle extracts (or source from env already set by parent).
if [[ -z "${_AWG_GUI_I18N_LOADED:-}" ]]; then
  AWG_GUI_LANG="${AWG_GUI_LANG:-ru}"
  case "$(printf '%s' "${AWG_GUI_LANG}" | tr '[:upper:]' '[:lower:]')" in
    en|english) AWG_GUI_LANG=en ;;
    *) AWG_GUI_LANG=ru ;;
  esac
  export AWG_GUI_LANG
  _run_t() {
    local key="$1"; shift
    local fmt
    if [[ "${AWG_GUI_LANG}" == "en" ]]; then
      case "${key}" in
        err_unknown_arg) fmt='Unknown argument: %s' ;;
        err_run_as_root) fmt='Run as root (sudo)' ;;
        err_corrupt_bundle) fmt='Corrupt bundle (payload marker missing)' ;;
        log_extracting_bundle) fmt='Extracting release bundle ...' ;;
        log_starting_installer) fmt='Starting installer in %s ...' ;;
        *) fmt="${key}" ;;
      esac
    else
      case "${key}" in
        err_unknown_arg) fmt='Неизвестный аргумент: %s' ;;
        err_run_as_root) fmt='Запустите от root (sudo)' ;;
        err_corrupt_bundle) fmt='Повреждённый бандл (нет маркера payload)' ;;
        log_extracting_bundle) fmt='Извлечение release-бандла ...' ;;
        log_starting_installer) fmt='Запуск установщика в %s ...' ;;
        *) fmt="${key}" ;;
      esac
    fi
    if [[ $# -gt 0 ]]; then
      # shellcheck disable=SC2059
      printf -- "${fmt}" "$@"
    else
      printf '%s' "${fmt}"
    fi
  }
else
  _run_t() { t "$@"; }
fi

usage() {
  if [[ "${AWG_GUI_LANG}" == "en" ]]; then
    cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--lang=ru|en] [--dir=/opt/awg-gui]

Extracts the release bundle and runs the production installer.

  --yes              Non-interactive (defaults; installs kernel module unless skipped)
  --no-awg-kernel    Skip AmneziaWG kernel module install
  --lang=ru|en       Installer language (default: ru; also AWG_GUI_LANG)
EOF
  else
    cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--lang=ru|en] [--dir=/opt/awg-gui]

Извлекает release-бандл и запускает production-установщик.

  --yes              Без интерактива (значения по умолчанию; kernel-модуль ставится, если не отключён)
  --no-awg-kernel    Пропустить установку модуля ядра AmneziaWG
  --lang=ru|en       Язык установщика (по умолчанию: ru; также AWG_GUI_LANG)
EOF
  fi
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --no-awg-kernel) SKIP_KERNEL=1 ;;
    --lang=*)
      AWG_GUI_LANG="${arg#*=}"
      case "$(printf '%s' "${AWG_GUI_LANG}" | tr '[:upper:]' '[:lower:]')" in
        en|english) AWG_GUI_LANG=en ;;
        *) AWG_GUI_LANG=ru ;;
      esac
      export AWG_GUI_LANG
      ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) echo "[error] $(_run_t err_unknown_arg "$arg")" >&2; usage; exit 1 ;;
  esac
done

if [[ "${AWG_GUI_SKIP_KERNEL:-0}" == "1" ]]; then
  SKIP_KERNEL=1
fi

[[ "$(id -u)" -eq 0 ]] || { echo "[error] $(_run_t err_run_as_root)" >&2; exit 1; }

TMP="$(mktemp -d /tmp/awg-gui-extract.XXXXXX)"
trap 'rm -rf "${TMP}"' EXIT

ARCHIVE_LINE="$(awk '/^#__PAYLOAD__$/{print NR + 1; exit 0}' "$0")"
[[ -n "${ARCHIVE_LINE}" ]] || { echo "[error] $(_run_t err_corrupt_bundle)" >&2; exit 1; }

echo "[run] $(_run_t log_extracting_bundle)"
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
ARGS+=(--lang="${AWG_GUI_LANG}")

echo "[run] $(_run_t log_starting_installer "${INSTALL_DIR}")"
export AWG_GUI_LANG
"${INSTALL_DIR}/bundle-install.sh" "${ARGS[@]}"
exit 0
#__PAYLOAD__
