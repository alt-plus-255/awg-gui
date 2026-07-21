# Uninstall

**Languages:** [Русский](../ru/uninstall.md) | [English](uninstall.md) | [README](../../README.en.md)

## Production

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash
```

Piped `curl | bash` has no TTY, so confirmation is skipped and uninstall runs immediately.

Also remove local awggui Docker images:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --images
```

Remove the install directory `/opt/awg-gui` as well:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --images --purge
```

## Development (from source)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --images   # also remove local images
```

Stops/removes `awggui` containers and volumes, disables systemd `awg-gui.service`, removes `/usr/local/bin/awg-gui` and `/etc/awg-gui`. Paths are read from `/etc/awg-gui/awg-gui.conf` when present.

Does **not** remove Docker Engine, repository files, or `src/.env`.
