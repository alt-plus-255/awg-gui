# Install

**Languages:** [Русский](../ru/install.md) | [English](install.md) | [README](../../README.en.md)

## Requirements

- Root / sudo on Linux
- KVM (or host with `/dev/net/tun`)
- Supported OS for auto Docker install: Ubuntu, Debian, Fedora, CentOS/RHEL/Rocky/Alma
- **Docker** and **curl** are installed automatically if missing ([Docker Engine docs](https://docs.docker.com/engine/install/))

## Production (recommended)

Downloads a pre-built release bundle from GitHub Releases. No source checkout, `node_modules`, or local image build required.

Quick one-liner — see [README](../../README.en.md#quick-install-production).

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh)
```

Non-interactive (panel port **8877**, upgrade if already installed):

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh) --yes
```

Specific version:

```bash
sudo AWG_GUI_VERSION=1.0.0 bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh) --yes
```

Installation extracts the bundle to `/opt/awg-gui`, loads Docker images, and starts the panel.

For uninstall details see [uninstall.md](uninstall.md).

## Install from source (development)

Clone the repository and use root-level scripts — they **build images locally**:

```bash
git clone https://github.com/alt-plus-255/awg-gui.git
cd awg-gui
sudo ./awg-gui-install.sh
# or non-interactive defaults (panel port 8877, upgrade if already installed):
sudo ./awg-gui-install.sh --yes
```

The dev installer:

- downloads the **sing-box** tarball into `src/awg/vendor/` when missing (required for the AWG image);
- prompts for (unless `--yes`):
  - panel port (default **8877**);
  - AmneziaWG UDP port / `AWG_PORT` (default **51820**);
  - server endpoint (public IP/DNS);
  - internal subnet / `INTERNAL_SUBNET` (default **10.66.66.0/24**);
  - peer DNS / `PEER_DNS` (default **1.1.1.1**);
  - AllowedIPs / `ALLOWED_IPS` (default **0.0.0.0/0, ::/0**);
- copies `src/.env.example` → `src/.env` and fills values including random **`DB_PASSWORD`**, **`APP_KEY`**, and admin password.

### Re-install / upgrade

If `awggui-*` containers or `src/.env` with `DB_PASSWORD` already exist, the script asks:

1. **Abort** — recommended before a clean install; run [uninstall](uninstall.md) first;
2. **Upgrade** — keep `.env`, volumes, DB and AWG data; rebuild images and run migrations.

With `--yes`, **upgrade** mode is selected automatically.

At the end the installer prints management help and a credentials box (URL, port, `admin`, generated password).

### sing-box vendor (dev build only)

The source installer downloads the sing-box tarball automatically (version from `src/awg/Dockerfile`). Manual download — fallback:

```bash
mkdir -p src/awg/vendor
curl -fsSL -o src/awg/vendor/sing-box-1.12.12-linux-amd64.tar.gz \
  https://github.com/SagerNet/sing-box/releases/download/v1.12.12/sing-box-1.12.12-linux-amd64.tar.gz
```

For ARM replace `amd64` with `arm64` or `armv7`.
