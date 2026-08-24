# Telegram bot

**Languages:** [Русский](../ru/telegram.md) | [English](telegram.md) | [README](../../README.en.md)

Admin Telegram bot for remote control of the AWG-GUI panel: configs, peers, resolver connections, resolver on server configs, and peer online/offline alerts.

Only one user may operate the bot — the numeric **Admin Telegram ID**. Everyone else gets “Access denied” on `/start`; other messages from non-admins are ignored.

## Quick start

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy the token.
2. Get your numeric Telegram user id (e.g. via [@userinfobot](https://t.me/userinfobot)).
3. In the panel open **Settings → Telegram**.
4. Set the token, Admin ID, bot language (`en` / `ru`), and transport mode.
5. Optionally enable peer notifications and (for long polling) a proxy pool.
6. Save settings and click **Test bot**.
7. In Telegram send `/start` to the bot from the Admin ID account.

Inside the `awggui-app` Docker container, `php artisan telegram:bot` starts automatically. The scheduler runs `telegram:notify-peers` every minute.

## Transport modes

| Mode | When to use | How it works |
|------|-------------|--------------|
| **Long polling** (default) | No inbound HTTPS from Telegram to the panel (e.g. restricted VPS) | The container long-polls `getUpdates`; you can configure SOCKS5/HTTP proxies or resolver connections |
| **Webhook** | Telegram can reach the panel over HTTPS | Telegram POSTs updates to the panel URL; the proxy pool is **not** used |

Webhook URL (secret is generated automatically on save):

```text
{panel_url}/api/telegram/webhook/{secret}
```

Requests are also checked against the `X-Telegram-Bot-Api-Secret-Token` header. A mismatch returns `404`. The secret and bot token are masked in the panel API and never exposed to the browser.

After changing mode or token, save settings so the backend can call `setWebhook` / `deleteWebhook`.

## What the bot can do

Navigation uses inline keyboards. The only slash command is **`/start`** (clears any wizard and shows the home menu).

### Main menu

| Section | Capabilities |
|---------|----------------|
| **Configs** | List, create (server / virtual network), enable/disable, edit name/port/DNS, delete (cannot delete the last config), peers |
| **Peers** | Create, enable/disable, delete, send VPN URI |
| **Connections** | Create from a share URL (`vless://`, `ss://`, `trojan://`, …), enable/disable, delete |
| **Resolver** | Server configs only: on/off, community lists, pick a connection |
| **Notifications** | Toggle peer online/offline alerts |
| **Refresh** | Sync peer stats from Docker and redraw the dashboard |

The home screen shows AWG status and endpoint, peer counts, CPU/RAM/disk, and online peers.

## Peer notifications

When notifications are enabled (in settings or in the bot), every minute the current peer online flags are compared to the previous snapshot. On online ↔ offline transitions the admin receives a chat message.

The first run only builds a baseline — there is no flood of messages on startup.

## Proxy pool (long polling only)

Use when `api.telegram.org` is not reachable directly from the server.

You can combine:

1. **URL proxies** — `socks5://`, `socks5h://`, `http://`, `https://` (the UI probes before adding).
2. **Resolver connections** — sing-box exposes a local authenticated mixed inbound (`:18088`); the bot reaches Telegram through the selected outbounds.

Selection strategy in settings: prefer lower latency or first available. The UI also has **Probe proxies**.

## Panel settings

| Field | Description |
|-------|-------------|
| Bot token | From @BotFather; empty/masked value on save keeps the current token |
| Admin Telegram ID | Numeric user id of the only operator |
| Bot language | Messages and buttons: English / Russian |
| Transport mode | Long polling or Webhook |
| Peer notifications | Online/offline alerts to the admin chat |
| Proxy pool | Polling mode only |

## Artisan commands

| Command | Purpose |
|---------|---------|
| `php artisan telegram:bot` | Long-polling worker (started from the Docker entrypoint) |
| `php artisan telegram:notify-peers` | One online/offline check (scheduled every minute) |

If the bot is not configured or webhook mode is selected, `telegram:bot` idles.

## Security

- Only the Admin Telegram ID can manage the panel via the bot.
- Webhook is gated by a path secret and the Telegram secret-token header.
- Token, webhook secret, and proxy URLs are masked in API responses.
- The webhook route is public (no panel session) but throttled and secret-checked.

## Related docs

- [Resolver](resolver.md) — connections you can reuse as bot proxies
- [Configs & peers](configs-and-peers.md)
- [Failure webhook](webhook.md) — separate panel HTTP webhook for Docker failures (not Telegram)
