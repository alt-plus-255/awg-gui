# Структура проекта

**Языки:** [Русский](project-structure.md) | [English](../en/project-structure.md) | [README](../../README.md)

```
awg-gui-install.sh
awg-gui-uninstall.sh
build.sh
VERSION
LICENSE
NOTICE.md
README.md
README.en.md
readme/
  ru/                 # подробная документация (RU)
  en/                 # detailed documentation (EN)
dist/
  install.sh          # production online installer (wget one-liner)
  uninstall.sh        # production online uninstaller
  README.md           # заметка про prod-bundle
src/
  docker-compose.yml
  .env.example
  bin/awg-gui
  systemd/awg-gui.service
  scripts/
    build-dist.sh
    host/             # awg-kernel-host.sh (helper kernel-модуля на хосте)
    release/          # prod bundle templates
  panel-ops/          # привилегированные ops (update, awg-kernel)
  caddy/
  awg/
  backend/     # Laravel 12
  frontend/    # Quasar (Vite)
```

## Docker Compose

Имя compose-проекта: **`awggui`**.

| Контейнер | Назначение |
|-----------|------------|
| `awggui-caddy` | Reverse proxy, TLS, статика frontend |
| `awggui-app` | Laravel API |
| `awggui-db` | MariaDB |
| `awggui-awg` | AmneziaWG + sing-box (резолвер) |
| `awggui-panel-ops` | Операции на хосте: обновление панели, установка/удаление kernel-модуля AmneziaWG |
| `awggui-docker-proxy` | Ограниченный Docker socket proxy для приложения |

## Связанные разделы

- [Установка](install.md) · [Удаление](uninstall.md) · [Сборка release](build-release.md)
- [CLI](cli.md) · [Webhook](webhook.md) · [Telegram-бот](telegram.md)
- [Конфиги и пиры](configs-and-peers.md) · [Виртуальные сети](virtual-networks.md) · [Резолвер](resolver.md)
