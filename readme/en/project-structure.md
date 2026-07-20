# Project structure

**Languages:** [Русский](../ru/project-structure.md) | [English](project-structure.md) | [README](../../README.en.md)

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
  ru/                 # detailed documentation (RU)
  en/                 # detailed documentation (EN)
dist/
  install.sh          # production online installer (wget one-liner)
  uninstall.sh        # production online uninstaller
  README.md           # prod bundle notes
src/
  docker-compose.yml
  .env.example
  bin/awg-gui
  systemd/awg-gui.service
  scripts/
    build-dist.sh
    release/          # prod bundle templates
  caddy/
  awg/
  backend/     # Laravel 12
  frontend/    # Quasar (Vite)
```

## Docker Compose

Compose project name: **`awggui`**.

| Container | Role |
|-----------|------|
| `awggui-caddy` | Reverse proxy, TLS, frontend static assets |
| `awggui-app` | Laravel API |
| `awggui-db` | MariaDB |
| `awggui-awg` | AmneziaWG + sing-box (resolver) |

## Related docs

- [Install](install.md) · [Uninstall](uninstall.md) · [Build release](build-release.md)
- [CLI](cli.md) · [Webhook](webhook.md)
- [Configs & peers](configs-and-peers.md) · [Virtual networks](virtual-networks.md) · [Resolver](resolver.md)
