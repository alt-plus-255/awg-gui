# AmneziaWG GUI (awggui)

**Languages / Языки:** [Русский](README.md) | [English](README.en.md)

AmneziaWG 2.0 VPN server with a Laravel 12 API and Quasar Vue admin panel, all in Docker containers prefixed with `awggui`.

**License:** [GPL-3.0-or-later](LICENSE) · third-party components: [NOTICE.md](NOTICE.md)

## Quick install (production)

Downloads a pre-built release bundle from GitHub Releases. No source checkout, `node_modules`, or local image build required.

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

## Features

### Multiple AWG configs

Up to **20** AmneziaWG configs (UDP **51820–51839**): each with its own interface, subnet, and port. Types **Server** (internet VPN) and **Virtual network** (isolated LAN).

→ [Details: configs & peers](readme/en/configs-and-peers.md)

### Peers and rebind

A peer (`vpn_client`) is a separate entity. **Attach** to a config, **detach** (peer stays in the panel), **rebind** to another config. Export **`.conf`** and **QR** for clients.

→ [Details: configs & peers](readme/en/configs-and-peers.md)

### Virtual LANs

Virtual network configs: isolated subnet, “allow all” / “isolation” policies, access zones, peer exclusions, connection graph.

→ [Details: virtual networks](readme/en/virtual-networks.md)

### Resolver (split-tunnel)

For **Server** configs: route selected domains and subnets via sing-box (community lists, custom rules). Other traffic stays on SIM/Wi‑Fi.

→ [Details: resolver](readme/en/resolver.md)

## Documentation

| Topic | Description |
|-------|-------------|
| [Install](readme/en/install.md) | Requirements, production and dev install, upgrade |
| [Uninstall](readme/en/uninstall.md) | Production and dev uninstall |
| [Build release](readme/en/build-release.md) | `./build.sh`, `.run`, GitHub Releases |
| [CLI](readme/en/cli.md) | `awg-gui`: endpoint, password, 2FA, systemd |
| [Webhook](readme/en/webhook.md) | Failure notification JSON schema |
| [Configs & peers](readme/en/configs-and-peers.md) | Multi-config, attach/detach, export |
| [Virtual networks](readme/en/virtual-networks.md) | VN, zones, exclusions |
| [Resolver](readme/en/resolver.md) | Split-tunnel, diagnostics, re-import |
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
