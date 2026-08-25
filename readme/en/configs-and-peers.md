# Configs & peers

**Languages:** [Русский](../ru/configs-and-peers.md) | [English](configs-and-peers.md) | [README](../../README.en.md)

The **Configs & peers** page is the main AmneziaWG management UI: interfaces, keys, AllowedIPs, and client assignment.

## Multiple AWG configs

You can create up to **20** AmneziaWG configs. Each config gets:

- its own interface (`awg0`, `awg1`, …);
- a dedicated internal subnet;
- a UDP port from **51820–51839**;
- its own AmneziaWG **protocol version** (**1.0**, **1.5**, **2.0**, or **3.1**) — different configs may use different versions.

Config types:

| Type | Purpose |
|------|---------|
| **Server** | Classic VPN: clients reach the internet via the server (or via the [resolver](resolver.md)) |
| **Virtual network** | Isolated LAN between peers — see [virtual-networks.md](virtual-networks.md) |

Typical deployment layouts (simple VPN, cascade, VN routers) — [use cases](use-cases.md).

When creating a config the panel automatically allocates a free `iface` and UDP port.

## AmneziaWG protocol versions

Choose the protocol version when **creating** or **editing** a config. Default is the latest (**3.1**). On one panel you can run several configs with **different** versions at once. Versions are **not compatible** with each other: the client and that specific config must use the same one.

Changing the version in the edit form asks for confirmation: obfuscation parameters and CPS (`I1`–`I5`) are **regenerated**. After save, all peers must re-download `.conf` / QR / `vpn://`.

| Version | Obfuscation parameters in `.conf` / `vpn://` |
|---------|-----------------------------------------------|
| **1.0** | `Jc`, `Jmin`, `Jmax`, `S1`, `S2`, `H1`–`H4` |
| **1.5** | same as 1.0 + `I1`–`I5` |
| **2.0** | same as 1.5 + `S3`, `S4` |
| **3.1** | same UI parameter set as **2.0** (until upstream publishes a separate schema) |

The obfuscation form on the config page shows only fields for the selected version. Exports (**`.conf`**, **QR**, **`vpn://`**) follow that profile (outer `vpn://` `protocol_version`: `1` for 1.0/1.5, `2` for 2.0/3.1).

### CPS / I1–I5 (Signature Chain)

For **1.5+**, the panel can **auto-generate** `I1`–`I5` masking packets in CPS format (`<b …>`, `<t>`, `<r N>`, `<rc N>`, `<rd N>`):

- templates: **QUIC** (default), DNS, STUN, SIP, DTLS, RTP, **random**;
- “Generate” (obfuscation + CPS) and “Generate CPS” buttons;
- syntax and size checks (MTU, collisions with handshake sizes `148+S1` / `92+S2` / …);
- empty `I*` fields are omitted from `.conf` and not sent by the client.

Manual CPS input is validated before save.

On upgrade, configs without a version get the latest available. For 1.x / 2.0, pick the version in the form or create a separate config and re-share QR / `.conf` / `vpn://`.

## Peers (clients)

A **peer** (`vpn_client`) is a separate entity with keys and a name. A peer is **not** permanently tied to one config:

- **Attach** — add the peer to a chosen config; client configuration is generated;
- **Detach** — remove the peer from a config; the peer record stays in the panel;
- **Rebind** — attach the peer to another config (different subnet, different type).

Unattached peers are shown separately and can be linked to any config later.

## AllowedIPs for Server configs

On a peer, **“Client routes via VPN”** (`extra_allowed_ips`) lists CIDRs the client sends into the tunnel (resources behind or near the server).

| Situation | Server `.conf` `[Peer]` | Client `.conf` / QR |
|-----------|-------------------------|---------------------|
| No CIDRs | peer `Address` only | Config `client_allowed_ips` (usually `0.0.0.0/0, ::/0`) |
| CIDRs set, **resolver off** | peer `Address` **only** (no extras) | **Tunnel subnet** (`internal_subnet`) + those CIDRs |
| **Resolver on** | peer `Address` only | always `0.0.0.0/0, ::/0` |

Extras are **not** written to the server Peer AllowedIPs: otherwise WireGuard installs `CIDR → peer` (cryptokey loop) and packets never leave toward the LAN/network behind the server.

Example: subnet `10.66.66.0/24`, peer CIDR `192.168.10.5/32`:

- client: `AllowedIPs = 10.66.66.0/24, 192.168.10.5/32`, `DNS = 1.1.1.1` — general internet off-VPN, that host via the tunnel;
- server: `AllowedIPs = 10.66.66.2/32` — without the client CIDRs.

The target must be reachable from the `awggui-awg` container (forward + MASQUERADE on egress). Do not put non-canonical `x.x.x.1/24` in AllowedIPs — Android rejects it (Error 1000).

`0.0.0.0/0` and `::/0` cannot be set as peer CIDRs.

**Unlike VN:** in a virtual network, `extra_allowed_ips` is the LAN **behind that peer** and *does* go into the server Peer AllowedIPs — see [virtual-networks.md](virtual-networks.md).

After changing peer CIDRs: the server conf reapplies on save; re-import client `.conf` / QR only if client AllowedIPs changed.

## Export configuration

For each attached peer you can:

- download a **`.conf`** file (AmneziaWG / WireGuard);
- show a **QR code** for mobile import;
- copy a **`vpn://`** key to paste into Amnezia / AmneziaWG.

After changing endpoint, UDP port, obfuscation, or resolver settings, re-export or re-import configs on devices.

## Panel settings

In **Settings** you can edit panel endpoint, port, and the [failure webhook](webhook.md).

VPN endpoint settings (public IP/DNS, AWG UDP port) are also available via the [CLI](cli.md#public-vpn-endpoint).
