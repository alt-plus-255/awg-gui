# AmneziaWG GUI (awggui)

**Languages / Языки:** [Русский](README.md) | [English](README.en.md)

AmneziaWG 2.0 VPN server with a Laravel 12 API and Quasar Vue admin panel, all in Docker containers prefixed with `awggui`.

## Requirements

- Root / sudo on Linux
- KVM (or host with `/dev/net/tun`)
- Supported OS for auto Docker install: Ubuntu, Debian, Fedora, CentOS/RHEL/Rocky/Alma
- **Docker** and **curl** are installed automatically if missing ([Docker Engine docs](https://docs.docker.com/engine/install/))

## Install

### Quick install (production)

Downloads a pre-built release bundle from GitHub Releases. No source checkout, `node_modules`, or local image build required.

Replace `YOUR_ORG/awg-gui` with your repository:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh)
```

Non-interactive (panel port **8877**, upgrade if already installed):

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh) --yes
```

Specific version:

```bash
sudo AWG_GUI_VERSION=1.0.0 bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/install.sh) --yes
```

### Uninstall (production)

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh)
```

Also remove local awggui Docker images:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images
```

Remove the install directory `/opt/awg-gui` as well:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/YOUR_ORG/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images --purge
```

### Install from source (development)

Clone the repository and use root-level scripts — they **build images locally**:

```bash
git clone https://github.com/YOUR_ORG/awg-gui.git
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

1. **Abort** — recommended before a clean install; run uninstall first;
2. **Upgrade** — keep `.env`, volumes, DB and AWG data; rebuild images and run migrations.

With `--yes`, **upgrade** mode is selected automatically.

At the end the installer prints management help and a credentials box (URL, port, `admin`, generated password).

## Uninstall (development)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --images   # also remove local images
```

Stops/removes `awggui` containers and volumes, disables systemd `awg-gui.service`, removes `/usr/local/bin/awg-gui` and `/etc/awg-gui`. Paths are read from `/etc/awg-gui/awg-gui.conf` when present.

Does **not** remove Docker Engine, repository files, or `src/.env`.

## Build production release

From the repository root (requires Docker and curl):

```bash
./build.sh
# or explicitly:
./build.sh --version 1.0.0 --arch amd64
```

`build.sh`:

- builds Docker images from `src/`;
- exports them to `dist/awg-gui-VERSION-ARCH.run` (self-extracting bundle);
- writes `dist/awg-gui-VERSION-ARCH.run.sha256`.

Publish the `.run` and `.sha256` files to **GitHub Releases** for tag `vVERSION`. Commit `dist/install.sh` and `dist/uninstall.sh` to git — users invoke them via the `wget` one-liner above.

## Management CLI (`awg-gui`)

Installed to `/usr/local/bin/awg-gui`. Config: `/etc/awg-gui/awg-gui.conf`.

| Command | Description |
|---------|-------------|
| `awg-gui help` | Show help |
| `awg-gui status` | Show compose service status |
| `awg-gui ensure-up` | If containers are down, `compose up -d` |
| `awg-gui restart awg` | Restart AmneziaWG container |
| `awg-gui restart panel` | Restart Caddy + Laravel app |
| `awg-gui restart all` | Restart all project services |
| `awg-gui password` | Generate a random admin password and print it |
| `awg-gui password --random` | Same as above (explicit) |
| `awg-gui password --password=Secret` | Set admin password to the given value |
| `awg-gui 2fa status` | Show whether 2FA is enabled |
| `awg-gui 2fa disable` | Disable 2FA for admin (recovery) |
| `awg-gui endpoint` | Show public VPN endpoint (IP/DNS and AWG UDP port) |
| `awg-gui endpoint IP [PORT]` | Set public IP/hostname and optionally AWG UDP port |
| `awg-gui endpoint --auto` | Reset endpoint to auto-detect |
| `awg-gui endpoint PORT` | Change AWG UDP port only (51820–51839) |

### Public VPN endpoint

Client configs use `Endpoint = <public IP>:<UDP port>`. After install you can view or change it from the shell:

```bash
# Current values
sudo awg-gui endpoint

# Public IP or DNS only
sudo awg-gui endpoint 203.0.113.10
sudo awg-gui endpoint vpn.example.com

# IP/DNS and AWG UDP port (51820–51839)
sudo awg-gui endpoint 203.0.113.10 51821

# AWG UDP port only
sudo awg-gui endpoint 51821

# Auto-detect public endpoint
sudo awg-gui endpoint --auto
```

Changes are saved to the database, `src/.env` (`SERVER_ENDPOINT`, `AWG_PORT`) and `/etc/awg-gui/webhook.conf`. When the UDP port changes, AmneziaWG is restarted automatically. Re-export or re-import client `.conf` / QR codes after changing the endpoint so devices pick up the new `Endpoint` line.

### Admin password

After install the installer prints a one-time random password. To change it later:

```bash
# Random password (20 characters, printed to the terminal)
sudo awg-gui password
sudo awg-gui password --random

# Your own password
sudo awg-gui password --password='MyStr0ng!Pass'
```

The command updates the `admin` user in the Laravel database and prints the new value. Use single quotes if the password contains shell metacharacters (`$`, `` ` ``, `!`, `&`, etc.).

### Boot service

`awg-gui.service` runs `awg-gui ensure-up` after Docker on system boot.

```bash
systemctl status awg-gui
journalctl -u awg-gui -e
```

## Failure webhook

In **Settings** set **Оповещение о сбое (ссылка на эндпоинт)** (failure notification URL). On boot failure (Docker down / compose fail / unhealthy services) the CLI POSTs JSON via `curl` to that URL. The URL is mirrored to `/etc/awg-gui/webhook.conf` so notifications work even when Laravel is down.

### JSON schema `1.0`

```json
{
  "schema_version": "1.0",
  "event": "awg_gui.failure",
  "severity": "error",
  "source": "awg-gui",
  "project": "awggui",
  "hostname": "vpn.example.com",
  "timestamp": "2026-07-15T10:58:00Z",
  "code": "docker_unavailable",
  "message": "Docker daemon did not become ready within timeout",
  "panel_url": "http://203.0.113.10:8877",
  "details": {
    "attempt": 1,
    "services": ["caddy", "app", "db", "awg"],
    "stderr": "..."
  }
}
```

Stable `code` values: `docker_unavailable`, `compose_up_failed`, `service_unhealthy`, `awg_gui.test`.

Contract: `POST`, `Content-Type: application/json`.

## Layout

```
awg-gui-install.sh
awg-gui-uninstall.sh
build.sh
VERSION
README.md
README.en.md
dist/
  install.sh          # production online installer (wget one-liner)
  uninstall.sh        # production online uninstaller
src/
  docker-compose.yml
  .env.example
  bin/awg-gui
  systemd/awg-gui.service
  scripts/
    build-dist.sh
    release/          # prod bundle templates
  caddy/
  awg/
  backend/     # Laravel 12
  frontend/    # Quasar (Vite)
```

Compose project name: **`awggui`**. Containers: `awggui-caddy`, `awggui-app`, `awggui-db`, `awggui-awg`.

## Admin settings

In **Settings** you can edit panel endpoint, port and failure webhook.

AWG interfaces, peers, keys and AllowedIPs are managed on **Конфиги и пиры** (Configs & peers).

### Resolver (split-tunnel)

The **Резолвер** (Resolver) page enables domain-based split-tunnel for **Сервер** (Server) configs (not VN):

- community lists from [allow-domains](https://github.com/itdoginfo/allow-domains) are downloaded to disk (`rulesets/*.srs`) and loaded in sing-box as local rulesets;
- custom domains and subnets;
- on the server — sing-box FakeIP; in the client `.conf` — `DNS = gateway` and `AllowedIPs = <subnet>, 198.18.0.0/15`;
- other traffic (Speedtest, sites outside the lists) goes via SIM/Wi‑Fi — this is expected.

**AmneziaWG (iPhone and Android):** after enabling or changing the resolver **delete the server and re-import** QR/`.conf`. The config must include `DNS = gateway` and `AllowedIPs` with `198.18.0.0/15`. Without re-import, lists (Telegram, YouTube, Meta…) will not work.

On iPhone disable iCloud Private Relay while testing. On Android disable Private DNS / DoH; if Telegram fails, clear the app cache. Do not test “full VPN” with Speedtest — open a site from the lists. The **Диагностика** (Diagnostics) button checks sing-box, `.srs` files on disk, and DNS → FakeIP for each enabled list.

### sing-box vendor

The installer downloads the sing-box tarball automatically (version from `src/awg/Dockerfile`). Manual download — fallback:

```bash
mkdir -p src/awg/vendor
curl -fsSL -o src/awg/vendor/sing-box-1.12.12-linux-amd64.tar.gz \
  https://github.com/SagerNet/sing-box/releases/download/v1.12.12/sing-box-1.12.12-linux-amd64.tar.gz
```

For ARM replace `amd64` with `arm64` or `armv7`.
