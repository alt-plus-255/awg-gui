# Удаление

**Языки:** [Русский](uninstall.md) | [English](../en/uninstall.md) | [README](../../README.md)

## Production

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash
```

Через `curl | bash` подтверждение не спрашивается (нет TTY) — удаление выполняется сразу.

По умолчанию также удаляются локальные Docker-образы `awggui-*`, dangling-слои и Docker build cache (часто самый жирный остаток после сборок).

Удалить и каталог установки `/opt/awg-gui`:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --purge
```

Оставить образы и build cache (обычно не нужно):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --keep-images
```

## Разработка (из исходников)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --keep-images   # не трогать образы/cache
```

Останавливает и удаляет контейнеры и volumes `awggui`, образы проекта, dangling-слои и build cache, **логи проекта** (Docker json-логи контейнеров, `/etc/awg-gui/update.log`, journal `awg-gui`, tmp extract), отключает systemd `awg-gui.service`, удаляет `/usr/local/bin/awg-gui`, `/etc/awg-gui` (Caddyfile, сертификаты, ACME) и `src/.env`. Пути берутся из `/etc/awg-gui/awg-gui.conf`, если файл существует.

**Не удаляет:** Docker Engine и исходники репозитория (для production `--purge` убирает `/opt/awg-gui`).
