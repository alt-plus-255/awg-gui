# install-i18n.sh — shared ru/en strings for install/uninstall scripts.
# Safe to source multiple times.
# Provides: AWG_GUI_LANG, normalize_awg_gui_lang, set_awg_gui_lang,
#           select_install_lang, t, is_yes_answer, is_no_answer,
#           confirm_hint, awg_gui_lang_flag_args
#
# Callers may set AWG_GUI_LANG_EXPLICIT=1 when --lang=... was passed,
# or AWG_GUI_LANG via environment before select_install_lang.

if [[ -n "${_AWG_GUI_I18N_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_AWG_GUI_I18N_LOADED=1

# Pre-set AWG_GUI_LANG in the environment counts as an explicit choice (skip prompt).
if [[ -n "${AWG_GUI_LANG:-}" ]]; then
  AWG_GUI_LANG_EXPLICIT="${AWG_GUI_LANG_EXPLICIT:-1}"
else
  AWG_GUI_LANG_EXPLICIT="${AWG_GUI_LANG_EXPLICIT:-0}"
fi
AWG_GUI_LANG="${AWG_GUI_LANG:-ru}"

normalize_awg_gui_lang() {
  case "$(printf '%s' "${AWG_GUI_LANG}" | tr '[:upper:]' '[:lower:]')" in
    en|english) AWG_GUI_LANG=en ;;
    *) AWG_GUI_LANG=ru ;;
  esac
  export AWG_GUI_LANG
}

set_awg_gui_lang() {
  AWG_GUI_LANG="$1"
  AWG_GUI_LANG_EXPLICIT=1
  normalize_awg_gui_lang
}

# Args to forward to child installers: --lang=ru|en
awg_gui_lang_flag_args() {
  printf '%s' "--lang=${AWG_GUI_LANG}"
}

_awg_gui_i18n_read_tty() {
  local prompt="$1"
  local ans=""
  if [[ -r /dev/tty ]]; then
    printf '%s' "${prompt}" > /dev/tty
    read -r ans < /dev/tty || true
  elif [[ -t 0 ]]; then
    read -r -p "${prompt}" ans || true
  fi
  printf '%s' "${ans}"
}

# Interactive language prompt (bilingual). Skips if explicit/--yes/no TTY.
# Uses optional YES=0|1 from caller.
select_install_lang() {
  normalize_awg_gui_lang

  if [[ "${AWG_GUI_LANG_EXPLICIT}" == "1" ]]; then
    return 0
  fi
  if [[ "${YES:-0}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -r /dev/tty && ! -t 0 ]]; then
    return 0
  fi

  local choice=""
  {
    echo
    echo "Language / Язык:"
    echo "  [1] Русский (по умолчанию / default)"
    echo "  [2] English"
  } > /dev/tty 2>/dev/null || {
    echo
    echo "Language / Язык:"
    echo "  [1] Русский (по умолчанию / default)"
    echo "  [2] English"
  }
  choice="$(_awg_gui_i18n_read_tty "Choice / Выбор [1/2]: ")"
  case "${choice}" in
    2|en|EN|english|English) AWG_GUI_LANG=en ;;
    *) AWG_GUI_LANG=ru ;;
  esac
  normalize_awg_gui_lang
}

is_yes_answer() {
  case "$1" in
    y|Y|yes|YES|д|Д|да|ДА) return 0 ;;
    *) return 1 ;;
  esac
}

is_no_answer() {
  case "$1" in
    n|N|no|NO|н|Н|нет|НЕТ) return 0 ;;
    *) return 1 ;;
  esac
}

confirm_hint() {
  local default="${1:-n}"
  if [[ "${default}" == "y" ]]; then
    printf '%s' "[Y/n]"
  else
    printf '%s' "[y/N]"
  fi
}

# t KEY [printf-args...]
t() {
  local key="$1"
  shift
  local fmt
  fmt="$(_awg_gui_msg "${key}")"
  if [[ $# -gt 0 ]]; then
    # shellcheck disable=SC2059
    printf -- "${fmt}" "$@"
  else
    printf '%s' "${fmt}"
  fi
}

_awg_gui_msg() {
  local key="$1"
  if [[ "${AWG_GUI_LANG}" == "en" ]]; then
    _awg_gui_msg_en "${key}"
  else
    _awg_gui_msg_ru "${key}"
  fi
}

_awg_gui_msg_en() {
  case "$1" in
    # Common
    err_unknown_arg) printf '%s' 'Unknown argument: %s' ;;
    err_unknown_arg_help) printf '%s' 'Unknown argument: %s (try --help)' ;;
    err_run_as_root) printf '%s' 'Run as root (sudo)' ;;
    err_run_as_root_install_curl) printf '%s' 'Run as root: curl -fsSL .../dist/install.sh | sudo bash' ;;
    err_run_as_root_uninstall_curl) printf '%s' 'Run as root: curl -fsSL .../dist/uninstall.sh | sudo bash' ;;
    err_no_tty_use_yes) printf '%s' 'Non-interactive shell (no TTY). Re-run with --yes' ;;
    err_no_tty_docker) printf '%s' 'Non-interactive shell (no TTY). Re-run with --yes, e.g.: curl -fsSL .../install.sh | sudo bash -s -- --yes' ;;
    err_missing_path) printf '%s' 'Missing %s' ;;
    err_curl_required) printf '%s' 'curl is required' ;;
    err_curl_or_wget) printf '%s' 'curl or wget required' ;;
    msg_aborted) printf '%s' 'Aborted' ;;
    log_confirm_yes) printf '%s' '%s → yes (--yes)' ;;
    opt_lang) printf '%s' '--lang=ru|en     Installer language (default: ru; also AWG_GUI_LANG)' ;;

    # Docker
    docker_already) printf '%s' 'Docker and Compose already installed' ;;
    docker_not_found) printf '%s' 'Docker not found (detected OS: %s)' ;;
    docker_docs) printf '%s' 'Docs: https://docs.docker.com/engine/install/' ;;
    docker_confirm_install) printf '%s' 'Docker is required. Install from official repositories now?' ;;
    docker_required_die) printf '%s' 'Docker is required. https://docs.docker.com/engine/install/' ;;
    docker_installing) printf '%s' 'Installing Docker Engine for %s (%s) ...' ;;
    docker_installed) printf '%s' 'Docker Engine installed' ;;
    docker_remove_conflicts) printf '%s' 'Removing conflicting Docker packages: %s' ;;
    docker_err_no_os_release) printf '%s' 'Cannot detect OS (/etc/os-release missing)' ;;
    docker_err_unsupported_os) printf '%s' "Unsupported OS '%s'. Install Docker manually: https://docs.docker.com/engine/install/" ;;
    docker_err_unsupported_arch) printf '%s' 'Unsupported architecture for Docker: %s' ;;
    docker_err_unknown_apt) printf '%s' "Internal error: unknown apt distro '%s'" ;;
    docker_err_no_codename) printf '%s' 'Cannot determine %s codename for Docker repo' ;;
    docker_err_binary_missing) printf '%s' 'Docker binary missing after install' ;;
    docker_err_compose_missing) printf '%s' 'docker compose plugin missing after install' ;;
    docker_err_load_helper) printf '%s' 'Failed to load Docker install helper. Install Docker manually: https://docs.docker.com/engine/install/' ;;

    # Online install (dist/install.sh)
    err_unsupported_arch) printf '%s' 'Unsupported architecture: %s' ;;
    err_bundle_not_found) printf '%s' 'Bundle not found: %s' ;;
    warn_stale_tmp) printf '%s' 'Found %s stale awg-gui temp artifact(s) in /tmp using %s' ;;
    confirm_remove_stale_tmp) printf '%s' 'Remove stale awg-gui temp files from /tmp before install?' ;;
    ok_removed_stale_tmp) printf '%s' 'Removed stale awg-gui temp files from /tmp' ;;
    warn_keep_stale_tmp) printf '%s' 'Keeping stale /tmp artifacts may cause install to run out of disk space' ;;
    log_cleanup_after_bundle) printf '%s' 'Cleaning temporary files and unused Docker images ...' ;;
    ok_removed_unused_images_tmp) printf '%s' 'Removed %s unused awg-gui image(s) and cleaned /tmp' ;;
    ok_cleaned_tmp_dangling) printf '%s' 'Cleaned /tmp and dangling Docker images' ;;
    err_free_space_check) printf '%s' 'Failed to check free space for %s' ;;
    err_not_enough_space) printf '%s' 'Not enough free space for %s at %s: need at least %s free, have %s. Clean disk space and retry.' ;;
    ok_free_space) printf '%s' '%s free space OK at %s (%s available)' ;;
    log_fetch_release) printf '%s' 'Fetching release metadata from GitHub (%s) ...' ;;
    err_fetch_release) printf '%s' 'Failed to fetch release info from GitHub' ;;
    err_github_rate_limit) printf '%s' 'GitHub API rate limit. Set AWG_GUI_VERSION and retry later, or download .run manually.' ;;
    err_release_bundle_missing) printf '%s' 'Release bundle awg-gui-*-%s.run not found for %s' ;;
    log_download_started) printf '%s' 'Bundle download started%s ...' ;;
    log_downloading_file) printf '%s' 'Downloading %s ...' ;;
    log_downloading_file_size) printf '%s' 'Downloading %s (%s) ...' ;;
    ok_download_complete) printf '%s' 'Download complete (%s)' ;;
    warn_download_retry) printf '%s' 'Download incomplete or failed (attempt %s, consecutive no-progress %s, exit %s, got %s) — retrying with resume ...' ;;
    warn_download_stall) printf '%s' 'Download stalled (no progress for %ss, got %s) — killing transfer and resuming ...' ;;
    err_download_failed) printf '%s' 'Download failed (exit code %s). Check network and retry.' ;;
    err_download_incomplete) printf '%s' 'Download incomplete: got %s, expected %s. Check network and retry.' ;;
    ok_using_local_bundle) printf '%s' 'Using local bundle %s' ;;
    log_running_release) printf '%s' 'Running release installer ...' ;;
    label_temp_space) printf '%s' 'installer temp space' ;;
    label_install_space) printf '%s' 'install directory space' ;;
    label_docker_space) printf '%s' 'Docker data space' ;;

    # run-header
    log_extracting_bundle) printf '%s' 'Extracting release bundle ...' ;;
    log_starting_installer) printf '%s' 'Starting installer in %s ...' ;;
    err_corrupt_bundle) printf '%s' 'Corrupt bundle (payload marker missing)' ;;

    # bundle / shared install
    err_missing_ensure_docker) printf '%s' 'Missing ensure-docker.sh helper' ;;
    ok_curl_present) printf '%s' 'curl present' ;;
    ok_curl_ready) printf '%s' 'curl ready' ;;
    warn_kernel_helper_missing) printf '%s' 'Kernel helper missing at %s; skip kernel install' ;;
    log_skip_kernel) printf '%s' 'Skipping AmneziaWG kernel module (--no-awg-kernel / AWG_GUI_SKIP_KERNEL)' ;;
    ok_kernel_already) printf '%s' 'AmneziaWG kernel module already installed — skipping' ;;
    confirm_install_kernel) printf '%s' 'Install AmneziaWG kernel module? Recommended for YouTube/Instagram streaming (https://github.com/amnezia-vpn/amneziawg-linux-kernel-module)' ;;
    log_installing_kernel) printf '%s' 'Installing AmneziaWG kernel module on host (may take several minutes)...' ;;
    ok_kernel_installed) printf '%s' 'AmneziaWG kernel module installed' ;;
    warn_kernel_failed) printf '%s' 'Kernel module install failed — continuing with userspace amneziawg-go' ;;
    log_kernel_skipped_user) printf '%s' 'Kernel module skipped by user' ;;
    log_kernel_skip_upgrade_not_installed) printf '%s' 'AmneziaWG kernel module is not installed — skipping forced install on upgrade' ;;
    warn_leftover_env) printf '%s' 'Leftover %s without containers — clean install with new passwords' ;;
    warn_incomplete_repair) printf '%s' 'Incomplete install detected — continuing automatic repair ...' ;;
    log_existing_upgrade_yes) printf '%s' 'Existing install detected → upgrade mode (--yes)' ;;
    warn_existing_install) printf '%s' 'Existing awggui install detected.' ;;
    choice_abort) printf '%s' '  [1] Abort' ;;
    choice_abort_recommend) printf '%s' '  [1] Abort (uninstall recommended before clean install)' ;;
    choice_upgrade) printf '%s' '  [2] Upgrade (keep .env, volumes, DB/AWG data)' ;;
    prompt_choice_1_2) printf '%s' 'Choice [1/2]: ' ;;
    log_install_aborted) printf '%s' 'Installation aborted.' ;;
    ok_panel_ops_token) printf '%s' 'Generated PANEL_OPS_TOKEN in %s' ;;
    ok_removed_archives) printf '%s' 'Removed bundled image archive(s) from %s/images' ;;
    log_removing_unused_images) printf '%s' 'Removing unused awg-gui Docker images ...' ;;
    log_removed_unused_image) printf '%s' 'Removed unused image %s' ;;
    ok_removed_n_images) printf '%s' 'Removed %s unused awg-gui image(s)' ;;
    ok_no_unused_images) printf '%s' 'No unused awg-gui images to remove' ;;
    err_missing_image_archive) printf '%s' 'Missing %s/images/awggui-all-*.tar.gz' ;;
    log_loading_images) printf '%s' 'Loading Docker images from %s ...' ;;
    ok_images_loaded) printf '%s' 'Images loaded' ;;
    ok_cli_systemd) printf '%s' 'CLI /usr/local/bin/awg-gui and systemd awg-gui.service installed' ;;
    helper_management) printf '%s' 'Management:' ;;
    helper_management_system) printf '%s' 'Management (system-wide):' ;;
    helper_uninstall_prod) printf '%s' 'Uninstall (production):' ;;
    banner_established) printf '%s' 'AmneziaWG GUI established' ;;
    banner_upgraded) printf '%s' 'AmneziaWG GUI upgraded' ;;
    banner_password_unchanged) printf '%s' '(unchanged — use awg-gui password)' ;;
    log_waiting_app) printf '%s' 'Waiting for app container...' ;;
    warn_app_not_ready) printf '%s' 'App container not ready — bootstrap steps may fail' ;;
    log_waiting_runtime) printf '%s' 'Waiting for runtime services to report healthy/running state...' ;;
    ok_all_containers) printf '%s' 'All containers are running' ;;
    ok_public_api) printf '%s' 'Public API responded on %s' ;;
    warn_startup_diag) printf '%s' 'Startup diagnostics:' ;;
    warn_debug_hint) printf '%s' 'For container logs re-run with --debug (or AWG_GUI_DEBUG=1)' ;;
    warn_recent_logs) printf '%s' 'Recent logs for %s:' ;;
    err_services_not_ready) printf '%s' 'Not all awg-gui services reached running/healthy state after install' ;;
    err_panel_unreachable) printf '%s' 'Panel API did not become reachable after install' ;;
    log_waiting_migrations) printf '%s' 'Waiting for in-container migrations to finish (if any)...' ;;
    warn_migrate_lock) printf '%s' 'Timed out waiting for migration lock' ;;
    log_running_migrations) printf '%s' 'Running migrations...' ;;
    log_ensuring_admin_preserve) printf '%s' 'Ensuring admin user exists (preserving password)...' ;;
    warn_admin_ensure_skipped) printf '%s' 'admin:ensure skipped (will rely on existing DB user)' ;;
    log_ensuring_admin) printf '%s' 'Ensuring admin user...' ;;
    log_bootstrapping_awg) printf '%s' 'Bootstrapping AmneziaWG config...' ;;
    warn_tun_missing) printf '%s' '/dev/net/tun not found — AWG userspace may still work' ;;
    ok_using_existing_env) printf '%s' 'Using existing %s' ;;
    ok_using_existing_env_merged) printf '%s' 'Using existing %s (missing keys merged from .env.example)' ;;
    prompt_panel_port) printf '%s' 'Panel port' ;;
    prompt_awg_port) printf '%s' 'AmneziaWG UDP port (AWG_PORT)' ;;
    prompt_endpoint) printf '%s' 'Server endpoint (public IP/DNS, or auto)' ;;
    prompt_internal_subnet) printf '%s' 'Internal subnet (INTERNAL_SUBNET)' ;;
    prompt_peer_dns) printf '%s' 'Peer DNS (PEER_DNS)' ;;
    prompt_allowed_ips) printf '%s' 'AllowedIPs for clients (ALLOWED_IPS)' ;;
    warn_port_busy) printf '%s' '%s: port %s is already in use — using free port %s instead' ;;
    warn_port_invalid) printf '%s' '%s: invalid port %s — using free port %s instead' ;;
    err_no_free_port) printf '%s' 'Could not find a free host port for %s' ;;
    ok_created_env) printf '%s' 'Created %s' ;;
    ok_created_env_dev) printf '%s' 'Created %s from .env.example (random DB password generated)' ;;
    log_starting_containers) printf '%s' 'Starting containers ...' ;;
    log_preparing_awg_recreate) printf '%s' 'Preparing awggui-awg for recreate (force-stop if needed) ...' ;;
    log_preparing_host_kernel_awg_stop) printf '%s' 'Host amneziawg module may wedge AWG stop — unloading or blacklisting before container recreate ...' ;;
    ok_host_kernel_unloaded) printf '%s' 'Host amneziawg module unloaded — safe to recreate awggui-awg' ;;
    ok_host_kernel_blacklist_present) printf '%s' 'Host amneziawg blacklist already active — userspace datapath' ;;
    warn_host_kernel_blacklisted) printf '%s' 'Could not unload amneziawg — wrote blacklist-amneziawg.conf (userspace until DKMS is fixed)' ;;
    warn_upgrade_keep_images_retry) printf '%s' 'Upgrade failed before compose up — keeping loaded image archives for retry (re-run installer without re-download if tar still in images/)' ;;
    log_force_removing_container) printf '%s' 'Force-removing stuck container %s ...' ;;
    ok_force_removed_container) printf '%s' 'Removed container %s' ;;
    warn_kill_container_host_pid) printf '%s' 'Container %s did not exit — sending SIGKILL to host PID %s' ;;
    warn_restart_docker_stuck) printf '%s' 'Container still stuck — restarting Docker engine (volumes preserved) ...' ;;
    warn_container_still_stuck) printf '%s' 'Could not remove container %s; a host reboot may be required' ;;
    warn_exit_event_stuck) printf '%s' 'Docker could not kill %s (no exit event) — restarting Docker engine ...' ;;
    warn_compose_up_stuck_retry) printf '%s' 'compose up failed stopping a container — recovering and retrying ...' ;;
    err_awg_container_stuck_reboot) printf '%s' 'Could not remove hung awggui-awg (Docker exit-event wedge). Reboot the server, then run: cd /opt/awg-gui/runtime && docker compose up -d  (or: awg-gui ensure-up). Volumes are preserved.' ;;
    ok_repair_complete) printf '%s' 'Install repair complete' ;;
    ok_upgrade_complete) printf '%s' 'Upgrade complete' ;;
    ok_install_complete) printf '%s' 'Installation complete' ;;

    # Dev install extras
    err_unsupported_singbox_arch) printf '%s' 'Unsupported sing-box architecture: %s' ;;
    err_curl_manual) printf '%s' 'curl is required. Install it manually and re-run.' ;;
    err_cannot_install_curl) printf '%s' 'Cannot install curl on %s. Install curl manually.' ;;
    err_curl_install_failed) printf '%s' 'curl install failed' ;;
    confirm_install_packages) printf '%s' 'Missing packages (%s). Install via package manager?' ;;
    warn_jq_optional) printf '%s' 'jq not installed (optional for rich webhook JSON)' ;;
    log_installing_packages) printf '%s' 'Installing %s...' ;;
    ok_singbox_present) printf '%s' 'sing-box vendor present (%s)' ;;
    confirm_download_singbox) printf '%s' 'sing-box tarball is missing (%s). Download from GitHub?' ;;
    err_singbox_required) printf '%s' 'sing-box vendor required for AWG image. Place tarball in src/awg/vendor/ and re-run.' ;;
    log_downloading_singbox) printf '%s' 'Downloading sing-box %s (%s)...' ;;
    ok_downloaded) printf '%s' 'Downloaded %s' ;;
    log_prune_build_cache) printf '%s' 'Freeing Docker build cache (small disks)...' ;;
    log_building_containers) printf '%s' 'Building and starting containers (this may take several minutes)...' ;;

    # Uninstall
    confirm_remove_stack) printf '%s' 'Remove awggui stack, volumes, images, logs and build cache?' ;;
    confirm_remove_target) printf '%s' 'Are you sure you want to remove %s?' ;;
    confirm_target_awg) printf '%s' 'AWG GUI' ;;
    confirm_target_awg_and_dir) printf '%s' 'AWG GUI and %s' ;;
    log_noninteractive_yes) printf '%s' 'Non-interactive shell — skipping confirmation (--yes implied)' ;;
    log_noninteractive_yes_curl) printf '%s' 'Non-interactive shell — skipping confirmation (--yes implied for curl|bash)' ;;
    err_no_tty_uninstall) printf '%s' 'No interactive terminal for confirmation. Re-run with --yes, for example:\n  sudo ./awg-gui-uninstall.sh --yes' ;;
    err_no_tty_uninstall_curl) printf '%s' 'No interactive terminal for confirmation. Re-run with --yes, for example:\n  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes' ;;
    log_removing_logs) printf '%s' 'Removing project logs ...' ;;
    log_pruning_docker) printf '%s' 'Pruning dangling images and Docker build cache ...' ;;
    log_compose_missing) printf '%s' 'Compose file missing — removing containers by name if present' ;;
    log_compose_missing_short) printf '%s' 'Compose file missing — removing containers by name' ;;
    ok_systemd_removed) printf '%s' 'systemd unit removed' ;;
    ok_compose_down) printf '%s' 'compose down -v completed' ;;
    ok_cli_etc_removed) printf '%s' 'CLI, /etc/awg-gui and project logs removed' ;;
    ok_removed_path) printf '%s' 'Removed %s' ;;
    ok_images_cache_removed) printf '%s' 'Project images, dangling layers and build cache removed' ;;
    ok_uninstall_finished_dev) printf '%s' 'Uninstall finished. Repository source files were kept. Docker Engine was not removed.' ;;
    ok_uninstall_finished) printf '%s' 'Uninstall finished.' ;;
    ok_uninstall_finished_prod) printf '%s' 'Production uninstall finished.' ;;
    log_using_bundle_uninstaller) printf '%s' 'Using installed bundle uninstaller ...' ;;
    log_no_install_fallback) printf '%s' 'No production install found at %s — running fallback cleanup ...' ;;

    # usage snippets (short option lines)
    usage_opt_yes) printf '%s' '--yes              Non-interactive (defaults; installs kernel module unless skipped)' ;;
    usage_opt_yes_uninstall) printf '%s' '--yes          Skip confirmation' ;;
    usage_opt_no_kernel) printf '%s' '--no-awg-kernel    Skip AmneziaWG kernel module install' ;;
    usage_opt_debug) printf '%s' '--debug            Show container logs on install failure' ;;
    usage_opt_keep_images) printf '%s' '--keep-images  Keep awggui Docker images and build cache' ;;
    usage_opt_images) printf '%s' '--images       Accepted for compatibility (images are removed by default)' ;;
    usage_opt_purge) printf '%s' '--purge        Remove install directory after uninstall' ;;
    usage_opt_purge_dir) printf '%s' '--purge        Also remove %s' ;;
    usage_opt_bundle) printf '%s' '--bundle=PATH      Use local .run bundle (skip download)' ;;
    usage_opt_dir) printf '%s' '--dir=/opt/awg-gui Install directory' ;;

    *) printf '%s' "$1" ;;
  esac
}

_awg_gui_msg_ru() {
  case "$1" in
    err_unknown_arg) printf '%s' 'Неизвестный аргумент: %s' ;;
    err_unknown_arg_help) printf '%s' 'Неизвестный аргумент: %s (см. --help)' ;;
    err_run_as_root) printf '%s' 'Запустите от root (sudo)' ;;
    err_run_as_root_install_curl) printf '%s' 'Запустите от root: curl -fsSL .../dist/install.sh | sudo bash' ;;
    err_run_as_root_uninstall_curl) printf '%s' 'Запустите от root: curl -fsSL .../dist/uninstall.sh | sudo bash' ;;
    err_no_tty_use_yes) printf '%s' 'Нет TTY. Перезапустите с --yes' ;;
    err_no_tty_docker) printf '%s' 'Нет TTY. Перезапустите с --yes, например: curl -fsSL .../install.sh | sudo bash -s -- --yes' ;;
    err_missing_path) printf '%s' 'Отсутствует %s' ;;
    err_curl_required) printf '%s' 'Требуется curl' ;;
    err_curl_or_wget) printf '%s' 'Требуется curl или wget' ;;
    msg_aborted) printf '%s' 'Отменено' ;;
    log_confirm_yes) printf '%s' '%s → да (--yes)' ;;
    opt_lang) printf '%s' '--lang=ru|en     Язык установщика (по умолчанию: ru; также AWG_GUI_LANG)' ;;

    docker_already) printf '%s' 'Docker и Compose уже установлены' ;;
    docker_not_found) printf '%s' 'Docker не найден (ОС: %s)' ;;
    docker_docs) printf '%s' 'Документация: https://docs.docker.com/engine/install/' ;;
    docker_confirm_install) printf '%s' 'Docker обязателен. Установить из официальных репозиториев сейчас?' ;;
    docker_required_die) printf '%s' 'Docker обязателен. https://docs.docker.com/engine/install/' ;;
    docker_installing) printf '%s' 'Установка Docker Engine для %s (%s) ...' ;;
    docker_installed) printf '%s' 'Docker Engine установлен' ;;
    docker_remove_conflicts) printf '%s' 'Удаление конфликтующих пакетов Docker: %s' ;;
    docker_err_no_os_release) printf '%s' 'Не удалось определить ОС (/etc/os-release отсутствует)' ;;
    docker_err_unsupported_os) printf '%s' "Неподдерживаемая ОС '%s'. Установите Docker вручную: https://docs.docker.com/engine/install/" ;;
    docker_err_unsupported_arch) printf '%s' 'Неподдерживаемая архитектура для Docker: %s' ;;
    docker_err_unknown_apt) printf '%s' "Внутренняя ошибка: неизвестный apt-дистрибутив '%s'" ;;
    docker_err_no_codename) printf '%s' 'Не удалось определить кодовое имя %s для репозитория Docker' ;;
    docker_err_binary_missing) printf '%s' 'Бинарник Docker отсутствует после установки' ;;
    docker_err_compose_missing) printf '%s' 'Плагин docker compose отсутствует после установки' ;;
    docker_err_load_helper) printf '%s' 'Не удалось загрузить helper установки Docker. Установите Docker вручную: https://docs.docker.com/engine/install/' ;;

    err_unsupported_arch) printf '%s' 'Неподдерживаемая архитектура: %s' ;;
    err_bundle_not_found) printf '%s' 'Бандл не найден: %s' ;;
    warn_stale_tmp) printf '%s' 'Найдено устаревших временных файлов awg-gui в /tmp: %s (занимают %s)' ;;
    confirm_remove_stale_tmp) printf '%s' 'Удалить устаревшие временные файлы awg-gui из /tmp перед установкой?' ;;
    ok_removed_stale_tmp) printf '%s' 'Устаревшие временные файлы awg-gui в /tmp удалены' ;;
    warn_keep_stale_tmp) printf '%s' 'Сохранение устаревших файлов в /tmp может привести к нехватке места на диске' ;;
    log_cleanup_after_bundle) printf '%s' 'Очистка временных файлов и неиспользуемых образов Docker ...' ;;
    ok_removed_unused_images_tmp) printf '%s' 'Удалено неиспользуемых образов awg-gui: %s; /tmp очищен' ;;
    ok_cleaned_tmp_dangling) printf '%s' '/tmp и dangling-образы Docker очищены' ;;
    err_free_space_check) printf '%s' 'Не удалось проверить свободное место для %s' ;;
    err_not_enough_space) printf '%s' 'Недостаточно места для %s в %s: нужно минимум %s свободно, доступно %s. Освободите диск и повторите.' ;;
    ok_free_space) printf '%s' 'Свободное место для %s в %s OK (%s доступно)' ;;
    log_fetch_release) printf '%s' 'Получение метаданных релиза с GitHub (%s) ...' ;;
    err_fetch_release) printf '%s' 'Не удалось получить информацию о релизе с GitHub' ;;
    err_github_rate_limit) printf '%s' 'Лимит GitHub API. Укажите AWG_GUI_VERSION и повторите позже или скачайте .run вручную.' ;;
    err_release_bundle_missing) printf '%s' 'Release-бандл awg-gui-*-%s.run не найден для %s' ;;
    log_download_started) printf '%s' 'Начата загрузка бандла%s ...' ;;
    log_downloading_file) printf '%s' 'Скачивание %s ...' ;;
    log_downloading_file_size) printf '%s' 'Скачивание %s (%s) ...' ;;
    ok_download_complete) printf '%s' 'Загрузка завершена (%s)' ;;
    warn_download_retry) printf '%s' 'Загрузка неполная или с ошибкой (попытка %s, без прогресса подряд %s, код %s, получено %s) — повтор с докачкой ...' ;;
    warn_download_stall) printf '%s' 'Загрузка зависла (нет прогресса %s с, получено %s) — обрыв и докачка ...' ;;
    err_download_failed) printf '%s' 'Ошибка загрузки (код выхода %s). Проверьте сеть и повторите.' ;;
    err_download_incomplete) printf '%s' 'Неполная загрузка: получено %s, ожидалось %s. Проверьте сеть и повторите.' ;;
    ok_using_local_bundle) printf '%s' 'Используется локальный бандл %s' ;;
    log_running_release) printf '%s' 'Запуск release-установщика ...' ;;
    label_temp_space) printf '%s' 'временное пространство установщика' ;;
    label_install_space) printf '%s' 'пространство каталога установки' ;;
    label_docker_space) printf '%s' 'пространство данных Docker' ;;

    log_extracting_bundle) printf '%s' 'Извлечение release-бандла ...' ;;
    log_starting_installer) printf '%s' 'Запуск установщика в %s ...' ;;
    err_corrupt_bundle) printf '%s' 'Повреждённый бандл (нет маркера payload)' ;;

    err_missing_ensure_docker) printf '%s' 'Отсутствует helper ensure-docker.sh' ;;
    ok_curl_present) printf '%s' 'curl уже установлен' ;;
    ok_curl_ready) printf '%s' 'curl готов' ;;
    warn_kernel_helper_missing) printf '%s' 'Нет helper ядра: %s; установка модуля пропущена' ;;
    log_skip_kernel) printf '%s' 'Пропуск модуля ядра AmneziaWG (--no-awg-kernel / AWG_GUI_SKIP_KERNEL)' ;;
    ok_kernel_already) printf '%s' 'Модуль ядра AmneziaWG уже установлен — пропускаем' ;;
    confirm_install_kernel) printf '%s' 'Установить модуль ядра AmneziaWG? Рекомендуется для YouTube/Instagram (https://github.com/amnezia-vpn/amneziawg-linux-kernel-module)' ;;
    log_installing_kernel) printf '%s' 'Установка модуля ядра AmneziaWG на хост (может занять несколько минут)...' ;;
    ok_kernel_installed) printf '%s' 'Модуль ядра AmneziaWG установлен' ;;
    warn_kernel_failed) printf '%s' 'Установка модуля ядра не удалась — продолжаем с userspace amneziawg-go' ;;
    log_kernel_skipped_user) printf '%s' 'Модуль ядра пропущен пользователем' ;;
    log_kernel_skip_upgrade_not_installed) printf '%s' 'Модуль ядра AmneziaWG не установлен — принудительная установка при обновлении пропущена' ;;
    warn_leftover_env) printf '%s' 'Найден оставшийся %s без контейнеров — чистая установка с новыми паролями' ;;
    warn_incomplete_repair) printf '%s' 'Обнаружена незавершённая установка — продолжаем восстановление автоматически ...' ;;
    log_existing_upgrade_yes) printf '%s' 'Обнаружена установка → режим обновления (--yes)' ;;
    warn_existing_install) printf '%s' 'Обнаружена существующая установка awggui.' ;;
    choice_abort) printf '%s' '  [1] Прервать' ;;
    choice_abort_recommend) printf '%s' '  [1] Прервать (рекомендуется uninstall перед чистой установкой)' ;;
    choice_upgrade) printf '%s' '  [2] Обновить (сохранить .env, volumes, данные БД/AWG)' ;;
    prompt_choice_1_2) printf '%s' 'Выбор [1/2]: ' ;;
    log_install_aborted) printf '%s' 'Установка прервана.' ;;
    ok_panel_ops_token) printf '%s' 'Сгенерирован PANEL_OPS_TOKEN в %s' ;;
    ok_removed_archives) printf '%s' 'Архивы образов удалены из %s/images' ;;
    log_removing_unused_images) printf '%s' 'Удаление неиспользуемых образов awg-gui ...' ;;
    log_removed_unused_image) printf '%s' 'Удалён неиспользуемый образ %s' ;;
    ok_removed_n_images) printf '%s' 'Удалено неиспользуемых образов awg-gui: %s' ;;
    ok_no_unused_images) printf '%s' 'Нет неиспользуемых образов awg-gui для удаления' ;;
    err_missing_image_archive) printf '%s' 'Отсутствует %s/images/awggui-all-*.tar.gz' ;;
    log_loading_images) printf '%s' 'Загрузка Docker-образов из %s ...' ;;
    ok_images_loaded) printf '%s' 'Образы загружены' ;;
    ok_cli_systemd) printf '%s' 'CLI /usr/local/bin/awg-gui и systemd awg-gui.service установлены' ;;
    helper_management) printf '%s' 'Управление:' ;;
    helper_management_system) printf '%s' 'Управление (системное):' ;;
    helper_uninstall_prod) printf '%s' 'Удаление (production):' ;;
    banner_established) printf '%s' 'AmneziaWG GUI установлен' ;;
    banner_upgraded) printf '%s' 'AmneziaWG GUI обновлён' ;;
    banner_password_unchanged) printf '%s' '(без изменений — используйте awg-gui password)' ;;
    log_waiting_app) printf '%s' 'Ожидание контейнера app...' ;;
    warn_app_not_ready) printf '%s' 'Контейнер app не готов — bootstrap может завершиться с ошибкой' ;;
    log_waiting_runtime) printf '%s' 'Ожидание healthy/running состояния сервисов...' ;;
    ok_all_containers) printf '%s' 'Все контейнеры запущены' ;;
    ok_public_api) printf '%s' 'Публичный API ответил на %s' ;;
    warn_startup_diag) printf '%s' 'Диагностика запуска:' ;;
    warn_debug_hint) printf '%s' 'Для логов контейнеров перезапустите с --debug (или AWG_GUI_DEBUG=1)' ;;
    warn_recent_logs) printf '%s' 'Последние логи %s:' ;;
    err_services_not_ready) printf '%s' 'Не все сервисы awg-gui достигли running/healthy после установки' ;;
    err_panel_unreachable) printf '%s' 'API панели недоступен после установки' ;;
    log_waiting_migrations) printf '%s' 'Ожидание завершения миграций в контейнере (если есть)...' ;;
    warn_migrate_lock) printf '%s' 'Таймаут ожидания блокировки миграций' ;;
    log_running_migrations) printf '%s' 'Выполнение миграций...' ;;
    log_ensuring_admin_preserve) printf '%s' 'Проверка пользователя admin (пароль не перезаписывается)...' ;;
    warn_admin_ensure_skipped) printf '%s' 'admin:ensure пропущен (будет использован существующий пользователь БД)' ;;
    log_ensuring_admin) printf '%s' 'Создание/проверка пользователя admin...' ;;
    log_bootstrapping_awg) printf '%s' 'Инициализация конфигурации AmneziaWG...' ;;
    warn_tun_missing) printf '%s' '/dev/net/tun не найден — AWG userspace всё ещё может работать' ;;
    ok_using_existing_env) printf '%s' 'Используется существующий %s' ;;
    ok_using_existing_env_merged) printf '%s' 'Используется существующий %s (недостающие ключи из .env.example)' ;;
    prompt_panel_port) printf '%s' 'Порт панели' ;;
    prompt_awg_port) printf '%s' 'UDP-порт AmneziaWG (AWG_PORT)' ;;
    prompt_endpoint) printf '%s' 'Endpoint сервера (публичный IP/DNS или auto)' ;;
    prompt_internal_subnet) printf '%s' 'Внутренняя подсеть (INTERNAL_SUBNET)' ;;
    prompt_peer_dns) printf '%s' 'DNS для клиентов (PEER_DNS)' ;;
    prompt_allowed_ips) printf '%s' 'AllowedIPs для клиентов (ALLOWED_IPS)' ;;
    warn_port_busy) printf '%s' '%s: порт %s уже занят — используем свободный порт %s' ;;
    warn_port_invalid) printf '%s' '%s: недопустимый порт %s — используем свободный порт %s' ;;
    err_no_free_port) printf '%s' 'Не удалось найти свободный порт хоста для %s' ;;
    ok_created_env) printf '%s' 'Создан %s' ;;
    ok_created_env_dev) printf '%s' 'Создан %s из .env.example (сгенерирован случайный пароль БД)' ;;
    log_starting_containers) printf '%s' 'Запуск контейнеров ...' ;;
    log_preparing_awg_recreate) printf '%s' 'Подготовка awggui-awg к пересозданию (принудительная остановка при зависании) ...' ;;
    log_preparing_host_kernel_awg_stop) printf '%s' 'Модуль amneziawg на хосте может зависнуть при остановке AWG — выгружаем или blacklist перед пересозданием ...' ;;
    ok_host_kernel_unloaded) printf '%s' 'Модуль amneziawg на хосте выгружен — безопасно пересоздавать awggui-awg' ;;
    ok_host_kernel_blacklist_present) printf '%s' 'Blacklist amneziawg на хосте уже активен — datapath userspace' ;;
    warn_host_kernel_blacklisted) printf '%s' 'Не удалось выгрузить amneziawg — создан blacklist-amneziawg.conf (userspace до фикса DKMS)' ;;
    warn_upgrade_keep_images_retry) printf '%s' 'Обновление упало до compose up — архивы образов сохранены для повтора (перезапустите установщик без повторной загрузки, если tar ещё в images/)' ;;
    log_force_removing_container) printf '%s' 'Принудительное удаление зависшего контейнера %s ...' ;;
    ok_force_removed_container) printf '%s' 'Контейнер %s удалён' ;;
    warn_kill_container_host_pid) printf '%s' 'Контейнер %s не завершился — SIGKILL процессу на хосте PID %s' ;;
    warn_restart_docker_stuck) printf '%s' 'Контейнер всё ещё завис — перезапуск Docker (volumes сохраняются) ...' ;;
    warn_container_still_stuck) printf '%s' 'Не удалось удалить контейнер %s; может потребоваться перезагрузка сервера' ;;
    warn_exit_event_stuck) printf '%s' 'Docker не смог убить %s (нет exit event) — перезапуск Docker ...' ;;
    warn_compose_up_stuck_retry) printf '%s' 'compose up не смог остановить контейнер — восстановление и повтор ...' ;;
    err_awg_container_stuck_reboot) printf '%s' 'Не удалось удалить зависший awggui-awg (wedge Docker exit-event). Перезагрузите сервер, затем: cd /opt/awg-gui/runtime && docker compose up -d  (или: awg-gui ensure-up). Volumes сохраняются.' ;;
    ok_repair_complete) printf '%s' 'Восстановление установки завершено' ;;
    ok_upgrade_complete) printf '%s' 'Обновление завершено' ;;
    ok_install_complete) printf '%s' 'Установка завершена' ;;

    err_unsupported_singbox_arch) printf '%s' 'Неподдерживаемая архитектура sing-box: %s' ;;
    err_curl_manual) printf '%s' 'Требуется curl. Установите вручную и запустите снова.' ;;
    err_cannot_install_curl) printf '%s' 'Не удалось установить curl на %s. Установите curl вручную.' ;;
    err_curl_install_failed) printf '%s' 'Установка curl не удалась' ;;
    confirm_install_packages) printf '%s' 'Не хватает пакетов (%s). Установить через пакетный менеджер?' ;;
    warn_jq_optional) printf '%s' 'jq не установлен (опционален для подробного webhook JSON)' ;;
    log_installing_packages) printf '%s' 'Установка %s...' ;;
    ok_singbox_present) printf '%s' 'sing-box vendor уже есть (%s)' ;;
    confirm_download_singbox) printf '%s' 'Tarball sing-box отсутствует (%s). Скачать с GitHub?' ;;
    err_singbox_required) printf '%s' 'Для образа AWG нужен tarball sing-box. Положите его в src/awg/vendor/ и запустите снова.' ;;
    log_downloading_singbox) printf '%s' 'Скачивание sing-box %s (%s)...' ;;
    ok_downloaded) printf '%s' 'Скачано: %s' ;;
    log_prune_build_cache) printf '%s' 'Очистка Docker build cache (для маленьких дисков)...' ;;
    log_building_containers) printf '%s' 'Сборка и запуск контейнеров (может занять несколько минут)...' ;;

    confirm_remove_stack) printf '%s' 'Удалить стек awggui, volumes, образы, логи и build cache?' ;;
    confirm_remove_target) printf '%s' 'Вы уверены, что хотите удалить %s?' ;;
    confirm_target_awg) printf '%s' 'AWG GUI' ;;
    confirm_target_awg_and_dir) printf '%s' 'AWG GUI и %s' ;;
    log_noninteractive_yes) printf '%s' 'Нет интерактива — подтверждение пропущено (--yes подразумевается)' ;;
    log_noninteractive_yes_curl) printf '%s' 'Нет интерактива — подтверждение пропущено (--yes подразумевается для curl|bash)' ;;
    err_no_tty_uninstall) printf '%s' 'Нет интерактивного терминала. Перезапустите с --yes, например:\n  sudo ./awg-gui-uninstall.sh --yes' ;;
    err_no_tty_uninstall_curl) printf '%s' 'Нет интерактивного терминала. Перезапустите с --yes, например:\n  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes' ;;
    log_removing_logs) printf '%s' 'Удаление логов проекта ...' ;;
    log_pruning_docker) printf '%s' 'Очистка dangling-образов и Docker build cache ...' ;;
    log_compose_missing) printf '%s' 'Файл compose отсутствует — удаляем контейнеры по имени, если есть' ;;
    log_compose_missing_short) printf '%s' 'Файл compose отсутствует — удаляем контейнеры по имени' ;;
    ok_systemd_removed) printf '%s' 'unit systemd удалён' ;;
    ok_compose_down) printf '%s' 'compose down -v выполнен' ;;
    ok_cli_etc_removed) printf '%s' 'CLI, /etc/awg-gui и логи проекта удалены' ;;
    ok_removed_path) printf '%s' 'Удалено: %s' ;;
    ok_images_cache_removed) printf '%s' 'Образы проекта, dangling-слои и build cache удалены' ;;
    ok_uninstall_finished_dev) printf '%s' 'Удаление завершено. Исходники репозитория сохранены. Docker Engine не удалялся.' ;;
    ok_uninstall_finished) printf '%s' 'Удаление завершено.' ;;
    ok_uninstall_finished_prod) printf '%s' 'Production-удаление завершено.' ;;
    log_using_bundle_uninstaller) printf '%s' 'Используется установленный bundle-uninstaller ...' ;;
    log_no_install_fallback) printf '%s' 'Установка не найдена в %s — запасная очистка ...' ;;

    usage_opt_yes) printf '%s' '--yes              Без интерактива (значения по умолчанию; kernel-модуль ставится, если не отключён)' ;;
    usage_opt_yes_uninstall) printf '%s' '--yes          Пропустить подтверждение' ;;
    usage_opt_no_kernel) printf '%s' '--no-awg-kernel    Пропустить установку модуля ядра AmneziaWG' ;;
    usage_opt_debug) printf '%s' '--debug            Показать логи контейнеров при ошибке установки' ;;
    usage_opt_keep_images) printf '%s' '--keep-images  Сохранить образы awggui и build cache' ;;
    usage_opt_images) printf '%s' '--images       Принимается для совместимости (образы удаляются по умолчанию)' ;;
    usage_opt_purge) printf '%s' '--purge        Удалить каталог установки после uninstall' ;;
    usage_opt_purge_dir) printf '%s' '--purge        Также удалить %s' ;;
    usage_opt_bundle) printf '%s' '--bundle=PATH      Локальный .run бандл (без скачивания)' ;;
    usage_opt_dir) printf '%s' '--dir=/opt/awg-gui Каталог установки' ;;

    *) printf '%s' "$1" ;;
  esac
}

normalize_awg_gui_lang
