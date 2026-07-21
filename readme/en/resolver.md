# Resolver

**Languages:** [Русский](../ru/resolver.md) | [English](resolver.md) | [README](../../README.en.md)

The **Resolver** page in AWG-GUI configures routing for **Server** configs. Virtual networks do **not** use the resolver.

## Overview

1. **sing-box** with FakeIP and rulesets runs inside the AWG container on the VDS.
2. Community lists ([allow-domains](https://github.com/itdoginfo/allow-domains)) are downloaded to disk (`rulesets/*.srs`) — **List settings** page.
3. Each server config picks a **Connection** — internet exit point (VLESS, VMess, subscription, etc.).
4. With the resolver enabled, the client `.conf` gets `DNS = gateway` and `AllowedIPs` for the selected mode.

## Two routing modes

### 1. Full tunnel on VDS (`vds_split`) — default

**In the panel:** “Full tunnel on VDS (AllowedIPs 0.0.0.0/0)”.

**Client `.conf`:**
```
DNS = gateway
AllowedIPs = 0.0.0.0/0, ::/0
```

**Behavior:**

| Traffic | Route |
|---------|-------|
| All client traffic | Via AmneziaWG to VDS |
| Listed domains (FakeIP) | sing-box → selected **Connection** |
| Sites outside lists, Speedtest, 2ip.ru | From **VDS server IP** |
| IP-CIDR from community lists | Proxied on VDS (full support) |

**When to use:** full VPN via VDS, but blocked resources (Telegram, YouTube…) exit through a separate upstream connection instead of the VDS IP.

### 2. Split-tunnel (`client_split`) — test mode

> **Split-tunnel is currently in test mode.** It works and is available in the panel, but behavior and limits may still change. For stable production use, prefer **full tunnel on VDS**.

**In the panel:** “Split-tunnel (lists only via VDS)”.

**Client `.conf`:**
```
DNS = gateway
AllowedIPs = 198.18.0.0/15, <gateway>/32
```
Plus custom subnets from “Custom subnets” if added.

**Behavior:**

| Traffic | Route |
|---------|-------|
| Listed domains (FakeIP) + DNS | Through tunnel → **Connection** |
| All other traffic | **Direct from client** (SIM/Wi‑Fi) |
| 2ip.ru, Speedtest | **Client** IP, not VDS |
| IP-CIDR from community lists | **Not** proxied (direct IP without DNS → FakeIP does not apply) |

**When to use:** only listed resources need VPN; the rest of the internet stays off-tunnel with carrier/Wi‑Fi IP.

**Split limitation:** for full IP-CIDR support from community lists, use full tunnel on VDS.

## Lists and connections

- **Community lists** — YouTube, Meta, Telegram, Discord, etc.; sync in **List settings** (default interval 6 h).
- **Custom domains and subnets** — on the config card on the Resolver page.
- **Connections** — separate page; resolver is not applied without a selected connection.
- **Block QUIC** — forces TCP for FakeIP domains (like YouTube QUIC reject, but for all selected lists).

## AmneziaWG: re-import config

**iPhone and Android:** after **enabling**, **disabling**, or **changing** the resolver mode:

1. Delete the server in AmneziaWG.
2. Re-import QR or `.conf` from the panel.

Without re-import, old `AllowedIPs` remain — lists (Telegram, YouTube, Meta…) will not work.

**Phone check:**

| Mode | 2ip.ru | Listed domain |
|------|--------|---------------|
| Full tunnel | VDS IP | Via VPN connection |
| Split-tunnel | Client IP | Via VPN connection |

## Diagnostics and common issues

- **iPhone:** disable iCloud Private Relay while testing.
- **Android:** disable Private DNS / DoH; if Telegram fails, clear the app cache.
- Do not test “full VPN” with Speedtest — open a site from the lists.
- The **Diagnostics** button checks sing-box, `.srs` files on disk, and DNS → FakeIP for each enabled list.

## sing-box in the AWG image

The resolver uses [sing-box](https://github.com/SagerNet/sing-box) inside the AWG container. Production builds include sing-box in the image; dev builds download the tarball via the installer — see [install.md](install.md#sing-box-vendor-dev-build-only).

License and branding details for sing-box — in [README](../../README.en.md#sing-box-and-branding) and [NOTICE.md](../../NOTICE.md).
