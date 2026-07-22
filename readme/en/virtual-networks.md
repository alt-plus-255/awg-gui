# Virtual networks (VN)

**Languages:** [Русский](../ru/virtual-networks.md) | [English](virtual-networks.md) | [README](../../README.en.md)

Configs of type **Virtual network** (`virtual_network`) create an isolated LAN between VPN clients without routing internet traffic through the server.

## Access policies

When creating a VN you set **`vn_policy`**:

| Policy | Behavior |
|--------|----------|
| **Allow all** | Any peer in the config can reach any other peer |
| **Deny all (isolation)** | Peers are isolated by default; access is configured with zone rules |

## Access zones

In isolation mode the panel lets you create **zones** and rules for who can reach whom. Peers in the same zone (or linked by rules) can exchange traffic; others cannot.

## Peer exclusions

You can define **exclusions** — point-to-point allow/deny overrides between specific peer pairs on top of the global policy.

## Connection graph

The **Connections** view shows a graph of VN peer connectivity: who can talk to whom according to policy, zones, and exclusions.

## Client configs

VN peers get ready-made `.conf` / QR files with the virtual subnet. The resolver is **not** used for VN — see [resolver.md](resolver.md).

Create configs and attach peers on the [Configs & peers](configs-and-peers.md) page.
