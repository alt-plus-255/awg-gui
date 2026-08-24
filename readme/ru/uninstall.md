# Удаление

**Языки:** [Русский](uninstall.md) | [English](../en/uninstall.md) | [README](../../README.md)

## Production

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash
```

В интерактивном режиме сначала спрашивается **язык** (по умолчанию русский). Через `curl | bash` без TTY подтверждение не спрашивается — удаление выполняется сразу (язык **ru**, либо `--lang=en` / `AWG_GUI_LANG=en`).

```bash
curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes --lang=en
```

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
sudo ./awg-gui-uninstall.sh --yes --lang=en       # статусы на английском
```

Останавливает и удаляет контейнеры и volumes `awggui`, образы проекта, dangling-слои и build cache, **логи проекта** (Docker json-логи контейнеров, `/etc/awg-gui/update.log`, helper/state/логи `awg-kernel`, journal `awg-gui`, tmp extract), отключает systemd `awg-gui.service`, удаляет `/usr/local/bin/awg-gui`, `/etc/awg-gui` (Caddyfile, сертификаты, ACME, `awg-kernel-host.sh`) и `src/.env`. Пути берутся из `/etc/awg-gui/awg-gui.conf`, если файл существует.

**Не удаляет:** Docker Engine, исходники репозитория (для production `--purge` убирает `/opt/awg-gui`) и **пакеты kernel-модуля AmneziaWG** на хосте. Сначала удалите модуль в **Настройки → Панель → Kernel-модуль AmneziaWG** или снимите пакеты Amnezia вручную, если они больше не нужны.
