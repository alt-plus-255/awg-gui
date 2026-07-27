# ensure-docker.sh — detect distro and install Docker Engine from official repos.
# Expects caller to define: die, log, ok, warn
# Uses optional YES=0|1 (default 0).
# Provides: ensure_docker_engine
#
# Keep in sync with: src/scripts/lib/ensure-docker.sh
# Official docs: https://docs.docker.com/engine/install/

_docker_read_tty() {
  local prompt="$1"
  local ans=""
  if [[ -r /dev/tty ]]; then
    # curl|bash leaves stdin at EOF — always prefer the controlling terminal
    printf '%s' "${prompt}" > /dev/tty
    read -r ans < /dev/tty || true
  elif [[ -t 0 ]]; then
    read -r -p "${prompt}" ans || true
  else
    die "Non-interactive shell (no TTY). Re-run with --yes, e.g.: curl -fsSL .../install.sh | sudo bash -s -- --yes"
  fi
  printf '%s' "${ans}"
}

_docker_confirm_yes() {
  local msg="$1"
  local ans
  if [[ "${YES:-0}" -eq 1 ]]; then
    log "${msg} → yes (--yes)"
    return 0
  fi
  ans="$(_docker_read_tty "${msg} [Y/n]: ")"
  case "${ans}" in
    ""|y|Y|yes|YES) return 0 ;;
    n|N|no|NO) return 1 ;;
    *) return 0 ;;
  esac
}

_docker_detect_os() {
  # shellcheck disable=SC1091
  [[ -f /etc/os-release ]] || die "Cannot detect OS (/etc/os-release missing)"
  source /etc/os-release
  DOCKER_OS_ID="${ID:-}"
  DOCKER_OS_ID_LIKE="${ID_LIKE:-}"
  DOCKER_VERSION_CODENAME="${VERSION_CODENAME:-}"
  DOCKER_UBUNTU_CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
  DOCKER_VERSION_ID="${VERSION_ID:-}"

  # Map derivatives to a Docker-supported family
  case "${DOCKER_OS_ID}" in
    ubuntu|debian|raspbian|fedora|centos|rhel|rocky|almalinux) ;;
    *)
      case " ${DOCKER_OS_ID_LIKE} " in
        *" ubuntu "*) DOCKER_OS_ID=ubuntu ;;
        *" debian "*) DOCKER_OS_ID=debian ;;
        *" rhel "*|*" centos "*) DOCKER_OS_ID=centos ;;
        *" fedora "*) DOCKER_OS_ID=fedora ;;
        *)
          die "Unsupported OS '${DOCKER_OS_ID}'. Install Docker manually: https://docs.docker.com/engine/install/"
          ;;
      esac
      ;;
  esac
}

_docker_detect_arch() {
  local m
  m="$(uname -m)"
  case "$m" in
    x86_64|amd64) DOCKER_ARCH=amd64 ;;
    aarch64|arm64) DOCKER_ARCH=arm64 ;;
    armv7l) DOCKER_ARCH=armhf ;;
    s390x) DOCKER_ARCH=s390x ;;
    ppc64le) DOCKER_ARCH=ppc64le ;;
    *) die "Unsupported architecture for Docker: $m" ;;
  esac
  # dpkg --print-architecture is preferred on Debian/Ubuntu when available
  if command -v dpkg >/dev/null 2>&1; then
    DOCKER_ARCH="$(dpkg --print-architecture)"
  fi
}

_docker_remove_conflicts_apt() {
  local pkgs
  pkgs="$(dpkg --get-selections 2>/dev/null \
    | awk '/^(docker\.io|docker-compose|docker-compose-v2|docker-doc|docker-buildx|podman-docker|containerd|runc)\>/ {print $1}' \
    || true)"
  if [[ -n "${pkgs}" ]]; then
    log "Removing conflicting Docker packages: ${pkgs}"
    # shellcheck disable=SC2086
    apt-get remove -y ${pkgs} >/dev/null 2>&1 || true
  fi
}

_docker_remove_conflicts_dnf() {
  dnf remove -y docker docker-client docker-client-latest docker-common \
    docker-latest docker-latest-logrotate docker-logrotate docker-engine \
    docker-selinux docker-engine-selinux podman runc >/dev/null 2>&1 || true
}

_docker_install_apt_repo() {
  local distro="$1"  # ubuntu|debian
  local suite gpg_url repo_url

  case "${distro}" in
    ubuntu)
      suite="${DOCKER_UBUNTU_CODENAME}"
      gpg_url="https://download.docker.com/linux/ubuntu/gpg"
      repo_url="https://download.docker.com/linux/ubuntu"
      ;;
    debian)
      suite="${DOCKER_VERSION_CODENAME}"
      gpg_url="https://download.docker.com/linux/debian/gpg"
      repo_url="https://download.docker.com/linux/debian"
      ;;
    *) die "Internal error: unknown apt distro '${distro}'" ;;
  esac

  [[ -n "${suite}" ]] || die "Cannot determine ${distro} codename for Docker repo"

  apt-get update -y
  apt-get install -y ca-certificates curl
  _docker_remove_conflicts_apt
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "${gpg_url}" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc

  # Official deb822 source format (docs.docker.com/engine/install/{ubuntu,debian}/)
  cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: ${repo_url}
Suites: ${suite}
Components: stable
Architectures: ${DOCKER_ARCH}
Signed-By: /etc/apt/keyrings/docker.asc
EOF
  # Drop legacy list file if present from older installs
  rm -f /etc/apt/sources.list.d/docker.list

  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

_docker_dnf_add_repo() {
  local url="$1"
  if dnf config-manager --help 2>&1 | grep -q 'addrepo'; then
    dnf config-manager addrepo --from-repofile "${url}" 2>/dev/null \
      || dnf config-manager --add-repo "${url}"
  else
    dnf config-manager --add-repo "${url}"
  fi
}

_docker_install_fedora() {
  dnf -y install dnf-plugins-core
  _docker_remove_conflicts_dnf
  _docker_dnf_add_repo "https://download.docker.com/linux/fedora/docker-ce.repo"
  dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

_docker_install_centos_family() {
  # CentOS / Rocky / Alma — centos repo per Docker docs
  if command -v dnf >/dev/null 2>&1; then
    dnf -y install dnf-plugins-core
    _docker_remove_conflicts_dnf
    _docker_dnf_add_repo "https://download.docker.com/linux/centos/docker-ce.repo"
    dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    yum install -y yum-utils
    yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  fi
}

_docker_install_rhel() {
  dnf -y install dnf-plugins-core
  _docker_remove_conflicts_dnf
  _docker_dnf_add_repo "https://download.docker.com/linux/rhel/docker-ce.repo"
  dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

ensure_docker_engine() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker and Compose already installed"
    systemctl enable --now docker 2>/dev/null || true
    return 0
  fi

  _docker_detect_os
  log "Docker not found (detected OS: ${DOCKER_OS_ID}${DOCKER_VERSION_ID:+ ${DOCKER_VERSION_ID}})"
  log "Docs: https://docs.docker.com/engine/install/"

  if ! _docker_confirm_yes "Docker is required. Install from official repositories now?"; then
    die "Docker is required. https://docs.docker.com/engine/install/"
  fi

  _docker_detect_arch
  log "Installing Docker Engine for ${DOCKER_OS_ID} (${DOCKER_ARCH}) ..."

  case "${DOCKER_OS_ID}" in
    ubuntu)
      _docker_install_apt_repo ubuntu
      ;;
    debian|raspbian)
      _docker_install_apt_repo debian
      ;;
    fedora)
      _docker_install_fedora
      ;;
    rhel)
      _docker_install_rhel
      ;;
    centos|rocky|almalinux)
      _docker_install_centos_family
      ;;
    *)
      die "Unsupported OS '${DOCKER_OS_ID}'. Install Docker manually: https://docs.docker.com/engine/install/"
      ;;
  esac

  systemctl enable --now docker
  # Give the daemon a moment on fresh RPM installs
  sleep 1
  command -v docker >/dev/null 2>&1 || die "Docker binary missing after install"
  docker compose version >/dev/null 2>&1 || die "docker compose plugin missing after install"
  ok "Docker Engine installed"
}
