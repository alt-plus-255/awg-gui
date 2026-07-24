# Configs & peers

**Languages:** [Русский](../ru/configs-and-peers.md) | [English](configs-and-peers.md) | [README](../../README.en.md)

The **Configs & peers** page is the main AmneziaWG management UI: interfaces, keys, AllowedIPs, and client assignment.

## Multiple AWG configs

You can create up to **20** AmneziaWG configs. Each config gets:

- its own interface (`awg0`, `awg1`, …);
- a dedicated internal subnet;
- a UDP port from **51820–51839**.

Config types:

| Type | Purpose |
|------|---------|
| **Server** | Classic VPN: clients reach the internet via the server (or via the [resolver](resolver.md)) |
| **Virtual network** | Isolated LAN between peers — see [virtual-networks.md](virtual-networks.md) |

When creating a config the panel automatically allocates a free `iface` and UDP port.

## AmneziaWG protocol versions

Choose the protocol version when **creating** a config. Default is the latest (**2.0**). Versions are **not compatible** with each other: client and server must use the same one. After create the version **cannot be changed** — create a new config for a different version.

| Version | Obfuscation parameters in `.conf` / `vpn://` |
|---------|-----------------------------------------------|
| **1.0** | `Jc`, `Jmin`, `Jmax`, `S1`, `S2`, `H1`–`H4` |
| **1.5** | same as 1.0 + `I1`–`I5` |
| **2.0** | same as 1.5 + `S3`, `S4` |

The obfuscation form on the config page shows only fields for the selected version. Exports (**`.conf`**, **QR**, **`vpn://`**) follow that profile (outer `vpn://` `protocol_version`: `1` for 1.0/1.5, `2` for 2.0).

On upgrade, existing configs receive **2.0**. If you need 1.x, create a separate config with that version and re-share QR / `.conf` / `vpn://` with clients.

## Peers (clients)

A **peer** (`vpn_client`) is a separate entity with keys and a name. A peer is **not** permanently tied to one config:

- **Attach** — add the peer to a chosen config; client configuration is generated;
- **Detach** — remove the peer from a config; the peer record stays in the panel;
- **Rebind** — attach the peer to another config (different subnet, different type).

Unattached peers are shown separately and can be linked to any config later.

## Export configuration

For each attached peer you can:

- download a **`.conf`** file (AmneziaWG / WireGuard);
- show a **QR code** for mobile import;
- copy a **`vpn://`** key to paste into Amnezia / AmneziaWG.

After changing endpoint, UDP port, obfuscation, or resolver settings, re-export or re-import configs on devices.

## Panel settings

In **Settings** you can edit panel endpoint, port, and the [failure webhook](webhook.md).

VPN endpoint settings (public IP/DNS, AWG UDP port) are also available via the [CLI](cli.md#public-vpn-endpoint).
