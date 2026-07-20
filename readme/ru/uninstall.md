# Удаление

**Языки:** [Русский](uninstall.md) | [English](../en/uninstall.md) | [README](../../README.md)

## Production

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh)
```

Также удалить локальные Docker-образы awggui:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images
```

Удалить и каталог установки `/opt/awg-gui`:

```bash
sudo bash <(wget -O - https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh) --yes --images --purge
```

## Разработка (из исходников)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --images   # также удалить локальные образы
```

Останавливает и удаляет контейнеры и volumes `awggui`, отключает systemd `awg-gui.service`, удаляет `/usr/local/bin/awg-gui` и `/etc/awg-gui`. Пути берутся из `/etc/awg-gui/awg-gui.conf`, если файл существует.

**Не удаляет:** Docker Engine, файлы репозитория и `src/.env`.
