# Failure webhook

**Languages:** [Русский](../ru/webhook.md) | [English](webhook.md) | [README](../../README.en.md)

In **Settings** set **Failure notification URL**. On boot failure (Docker down / compose fail / unhealthy services) the CLI POSTs JSON via `curl` to that URL. The URL is mirrored to `/etc/awg-gui/webhook.conf` so notifications work even when Laravel is down.

## JSON schema `1.0`

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
