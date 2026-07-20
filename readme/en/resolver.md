# Resolver (split-tunnel)

**Languages:** [Русский](../ru/resolver.md) | [English](resolver.md) | [README](../../README.en.md)

The **Resolver** page enables domain-based split-tunnel for **Server** configs (not virtual networks).

## How it works

- community lists from [allow-domains](https://github.com/itdoginfo/allow-domains) are downloaded to disk (`rulesets/*.srs`) and loaded in sing-box as local rulesets;
- you can add custom domains and subnets;
- on the server — sing-box FakeIP; in the client `.conf` — `DNS = gateway` and `AllowedIPs = <subnet>, 198.18.0.0/15`;
- other traffic (Speedtest, sites outside the lists) goes via SIM/Wi‑Fi — this is expected.

## AmneziaWG: re-import config

**iPhone and Android:** after enabling or changing the resolver **delete the server and re-import** QR/`.conf`. The config must include:

```
DNS = gateway
AllowedIPs = <subnet>, 198.18.0.0/15
```

Without re-import, lists (Telegram, YouTube, Meta…) will not work.

## Diagnostics and common issues

- **iPhone:** disable iCloud Private Relay while testing.
- **Android:** disable Private DNS / DoH; if Telegram fails, clear the app cache.
- Do not test “full VPN” with Speedtest — open a site from the lists.
- The **Diagnostics** button checks sing-box, `.srs` files on disk, and DNS → FakeIP for each enabled list.

## sing-box in the AWG image

The resolver uses [sing-box](https://github.com/SagerNet/sing-box) inside the AWG container. Production builds include sing-box in the image; dev builds download the tarball via the installer — see [install.md](install.md#sing-box-vendor-dev-build-only).

License and branding details for sing-box — in [README](../../README.en.md#sing-box-and-branding) and [NOTICE.md](../../NOTICE.md).
