# Webhook сбоев

**Языки:** [Русский](webhook.md) | [English](../en/webhook.md) | [README](../../README.md)

В **Settings** задайте **Оповещение о сбое (ссылка на эндпоинт)**. При сбое загрузки (Docker недоступен / compose fail / unhealthy services) CLI отправляет JSON через `curl` на этот URL. URL дублируется в `/etc/awg-gui/webhook.conf`, чтобы уведомления работали даже когда Laravel недоступен.

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

Стабильные значения `code`: `docker_unavailable`, `compose_up_failed`, `service_unhealthy`, `awg_gui.test`.

Контракт: `POST`, `Content-Type: application/json`.
