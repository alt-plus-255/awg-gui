# AmneziaWG GUI (awggui)

**Languages / Языки:** [Русский](README.md) | [English](README.en.md)

AmneziaWG VPN server (protocol versions **1.0**, **1.5**, and **2.0**, default **2.0**) with a Laravel 12 API and Quasar Vue admin panel, all in Docker containers prefixed with `awggui`.

## Use cases

| Layout | Where the panel runs | What it does |
|--------|----------------------|--------------|
| **Server → client** | Foreign VDS | Classic VPN: all traffic exits via the server IP |
| **Server + resolver (RU access)** | Foreign VDS | Default exit = foreign IP; `russia_inside` → Connection (VPN/proxy in Russia) |
| **Cascade server → client** | Russia VDS | RU segment via provider IP; `russia_outside` and services → foreign hop |
| **Virtual network hub** | Often Russia VDS | N routers/clients in one LAN; each router has its own subnet |
| **Home / office via a router** | Any VDS | Router as a Server-config client — whole LAN behind VPN |
| **Several roles** | One VDS | Up to 20 configs: plain VPN, cascade, and VN at once |

→ [Details: use cases](readme/en/use-cases.md)

<p align="center">
  <img src="readme/assets/dashboard.png" alt="AWG-GUI dashboard: server resources, peers, and connection status" width="720">
  <br><br>
  <img src="readme/assets/connection-graph.png" alt="Virtual network connection graph: zones, peers, and traffic" width="720">
</p>

**License:** [GPL-3.0-or-later](LICENSE) · third-party components: [NOTICE.md](NOTICE.md)

## Quick install (production)

Downloads a pre-built release bundle from GitHub Releases. No source checkout, `node_modules`, or local image build required.

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash
```

Non-interactive (panel port **8877**, upgrade if already installed; installs **AmneziaWG kernel module** by default):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo bash -s -- --yes
```

Skip the kernel module (userspace `amneziawg-go`): `--no-awg-kernel` or `AWG_GUI_SKIP_KERNEL=1`. An already-installed module is not reinstalled. Manage later under **Settings → Panel**.

Specific version:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh | sudo AWG_GUI_VERSION=1.0.0 bash -s -- --yes
```

If `curl` is unavailable, download the script and run it:

```bash
wget --no-config -O /tmp/awg-gui-install.sh https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh
sudo bash /tmp/awg-gui-install.sh --yes
```

## Features

### Multiple AWG configs

Up to **20** AmneziaWG configs (UDP **51820–51839**): each with its own interface, subnet, port, and **protocol version** (**1.0** / **1.5** / **2.0** — different configs may use different versions). Types **Server** (internet VPN) and **Virtual network** (isolated LAN).

→ [Details: configs & peers](readme/en/configs-and-peers.md)

### AmneziaWG protocol versions

When creating a config, choose **1.0**, **1.5**, or **2.0** (default: latest). Versions are **not compatible** with each other; the version is **fixed** after create. Obfuscation parameters and exports (**`.conf`**, **QR**, **`vpn://`**) follow the selected profile.

→ [Details: configs & peers](readme/en/configs-and-peers.md#amneziawg-protocol-versions)

### Peers and rebind

A peer (`vpn_client`) is a separate entity. **Attach** to a config, **detach** (peer stays in the panel), **rebind** to another config. Export **`.conf`**, **QR**, and **`vpn://`** for clients.

For **Server** configs with the resolver off: if a peer has AllowedIPs CIDRs, the client `.conf` uses the tunnel subnet (`10.66.66.0/24`) + those CIDRs (split-tunnel) instead of full tunnel; general internet stays off-VPN. Enabling the resolver asks for confirmation and switches to `0.0.0.0/0`.

→ [Details: configs & peers](readme/en/configs-and-peers.md)

### Virtual LANs

Virtual network configs: isolated subnet, “allow all” / “isolation” policies, access zones, peer exclusions, **connection graph** with online status and peer-to-peer traffic.

→ [Details: virtual networks](readme/en/virtual-networks.md)

### Resolver

For **Server** configs (not virtual networks): route traffic by domain and subnet via sing-box — community lists ([allow-domains](https://github.com/itdoginfo/allow-domains)), custom domains and CIDR. Internet exit point is a **Connection**: VLESS / subscription, outbound JSON, or **WG/AWG** (remote AmneziaWG / WireGuard `.conf` with a matching protocol version).

Resolver on the **Resolver** page:

#### Full tunnel on VDS

`AllowedIPs = 0.0.0.0/0, ::/0` · `DNS = gateway`

| Traffic | Route |
|---------|-------|
| **All** client traffic | To VDS (AmneziaWG tunnel) |
| Listed domains (Telegram, YouTube, Meta…) | Via selected **Connection** (sing-box FakeIP → outbound) |
| Everything else (2ip.ru, Speedtest, sites outside lists) | From **VDS server IP** |
| IP-CIDR from community lists | **Fully** proxied |

Use when you want a classic “full VPN via server”, with blocked resources exiting through a separate upstream connection.

For ABR video (YouTube/Instagram) you need **kernel AmneziaWG** on the VDS host plus working QUIC (Block QUIC off + UDP-capable outbound) or a solid TCP path. Delivery: FakeIP/list TCP via **NAT REDIRECT** `:1602`, FakeIP UDP via **TPROXY** `:1603` (sing-box **1.13.x**).

**After enabling or disabling:** delete the server in AmneziaWG and **re-import** QR/`.conf` — lists will not work without re-import. If peers had custom AllowedIPs, enabling the resolver shows a dialog: full tunnel replaces split-tunnel.

→ [Details: resolver, diagnostics, re-import](readme/en/resolver.md)

### Telegram bot

Remote panel control from Telegram: configs, peers, connections and resolver, peer online/offline alerts. Only the configured Admin ID can operate the bot. Modes: **long polling** (with SOCKS/HTTP or resolver-connection proxy pool) and **webhook**.

→ [Details: Telegram bot](readme/en/telegram.md)

## Documentation

| Topic | Description |
|-------|-------------|
| [Install](readme/en/install.md) | Requirements, production and dev install, upgrade |
| [Uninstall](readme/en/uninstall.md) | Production and dev uninstall |
| [Build release](readme/en/build-release.md) | `./build.sh`, `.run`, GitHub Releases |
| [CLI](readme/en/cli.md) | `awg-gui`: info, endpoint, password, 2FA, systemd |
| [Webhook](readme/en/webhook.md) | Failure notification JSON schema |
| [Telegram bot](readme/en/telegram.md) | Setup, polling/webhook, menus, notifications, proxies |
| [Use cases](readme/en/use-cases.md) | Server→client, resolver/cascade, VN routers, home behind VPN |
| [Configs & peers](readme/en/configs-and-peers.md) | Multi-config, protocol versions, attach/detach, export |
| [Virtual networks](readme/en/virtual-networks.md) | VN, zones, exclusions |
| [Resolver](readme/en/resolver.md) | Full tunnel, lists, connections (incl. WG/AWG), diagnostics |
| [Project structure](readme/en/project-structure.md) | Directories, Docker containers |

Русский: [readme/ru/](readme/ru/)

## License

The **awg-gui** project (panel source, install scripts, Docker definitions) is licensed under the
**[GNU General Public License v3.0 or later](LICENSE)** (GPL-3.0-or-later).

Release bundles (`.run`) and Docker images include **third-party** software under
**other** licenses — including **GPL-2.0** (amneziawg-tools) and **GPL-3.0** (sing-box, MariaDB).
See **[NOTICE.md](NOTICE.md)** for versions and source links.

### sing-box and branding

The resolver uses [sing-box](https://github.com/SagerNet/sing-box) as a component inside the AWG
container. **awg-gui is not an official sing-box / SagerNet product.** sing-box includes an
additional term: derivative works must not use the sing-box name or imply association without
prior consent from the copyright holder. Details in [NOTICE.md](NOTICE.md).

When redistributing `.run` files or images, comply with GPL obligations: include license text,
`NOTICE.md`, and a way for recipients to obtain GPL source for bundled components (see NOTICE.md).
