# Uninstall

**Languages:** [Русский](../ru/uninstall.md) | [English](uninstall.md) | [README](../../README.en.md)

## Production

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash
```

Piped `curl | bash` has no TTY, so confirmation is skipped and uninstall runs immediately.

By default this also removes local `awggui-*` Docker images, dangling layers, and the Docker build cache (often the largest leftover after image builds).

Remove the install directory `/opt/awg-gui` as well:

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --purge
```

Keep images and build cache (usually not needed):

```bash
curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash -s -- --keep-images
```

## Development (from source)

```bash
sudo ./awg-gui-uninstall.sh
sudo ./awg-gui-uninstall.sh --yes --keep-images   # leave images/cache alone
```

Stops/removes `awggui` containers and volumes, project images, dangling layers and build cache, **project logs** (Docker container json logs, `/etc/awg-gui/update.log`, `awg-kernel` helper/state/logs, `awg-gui` journal, tmp extract dirs), disables systemd `awg-gui.service`, removes `/usr/local/bin/awg-gui`, `/etc/awg-gui` (Caddyfile, certs, ACME, `awg-kernel-host.sh`), and `src/.env`. Paths are read from `/etc/awg-gui/awg-gui.conf` when present.

Does **not** remove Docker Engine, repository source files (production `--purge` also removes `/opt/awg-gui`), or the **AmneziaWG kernel module packages** on the host. Remove the module first in **Settings → Panel → AmneziaWG kernel module**, or uninstall the Amnezia packages manually if you no longer need them.
